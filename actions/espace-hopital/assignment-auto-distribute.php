<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-student-notifications.php';

requirePermission($pdo,'assignment.hosting.manage');
verifyAjaxCsrf();

$eid=currentEtablissementId($pdo);
$actor=(int)($_SESSION['user_id']??0)?:null;
$observation=trim((string)($_POST['observation']??''));

$ids=json_decode((string)($_POST['admission_ids']??'[]'),true);
if(!is_array($ids))$ids=[];

$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));

if(!$eid)
    jsonResponse(false,'Aucun établissement associé.',[],403);

if(!$ids)
    jsonResponse(false,'Aucun stagiaire sélectionné.',[],422);

if(count($ids)>2000)
    jsonResponse(false,'Maximum 2000 stagiaires par répartition automatique.',[],422);

function autoAssignmentUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

function overlaps(string $aStart,string $aEnd,string $bStart,string $bEnd):bool{
    return $aStart<=$bEnd && $aEnd>=$bStart;
}

try{
    set_time_limit(120);
    $pdo->beginTransaction();

    /* Services/unités utilisables : capacité explicite obligatoire. */
    $s=$pdo->prepare("
        SELECT id,nom,type,capacite,parent_id
        FROM host_units
        WHERE host_etablissement_id=?
          AND actif=1
          AND UPPER(type)='SERVICE'
          AND parent_id IS NOT NULL
          AND capacite IS NOT NULL
          AND capacite>0
        ORDER BY id
        FOR UPDATE
    ");
    $s->execute([$eid]);
    $units=$s->fetchAll(PDO::FETCH_ASSOC);

    if(!$units)
        throw new RuntimeException(
            'Aucun service ou unité actif ne possède une capacité configurée.'
        );

    $unitMap=[];
    foreach($units as $u){
        $u['id']=(int)$u['id'];
        $u['capacite']=(int)$u['capacite'];
        $unitMap[$u['id']]=$u;
    }

    /*
     * Affectations déjà présentes.
     * Elles servent au calcul de capacité sur les périodes qui se chevauchent.
     */
    $s=$pdo->prepare("
        SELECT host_unit_id,date_debut,COALESCE(date_fin,'9999-12-31') date_fin
        FROM stage_assignments
        WHERE host_etablissement_id=?
          AND statut IN('PLANIFIEE','ACTIVE')
    ");
    $s->execute([$eid]);

    $intervals=[];
    foreach($units as $u)$intervals[$u['id']]=[];

    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
        $uid=(int)$row['host_unit_id'];
        if(isset($intervals[$uid])){
            $intervals[$uid][]=[
                'start'=>$row['date_debut'],
                'end'=>$row['date_fin']
            ];
        }
    }

    $ph=implode(',',array_fill(0,count($ids),'?'));

    $sql="
        SELECT
            ad.id admission_id,
            ad.statut admission_status,
            ad.coordination_unit_id,
            sr.statut reservation_status,
            app.statut application_status,
            pl.statut placement_status,
            c.date_debut campaign_start,
            c.date_fin campaign_end,
            sp.nom,sp.postnom,sp.prenom
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN stage_placements pl ON pl.id=ad.placement_id
            AND pl.reservation_id=sr.id
            AND pl.host_etablissement_id=ad.host_etablissement_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        WHERE ad.host_etablissement_id=?
          AND app.host_etablissement_id=?
          AND ad.id IN($ph)
        ORDER BY c.date_debut,ad.id
        FOR UPDATE
    ";

    $params=array_merge([$eid,$eid],$ids);
    $s=$pdo->prepare($sql);
    $s->execute($params);
    $admissions=$s->fetchAll(PDO::FETCH_ASSOC);

    if(!$admissions)
        throw new RuntimeException('Aucune admission valide dans la sélection.');

    $assigned=0;
    $failed=[];
    $distribution=[];
    $assignmentNotifications=[];

    $existingStmt=$pdo->prepare("
        SELECT id
        FROM stage_assignments
        WHERE admission_id=?
          AND statut IN('PLANIFIEE','ACTIVE')
        LIMIT 1
    ");

    $insertAssignment=$pdo->prepare("
        INSERT INTO stage_assignments(
            uuid,admission_id,host_unit_id,host_etablissement_id,
            statut,date_debut,date_fin,observation,
            assigned_by,assigned_at
        )
        VALUES(?,?,?,?,'ACTIVE',?,?,?,?,NOW())
    ");

    $updateAdmission=$pdo->prepare("
        UPDATE stage_admissions
        SET statut='EN_COURS'
        WHERE id=?
          AND host_etablissement_id=?
    ");

    $assignmentHistory=$pdo->prepare("
        INSERT INTO stage_assignment_history(
            assignment_id,event_code,previous_status,new_status,
            details,actor_user_id,created_at
        )
        VALUES(?,'ASSIGNMENT_CREATED',NULL,'ACTIVE',?,?,NOW())
    ");

    $admissionHistory=$pdo->prepare("
        INSERT INTO stage_admission_history(
            admission_id,event_code,previous_status,new_status,
            details,actor_user_id,created_at
        )
        VALUES(?,'FIRST_ASSIGNMENT',?,'EN_COURS',?,?,NOW())
    ");

    $today=date('Y-m-d');

    foreach($admissions as $a){
        $admissionId=(int)$a['admission_id'];
        $label=trim(implode(' ',array_filter([
            $a['prenom']??'',
            $a['nom']??'',
            $a['postnom']??''
        ]))) ?: 'Stagiaire #'.$admissionId;

        if($a['reservation_status']!=='CONFIRMEE'){
            $failed[]=['admission_id'=>$admissionId,'student'=>$label,'reason'=>'Réservation non confirmée'];
            continue;
        }

        if($a['application_status']!=='ACCEPTEE'){
            $failed[]=['admission_id'=>$admissionId,'student'=>$label,'reason'=>'Décision universitaire absente'];
            continue;
        }

        if($a['placement_status']!=='CONFIRME'){
            $failed[]=['admission_id'=>$admissionId,'student'=>$label,'reason'=>'Placement universitaire non confirmé'];
            continue;
        }

        if(!in_array($a['admission_status'],['ADMIS','EN_COURS'],true)){
            $failed[]=['admission_id'=>$admissionId,'student'=>$label,'reason'=>'Stagiaire non admis'];
            continue;
        }

        if(empty($a['coordination_unit_id'])){
            $failed[]=['admission_id'=>$admissionId,'student'=>$label,'reason'=>'Département d’affectation absent'];
            continue;
        }

        $existingStmt->execute([$admissionId]);
        if($existingStmt->fetchColumn()){
            $failed[]=['admission_id'=>$admissionId,'student'=>$label,'reason'=>'Déjà affecté'];
            continue;
        }

        $start=max($today,(string)$a['campaign_start']);
        $end=(string)$a['campaign_end'];

        if(!$end || $start>$end){
            $failed[]=['admission_id'=>$admissionId,'student'=>$label,'reason'=>'Période de campagne terminée ou invalide'];
            continue;
        }

        /*
         * Choix équilibré selon le taux d'occupation.
         * Cela respecte les capacités différentes :
         * ex. 20 / 15 / 20.
         */
        $best=null;

        foreach($unitMap as $uid=>$u){
            if((int)($u['parent_id']??0)!==(int)$a['coordination_unit_id'])continue;

            $occupied=0;

            foreach($intervals[$uid] as $it){
                if(overlaps($start,$end,$it['start'],$it['end']))
                    $occupied++;
            }

            $available=$u['capacite']-$occupied;
            if($available<=0)continue;

            $ratio=$occupied/$u['capacite'];

            if(
                $best===null
                ||$ratio<$best['ratio']
                ||($ratio===$best['ratio'] && $available>$best['available'])
                ||($ratio===$best['ratio'] && $available===$best['available'] && $uid<$best['id'])
            ){
                $best=[
                    'id'=>$uid,
                    'unit'=>$u,
                    'occupied'=>$occupied,
                    'available'=>$available,
                    'ratio'=>$ratio
                ];
            }
        }

        if($best===null){
            $failed[]=[
                'admission_id'=>$admissionId,
                'student'=>$label,
                'reason'=>'Capacité insuffisante pour la période'
            ];
            continue;
        }

        $unit=$best['unit'];
        $unitId=(int)$unit['id'];

        $insertAssignment->execute([
            autoAssignmentUuid(),
            $admissionId,
            $unitId,
            $eid,
            $start,
            $end,
            $observation!==''?$observation:'Répartition automatique STAGIA',
            $actor
        ]);

        $assignmentId=(int)$pdo->lastInsertId();
        $assignmentNotifications[]=$assignmentId;

        $updateAdmission->execute([$admissionId,$eid]);

        $assignmentHistory->execute([
            $assignmentId,
            json_encode([
                'admission_id'=>$admissionId,
                'host_unit_id'=>$unitId,
                'host_unit_name'=>$unit['nom'],
                'date_debut'=>$start,
                'date_fin'=>$end,
                'source'=>'AUTO_DISTRIBUTION'
            ],JSON_UNESCAPED_UNICODE),
            $actor
        ]);

        $admissionHistory->execute([
            $admissionId,
            $a['admission_status'],
            json_encode([
                'assignment_id'=>$assignmentId,
                'host_unit_id'=>$unitId,
                'source'=>'AUTO_DISTRIBUTION'
            ],JSON_UNESCAPED_UNICODE),
            $actor
        ]);

        $intervals[$unitId][]=[
            'start'=>$start,
            'end'=>$end
        ];

        if(!isset($distribution[$unitId])){
            $distribution[$unitId]=[
                'host_unit_id'=>$unitId,
                'host_unit_name'=>$unit['nom'],
                'assigned'=>0,
                'capacity'=>$unit['capacite']
            ];
        }

        $distribution[$unitId]['assigned']++;
        $assigned++;
    }

    $pdo->commit();

    foreach($assignmentNotifications as $assignmentId){
        stageNotifyStudentAssignment($pdo,(int)$assignmentId,'stage.assignment.created');
    }

    jsonResponse(
        true,
        $assigned.' stagiaire(s) réparti(s) automatiquement.',
        [
            'requested'=>count($ids),
            'found'=>count($admissions),
            'assigned'=>$assigned,
            'failed'=>count($failed),
            'distribution'=>array_values($distribution),
            'errors'=>array_slice($failed,0,20)
        ]
    );

}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();

    jsonResponse(
        false,
        'Erreur répartition automatique : '.$e->getMessage(),
        [],
        422
    );
}

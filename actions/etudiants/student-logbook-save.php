<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);
verifyAjaxCsrf();

function logbookUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    $userId=(int)($_SESSION['user_id']??0);
    $id=(int)($_POST['id']??0);
    $assignmentId=(int)($_POST['assignment_id']??0);
    $rotationId=(int)($_POST['rotation_id']??0);

    $resume=trim((string)($_POST['resume_activites']??''));
    $apprentissages=trim((string)($_POST['apprentissages']??''));
    $difficultes=trim((string)($_POST['difficultes']??''));
    $observation=trim((string)($_POST['observation_etudiant']??''));

    $activities=json_decode((string)($_POST['activities']??'[]'),true);
    if(!is_array($activities))$activities=[];

    if($resume==='')
        jsonResponse(false,'Le résumé des activités est obligatoire.',[],422);

    $s=$pdo->prepare("
        SELECT id
        FROM student_profiles
        WHERE user_id=?
        LIMIT 1
    ");
    $s->execute([$userId]);
    $studentId=(int)$s->fetchColumn();

    if(!$studentId)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $allowedTypes=[
        'OBSERVATION','PARTICIPATION','REALISATION',
        'GARDE','CONSULTATION','AUTRE'
    ];
    $allowedLevels=[
        '',
        'OBSERVE','ASSISTE',
        'REALISE_SUPERVISE','REALISE_AUTONOME'
    ];

    $cleanActivities=[];

    foreach($activities as $i=>$a){
        $type=strtoupper(trim((string)($a['type_activite']??'PARTICIPATION')));
        $title=trim((string)($a['intitule']??''));
        $description=trim((string)($a['description']??''));
        $level=strtoupper(trim((string)($a['niveau_implication']??'')));
        $qty=max(1,(int)($a['quantite']??1));
        $obs=trim((string)($a['observation']??''));

        if(!in_array($type,$allowedTypes,true))
            jsonResponse(false,'Type d’activité invalide.',[],422);

        if($title==='')
            jsonResponse(false,'L’intitulé de chaque activité est obligatoire.',[],422);

        if(!in_array($level,$allowedLevels,true))
            jsonResponse(false,'Niveau d’implication invalide.',[],422);

        $cleanActivities[]=[
            'type'=>$type,
            'title'=>$title,
            'description'=>$description!==''?$description:null,
            'level'=>$level!==''?$level:null,
            'qty'=>$qty,
            'observation'=>$obs!==''?$obs:null
        ];
    }

    $today=date('Y-m-d');

    if($id){
        /*
         * Une modification garde toujours la rotation et la date
         * d'origine. Le client ne peut pas déplacer un journal.
         */
        $s=$pdo->prepare("
            SELECT id,rotation_id,assignment_id,date_journal,statut
            FROM stage_logbook_entries
            WHERE id=?
              AND student_id=?
            LIMIT 1
        ");
        $s->execute([$id,$studentId]);
        $entry=$s->fetch(PDO::FETCH_ASSOC);

        if(!$entry)
            jsonResponse(false,'Journal introuvable.',[],404);

        if(!in_array($entry['statut'],['BROUILLON','REJETE'],true))
            jsonResponse(false,'Ce journal ne peut plus être modifié.',[],422);

        $rotationId=(int)$entry['rotation_id'];
        $assignmentId=(int)$entry['assignment_id'];

        $pdo->prepare("
            UPDATE stage_logbook_entries
            SET
                resume_activites=?,
                apprentissages=?,
                difficultes=?,
                observation_etudiant=?,
                statut='BROUILLON',
                commentaire_encadreur=NULL,
                submitted_at=NULL,
                validated_at=NULL,
                validated_by=NULL
            WHERE id=?
              AND student_id=?
        ")->execute([
            $resume,
            $apprentissages!==''?$apprentissages:null,
            $difficultes!==''?$difficultes:null,
            $observation!==''?$observation:null,
            $id,
            $studentId
        ]);

        $pdo->prepare("
            DELETE FROM stage_logbook_activities
            WHERE logbook_entry_id=?
        ")->execute([$id]);

        $entryId=$id;

    }else{
        if(!$assignmentId||!$rotationId)
            jsonResponse(false,'Rotation active invalide.',[],422);

        /*
         * La rotation doit appartenir au stagiaire connecté
         * et être active aujourd'hui.
         */
        $s=$pdo->prepare("
            SELECT
                r.id AS rotation_id,
                r.assignment_id,
                sa.host_etablissement_id

            FROM student_profiles sp

            JOIN student_enrollments se
              ON se.student_id=sp.id

            JOIN student_academic_enrollments ae
              ON ae.enrollment_id=se.id

            JOIN stage_applications app
              ON app.academic_enrollment_id=ae.id

            JOIN stage_reservations sr
              ON sr.application_id=app.id
             AND sr.statut='CONFIRMEE'

            JOIN stage_admissions ad
              ON ad.reservation_id=sr.id
             AND ad.statut IN('ADMIS','EN_COURS')

            JOIN stage_assignments sa
              ON sa.admission_id=ad.id
             AND sa.id=?
             AND sa.statut IN('PLANIFIEE','ACTIVE')

            JOIN stage_rotations r
              ON r.assignment_id=sa.id
             AND r.id=?
             AND r.statut IN('PLANIFIEE','ACTIVE')
             AND ? BETWEEN r.date_debut AND r.date_fin

            WHERE sp.id=?

            LIMIT 1
        ");
        $s->execute([
            $assignmentId,
            $rotationId,
            $today,
            $studentId
        ]);
        $rotation=$s->fetch(PDO::FETCH_ASSOC);

        if(!$rotation)
            jsonResponse(
                false,
                'Aucune rotation active ne correspond à ce stage aujourd’hui.',
                [],
                422
            );

        $s=$pdo->prepare("
            SELECT id
            FROM stage_logbook_entries
            WHERE rotation_id=?
              AND student_id=?
              AND date_journal=?
            LIMIT 1
        ");
        $s->execute([$rotationId,$studentId,$today]);

        if($s->fetchColumn())
            jsonResponse(
                false,
                'Une entrée de journal existe déjà pour cette rotation aujourd’hui.',
                [],
                422
            );

        $pdo->prepare("
            INSERT INTO stage_logbook_entries(
                uuid,
                rotation_id,
                assignment_id,
                student_id,
                host_etablissement_id,
                date_journal,
                resume_activites,
                apprentissages,
                difficultes,
                observation_etudiant,
                statut
            )
            VALUES(
                ?,?,?,?,?,?,
                ?,?,?,?,
                'BROUILLON'
            )
        ")->execute([
            logbookUuid(),
            $rotationId,
            $assignmentId,
            $studentId,
            (int)$rotation['host_etablissement_id'],
            $today,
            $resume,
            $apprentissages!==''?$apprentissages:null,
            $difficultes!==''?$difficultes:null,
            $observation!==''?$observation:null
        ]);

        $entryId=(int)$pdo->lastInsertId();
    }

    if($cleanActivities){
        $ins=$pdo->prepare("
            INSERT INTO stage_logbook_activities(
                logbook_entry_id,
                type_activite,
                intitule,
                description,
                niveau_implication,
                quantite,
                observation
            )
            VALUES(?,?,?,?,?,?,?)
        ");

        foreach($cleanActivities as $a){
            $ins->execute([
                $entryId,
                $a['type'],
                $a['title'],
                $a['description'],
                $a['level'],
                $a['qty'],
                $a['observation']
            ]);
        }
    }

    jsonResponse(
        true,
        $id?'Journal mis à jour.':'Brouillon du journal enregistré.',
        [
            'id'=>$entryId,
            'rotation_id'=>$rotationId,
            'assignment_id'=>$assignmentId
        ]
    );

}catch(Throwable $e){
    error_log(
        '[STUDENT LOGBOOK SAVE] '.$e->getMessage().
        ' | '.$e->getFile().':'.$e->getLine()
    );

    jsonResponse(false,$e->getMessage(),[],422);
}

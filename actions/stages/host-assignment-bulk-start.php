<?php
/**
 * Endpoint AJAX qui prépare une affectation groupée puis lance un worker asynchrone.
 * Les validations lourdes sont faites avant de créer le travail afin d'éviter un lot incohérent.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-assignment-service.php';

/*
 * Pas de requireAjaxRole() ici : l'autorisation fine est centralisée dans
 * stageAssignmentActorCanUseCoordination(), qui vérifie le rôle actif en base,
 * l'établissement et le périmètre de coordination du Chef de service.
 */

/** Génère l'identifiant UUID public suivi par l'interface pendant le traitement asynchrone. */
function bulkAssignmentUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

/** Recherche un exécutable PHP CLI utilisable pour lancer le worker selon l'environnement. */
function bulkAssignmentDetectPhpCli():?string{
    $list=[];

    if(defined('PHP_BINARY')&&PHP_BINARY){
        if(strtolower(basename(PHP_BINARY))==='php.exe'&&is_file(PHP_BINARY))$list[]=PHP_BINARY;
        $sibling=dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php.exe';
        if(is_file($sibling))$list[]=$sibling;
    }

    if(DIRECTORY_SEPARATOR==='\\'){
        foreach(glob('C:/wamp64/bin/php/php*/php.exe')?:[] as $php)
            if(is_file($php))$list[]=$php;
    }else{
        $which=trim((string)@shell_exec('command -v php 2>/dev/null'));
        if($which&&is_file($which))$list[]=$which;
    }

    $list=array_values(array_unique($list));
    if(!$list)return null;
    usort($list,fn($a,$b)=>strnatcasecmp($b,$a));

    return $list[0];
}

/** Démarre le worker en arrière-plan et retourne un état de lancement explicite. */
function bulkAssignmentLaunch(string $uuid):array{
    $php=bulkAssignmentDetectPhpCli();
    $worker=realpath(__DIR__.'/../../workers/host-assignment-bulk-worker.php');

    if(!$php||!$worker)return [false,!$php?'PHP CLI introuvable.':'Worker d’affectation introuvable.'];

    if(DIRECTORY_SEPARATOR==='\\'){
        $php=str_replace('"','',$php);
        $worker=str_replace('"','',$worker);
        $uuid=preg_replace('/[^a-f0-9-]/i','',$uuid);

        $command='cmd /C start "" /B "'.$php.'" "'.$worker.'" "'.$uuid.'" >NUL 2>&1';
        $handle=@popen($command,'r');

        if($handle===false)return [false,'Impossible de démarrer le worker Windows.'];

        @pclose($handle);
        return [true,''];
    }

    @exec(escapeshellarg($php).' '.escapeshellarg($worker).' '.escapeshellarg($uuid).' > /dev/null 2>&1 &');

    return [true,''];
}

/** Vérifie strictement une date ISO avant les comparaisons de période. */
function bulkValidDate(string $v):bool{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
    return $d&&$d->format('Y-m-d')===$v;
}

try{
    /* CSRF, contexte de session et absence de tâche concurrente sont contrôlés en premier. */
    $csrf=$_POST['csrf']??'';

    if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
        jsonResponse(false,'Jeton de sécurité invalide.',[],419);

    $hostId=currentEtablissementId($pdo);
    $actorId=(int)($_SESSION['user_id']??0);

    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);
    if(!$actorId)jsonResponse(false,'Session utilisateur invalide.',[],401);

    $s=$pdo->prepare("
        SELECT uuid,statut,total_count
        FROM stage_assignment_bulk_jobs
        WHERE host_etablissement_id=? AND created_by_user_id=?
          AND statut IN('PENDING','RUNNING')
        ORDER BY id DESC
        LIMIT 1
    ");
    $s->execute([$hostId,$actorId]);
    $active=$s->fetch(PDO::FETCH_ASSOC);

    if($active){
        bulkAssignmentLaunch($active['uuid']);

        jsonResponse(true,'Une affectation groupée est déjà en cours.',[
            'job_uuid'=>$active['uuid'],
            'statut'=>$active['statut'],
            'total'=>(int)$active['total_count']
        ],202);
    }

    /* Les identifiants sélectionnés sont nettoyés, dédoublonnés et limités à 500 éléments. */
    $decoded=json_decode((string)($_POST['admission_ids']??'[]'),true);
    if(!is_array($decoded))$decoded=[];

    $ids=array_values(array_unique(array_filter(array_map('intval',$decoded),fn($id)=>$id>0)));

    if(!$ids)jsonResponse(false,'Sélectionnez au moins un stagiaire.',[],422);
    if(count($ids)>500)jsonResponse(false,'Maximum 500 stagiaires par opération.',[],422);

    $unitId=(int)($_POST['host_unit_id']??0);
    $dateDebut=trim((string)($_POST['date_debut']??''));
    $dateFin=trim((string)($_POST['date_fin']??''));
    $observation=trim((string)($_POST['observation']??''));

    if(!$unitId)jsonResponse(false,'Service obligatoire.',[],422);
    if(!bulkValidDate($dateDebut)||!bulkValidDate($dateFin)||$dateDebut>$dateFin)
        jsonResponse(false,'Période invalide.',[],422);

    /* Création atomique du job et de toutes ses lignes, sous verrou des données sélectionnées. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT id,nom,type,parent_id
        FROM host_units
        WHERE id=? AND host_etablissement_id=? AND actif=1
          AND UPPER(type) IN('SERVICE','UNITE','UNITÉ')
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$unitId,$hostId]);
    $unit=$s->fetch(PDO::FETCH_ASSOC);

    if(!$unit)throw new RuntimeException('Service invalide ou inactif.');

    $coordId=(int)($unit['parent_id']??0);
    if(!$coordId)throw new RuntimeException('Ce service n’est rattaché à aucune coordination.');

    if(!stageAssignmentActorCanUseCoordination($pdo,$hostId,$actorId,$coordId))
        throw new RuntimeException("Vous n'êtes pas autorisé à affecter les stagiaires de cette coordination.");

    $ph=implode(',',array_fill(0,count($ids),'?'));

    /* Les admissions doivent rester disponibles, dans la coordination et la période choisies. */
    $sql="
        SELECT ad.id admission_id,ad.coordination_unit_id,ad.statut admission_status,
               sr.id reservation_id,
               c.date_debut campaign_start,c.date_fin campaign_end,
               CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom) student_label
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications sa ON sa.id=sr.application_id
            AND sa.host_etablissement_id=ad.host_etablissement_id
            AND sa.statut='ACCEPTEE'
        JOIN stage_placements pl ON pl.id=ad.placement_id
            AND pl.reservation_id=sr.id
            AND pl.statut='CONFIRME'
        JOIN stage_campaigns c ON c.id=sa.campaign_id
        JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        JOIN student_enrollments se ON se.id=sae.enrollment_id
        JOIN student_profiles sp ON sp.id=se.student_id
        WHERE ad.host_etablissement_id=?
          AND ad.statut IN('ADMIS','EN_COURS')
          AND ad.id IN($ph)
          AND NOT EXISTS(
              SELECT 1 FROM stage_assignments a
              WHERE a.admission_id=ad.id
                AND a.host_etablissement_id=ad.host_etablissement_id
                AND a.statut IN('PLANIFIEE','ACTIVE')
          )
        FOR UPDATE
    ";

    $s=$pdo->prepare($sql);
    $s->execute(array_merge([$hostId],$ids));
    $students=$s->fetchAll(PDO::FETCH_ASSOC);

    if(count($students)!==count($ids))
        throw new RuntimeException('La sélection a changé : certains stagiaires ne sont plus disponibles. Actualisez la page.');

    $blocked=[];

    /* Paiement, coordination et bornes calendaires sont vérifiés pour chaque stagiaire. */
    foreach($students as $st){
        $label=trim((string)$st['student_label']);

        if((int)$st['coordination_unit_id']!==$coordId){
            $blocked[]=$label.' : coordination différente du service choisi';
            continue;
        }

        if(!empty($st['campaign_start'])&&$dateDebut<$st['campaign_start']){
            $blocked[]=$label.' : date avant la session';
            continue;
        }

        if(!empty($st['campaign_end'])&&$dateFin>$st['campaign_end']){
            $blocked[]=$label.' : date après la session';
            continue;
        }

        $payment=stageAssignmentPaymentCheck($pdo,(int)$st['reservation_id']);

        if(empty($payment['allowed'])){
            $invoice=$payment['invoice']??null;
            $ref=$invoice?(' facture '.$invoice['reference']):'';
            $blocked[]=$label.' : paiement non validé'.$ref;
        }
    }

    if($blocked)
        throw new RuntimeException("Affectation groupée bloquée :\n- ".implode("\n- ",array_slice($blocked,0,8)));

    /* Création de l'identifiant public du job une fois toutes les règles franchies. */
    $uuid=bulkAssignmentUuid();

    $s=$pdo->prepare("
        INSERT INTO stage_assignment_bulk_jobs(
            uuid,host_etablissement_id,host_unit_id,date_debut,date_fin,
            observation,created_by_user_id,statut,total_count
        )VALUES(?,?,?,?,?,?,?,'PENDING',?)
    ");
    $s->execute([$uuid,$hostId,$unitId,$dateDebut,$dateFin,$observation?:null,$actorId,count($students)]);

    $jobId=(int)$pdo->lastInsertId();
    if($jobId<=0)throw new RuntimeException('Impossible de créer la tâche d’affectation.');

    $insert=$pdo->prepare("
        INSERT INTO stage_assignment_bulk_job_items(job_id,admission_id,student_label,statut)
        VALUES(?,?,?,'PENDING')
    ");

    /* Chaque stagiaire devient une unité de traitement suivie par le worker. */
    foreach($students as $st)
        $insert->execute([$jobId,(int)$st['admission_id'],trim((string)$st['student_label'])]);

    $pdo->commit();

    /* Le worker démarre après le commit ; son échec est reflété dans le statut du job. */
    [$started,$error]=bulkAssignmentLaunch($uuid);

    if(!$started){
        $pdo->prepare("
            UPDATE stage_assignment_bulk_jobs
            SET statut='FAILED',error_message=?,finished_at=NOW()
            WHERE id=?
        ")->execute([$error,$jobId]);

        jsonResponse(false,'La tâche a été créée mais le traitement n’a pas pu démarrer : '.$error,['job_uuid'=>$uuid],500);
    }

    jsonResponse(true,'Affectation groupée démarrée en arrière-plan.',[
        'job_uuid'=>$uuid,
        'statut'=>'PENDING',
        'total'=>count($students),
        'processed'=>0,
        'assigned'=>0,
        'failed'=>0,
        'remaining'=>count($students),
        'progress'=>0
    ],202);

}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();

    error_log('[HOST ASSIGNMENT BULK START] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());

    jsonResponse(false,'Impossible de démarrer l’affectation groupée : '.$e->getMessage(),[],422);
}

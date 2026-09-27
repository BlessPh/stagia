<?php
/**
 * Endpoint AJAX de suivi d'une affectation groupée lancée en arrière-plan.
 * Un utilisateur ne peut consulter que les travaux qu'il a créés dans son établissement courant.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/*
 * La progression est protégée par l'établissement courant + created_by_user_id.
 * On évite requireAjaxRole(), qui refusait CHEF_SERVICE avant même la lecture
 * de sa propre tâche d'affectation groupée.
 */

try{
    /* Identité de session obligatoire : elle borne toutes les lectures de progression. */
    $hostId=currentEtablissementId($pdo);
    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);

    $actorId=(int)($_SESSION['user_id']??0);
    if(!$actorId)jsonResponse(false,'Session utilisateur invalide.',[],401);
    $uuid=trim((string)($_GET['job_uuid']??''));

    /* Un UUID cible un job donné ; sans UUID, le dernier job actif de l'auteur est renvoyé. */
    if($uuid!==''){
        $stmt=$pdo->prepare("
            SELECT *
            FROM stage_assignment_bulk_jobs
            WHERE uuid=? AND host_etablissement_id=? AND created_by_user_id=?
            LIMIT 1
        ");
        $stmt->execute([$uuid,$hostId,$actorId]);
    }else{
        $stmt=$pdo->prepare("
            SELECT *
            FROM stage_assignment_bulk_jobs
            WHERE host_etablissement_id=? AND created_by_user_id=?
              AND statut IN('PENDING','RUNNING')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$hostId,$actorId]);
    }

    $job=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$job)jsonResponse(true,'',['job'=>null]);

    /* Les compteurs sont calculés depuis les lignes du job et non fournis par le navigateur. */
    $stmt=$pdo->prepare("
        SELECT
            COUNT(*) total,
            SUM(statut IN('ASSIGNED','FAILED')) processed,
            SUM(statut='ASSIGNED') assigned,
            SUM(statut='FAILED') failed,
            SUM(statut IN('PENDING','RUNNING')) remaining
        FROM stage_assignment_bulk_job_items
        WHERE job_id=?
    ");
    $stmt->execute([$job['id']]);
    $s=$stmt->fetch(PDO::FETCH_ASSOC)?:[];

    $total=(int)($s['total']??0);
    $processed=(int)($s['processed']??0);
    $assigned=(int)($s['assigned']??0);
    $failed=(int)($s['failed']??0);
    $remaining=(int)($s['remaining']??0);

    /* Pourcentage robuste, y compris si un job terminé ne contient aucune ligne. */
    $progress=$total>0
        ?(int)round($processed*100/$total)
        :(in_array($job['statut'],['COMPLETED','COMPLETED_WITH_ERRORS'],true)?100:0);

    $stmt=$pdo->prepare("
        SELECT student_label,error_message
        FROM stage_assignment_bulk_job_items
        WHERE job_id=? AND statut='FAILED'
        ORDER BY id DESC
        LIMIT 8
    ");
    $stmt->execute([$job['id']]);
    $errors=$stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'job'=>[
            'uuid'=>$job['uuid'],
            'statut'=>$job['statut'],
            'total'=>$total,
            'processed'=>$processed,
            'assigned'=>$assigned,
            'failed'=>$failed,
            'remaining'=>$remaining,
            'progress'=>$progress,
            'current_student'=>$job['current_student'],
            'message'=>$job['message'],
            'error_message'=>$job['error_message'],
            'errors'=>$errors,
            'started_at'=>$job['started_at'],
            'finished_at'=>$job['finished_at'],
            'heartbeat_at'=>$job['heartbeat_at']
        ]
    ]);

}catch(Throwable $e){
    jsonResponse(false,'Impossible de lire la progression : '.$e->getMessage(),[],500);
}

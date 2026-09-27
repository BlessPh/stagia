<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

try{
    $etablissementId=currentEtablissementId($pdo);

    if(!$etablissementId){
        jsonResponse(false,'Aucun établissement associé.',[],422);
    }

    $uuid=trim($_GET['job_uuid']??'');

    if($uuid!==''){
        $stmt=$pdo->prepare("\n            SELECT *\n            FROM student_invitation_jobs\n            WHERE uuid=? AND etablissement_id=?\n            LIMIT 1\n        ");
        $stmt->execute([$uuid,$etablissementId]);
    }else{
        $stmt=$pdo->prepare("\n            SELECT *\n            FROM student_invitation_jobs\n            WHERE etablissement_id=?\n              AND statut IN('PENDING','RUNNING')\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->execute([$etablissementId]);
    }

    $job=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$job){
        jsonResponse(true,'',['job'=>null]);
    }

    $stmt=$pdo->prepare("\n        SELECT\n            COUNT(*) AS total,\n            SUM(statut IN('SENT','FAILED','SKIPPED')) AS processed,\n            SUM(statut='SENT') AS sent,\n            SUM(statut='FAILED') AS failed,\n            SUM(statut='SKIPPED') AS skipped,\n            SUM(statut IN('PENDING','RUNNING')) AS remaining\n        FROM student_invitation_job_items\n        WHERE job_id=?\n    ");
    $stmt->execute([$job['id']]);
    $stats=$stmt->fetch(PDO::FETCH_ASSOC)?:[];

    $total=(int)($stats['total']??0);
    $processed=(int)($stats['processed']??0);
    $sent=(int)($stats['sent']??0);
    $failed=(int)($stats['failed']??0);
    $skipped=(int)($stats['skipped']??0);
    $remaining=(int)($stats['remaining']??0);
    $progress=$total>0?(int)round($processed*100/$total):100;

    $stmt=$pdo->prepare("\n        SELECT sp.nom,sp.postnom,sp.prenom,i.email\n        FROM student_invitation_job_items i\n        INNER JOIN student_profiles sp ON sp.id=i.student_id\n        WHERE i.job_id=? AND i.statut='RUNNING'\n        ORDER BY i.id\n        LIMIT 1\n    ");
    $stmt->execute([$job['id']]);
    $current=$stmt->fetch(PDO::FETCH_ASSOC);

    $currentStudent=$current
        ?trim(implode(' ',array_filter([
            $current['nom']??'',
            $current['postnom']??'',
            $current['prenom']??''
        ])))
        :null;

    $stmt=$pdo->prepare("\n        SELECT sp.nom,sp.postnom,sp.prenom,i.email,i.error_message\n        FROM student_invitation_job_items i\n        INNER JOIN student_profiles sp ON sp.id=i.student_id\n        WHERE i.job_id=? AND i.statut='FAILED'\n        ORDER BY i.id DESC\n        LIMIT 5\n    ");
    $stmt->execute([$job['id']]);
    $errors=$stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach($errors as &$error){
        $error['student']=trim(implode(' ',array_filter([
            $error['nom']??'',
            $error['postnom']??'',
            $error['prenom']??''
        ])));
        unset($error['nom'],$error['postnom'],$error['prenom']);
    }
    unset($error);

    jsonResponse(
        true,
        '',
        [
            'job'=>[
                'uuid'=>$job['uuid'],
                'statut'=>$job['statut'],
                'total'=>$total,
                'processed'=>$processed,
                'sent'=>$sent,
                'failed'=>$failed,
                'skipped'=>$skipped,
                'remaining'=>$remaining,
                'progress'=>$progress,
                'current_student'=>$currentStudent,
                'started_at'=>$job['started_at'],
                'finished_at'=>$job['finished_at'],
                'heartbeat_at'=>$job['heartbeat_at'],
                'last_error'=>$job['last_error'],
                'errors'=>$errors
            ]
        ]
    );

}catch(Throwable $e){
    error_log('[STUDENT INVITATION STATUS] '.$e->getMessage());

    jsonResponse(
        false,
        'Impossible de lire la progression des invitations.',
        [],
        500
    );
}

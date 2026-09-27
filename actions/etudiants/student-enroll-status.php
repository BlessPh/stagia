<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

try{

    $etablissementId=
        currentEtablissementId(
            $pdo
        );


    if(!$etablissementId){

        jsonResponse(
            false,
            'Aucun établissement associé.',
            [],
            403
        );
    }


    $userId=
        (int)(
            $_SESSION['user_id']
            ??0
        );


    $uuid=
        trim(
            $_GET['job_uuid']
            ??''
        );


    if($uuid!==''){

        $stmt=$pdo->prepare("
            SELECT *
            FROM student_enrollment_jobs
            WHERE uuid=?
              AND etablissement_id=?
              AND created_by_user_id=?
            LIMIT 1
        ");


        $stmt->execute([
            $uuid,
            $etablissementId,
            $userId
        ]);

    }else{

        $stmt=$pdo->prepare("
            SELECT *
            FROM student_enrollment_jobs
            WHERE etablissement_id=?
              AND created_by_user_id=?
              AND statut IN(
                    'PENDING',
                    'RUNNING'
              )
            ORDER BY id DESC
            LIMIT 1
        ");


        $stmt->execute([
            $etablissementId,
            $userId
        ]);
    }


    $job=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if(!$job){

        jsonResponse(
            true,
            '',
            [
                'job'=>null
            ]
        );
    }


    $result=
        [];


    if(
        !empty(
            $job['result_json']
        )
    ){

        $decoded=
            json_decode(
                $job['result_json'],
                true
            );


        if(is_array($decoded))
            $result=$decoded;
    }


    jsonResponse(
        true,
        '',
        [
            'job'=>[
                'uuid'=>$job['uuid'],
                'statut'=>$job['statut'],
                'progress'=>(int)$job['progress'],
                'step_label'=>$job['step_label'],
                'message'=>$job['message'],
                'error_message'=>$job['error_message'],
                'result'=>$result,
                'started_at'=>$job['started_at'],
                'finished_at'=>$job['finished_at'],
                'heartbeat_at'=>$job['heartbeat_at']
            ]
        ]
    );


}catch(Throwable $e){

    error_log(
        '[STUDENT ENROLL STATUS] '.
        $e->getMessage()
    );


    jsonResponse(
        false,
        'Impossible de lire la progression de l’inscription.',
        [],
        500
    );
}

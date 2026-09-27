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
            FROM student_import_jobs
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
            FROM student_import_jobs
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


    $stmt=$pdo->prepare("
        SELECT
            COUNT(*) AS total,

            SUM(
                statut IN(
                    'IMPORTED',
                    'FAILED'
                )
            ) AS processed,

            SUM(
                statut='IMPORTED'
            ) AS imported,

            SUM(
                statut='FAILED'
            ) AS failed,

            SUM(
                statut IN(
                    'PENDING',
                    'RUNNING'
                )
            ) AS remaining

        FROM student_import_job_items

        WHERE job_id=?
    ");


    $stmt->execute([
        $job['id']
    ]);


    $stats=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?:[];


    $total=
        (int)(
            $stats['total']
            ??0
        );


    $processed=
        (int)(
            $stats['processed']
            ??0
        );


    $imported=
        (int)(
            $stats['imported']
            ??0
        );


    $failed=
        (int)(
            $stats['failed']
            ??0
        );


    $remaining=
        (int)(
            $stats['remaining']
            ??0
        );


    $progress=
        $total>0
        ?(int)round(
            $processed*100/$total
        )
        :(int)$job['progress'];


    $stmt=$pdo->prepare("
        SELECT
            source_row AS row_number,
            error_message

        FROM student_import_job_items

        WHERE job_id=?
          AND statut='FAILED'

        ORDER BY id DESC

        LIMIT 8
    ");


    $stmt->execute([
        $job['id']
    ]);


    $errors=
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    jsonResponse(
        true,
        '',
        [
            'job'=>[
                'uuid'=>$job['uuid'],
                'statut'=>$job['statut'],
                'progress'=>$progress,
                'step_label'=>$job['step_label'],
                'message'=>$job['message'],
                'error_message'=>$job['error_message'],

                'total'=>$total,
                'processed'=>$processed,
                'imported'=>$imported,
                'failed'=>$failed,
                'remaining'=>$remaining,


                'current_student'=>$job['current_student'],
                'errors'=>$errors,

                'started_at'=>$job['started_at'],
                'finished_at'=>$job['finished_at'],
                'heartbeat_at'=>$job['heartbeat_at']
            ]
        ]
    );


}catch(Throwable $e){

    error_log(
        '[STUDENT IMPORT STATUS] '.
        $e->getMessage()
    );


    jsonResponse(
        false,
        'Impossible de lire la progression de l’import.',
        [],
        500
    );
}

<?php
if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/stage-assignment-service.php';

$jobUuid=trim((string)($argv[1]??''));

if(!preg_match('/^[a-f0-9-]{36}$/i',$jobUuid))
    exit(2);

function bulkWorkerStats(PDO $pdo,int $jobId):array{
    $stmt=$pdo->prepare("
        SELECT
            COUNT(*) total,
            SUM(statut='ASSIGNED') assigned,
            SUM(statut='FAILED') failed,
            SUM(statut IN('ASSIGNED','FAILED')) processed,
            SUM(statut IN('PENDING','RUNNING')) remaining
        FROM stage_assignment_bulk_job_items
        WHERE job_id=?
    ");
    $stmt->execute([$jobId]);
    $x=$stmt->fetch(PDO::FETCH_ASSOC)?:[];

    foreach(['total','assigned','failed','processed','remaining'] as $key)
        $x[$key]=(int)($x[$key]??0);

    return $x;
}

try{
    $lockName='stagia_bulk_assignment_'.$jobUuid;

    $stmt=$pdo->prepare("SELECT GET_LOCK(?,0)");
    $stmt->execute([$lockName]);

    if((int)$stmt->fetchColumn()!==1)
        exit(0);

    try{
        $stmt=$pdo->prepare("
            SELECT *
            FROM stage_assignment_bulk_jobs
            WHERE uuid=?
            LIMIT 1
        ");
        $stmt->execute([$jobUuid]);
        $job=$stmt->fetch(PDO::FETCH_ASSOC);

        if(!$job)exit(3);

        if(in_array($job['statut'],['COMPLETED','COMPLETED_WITH_ERRORS'],true))
            exit(0);

        /* Reprise sûre après interruption éventuelle. */
        $pdo->prepare("
            UPDATE stage_assignment_bulk_job_items
            SET statut='PENDING'
            WHERE job_id=? AND statut='RUNNING'
        ")->execute([$job['id']]);

        $pdo->prepare("
            UPDATE stage_assignment_bulk_jobs
            SET statut='RUNNING',
                started_at=COALESCE(started_at,NOW()),
                heartbeat_at=NOW(),
                error_message=NULL
            WHERE id=?
        ")->execute([$job['id']]);

        while(true){
            $pdo->beginTransaction();

            $stmt=$pdo->prepare("
                SELECT *
                FROM stage_assignment_bulk_job_items
                WHERE job_id=? AND statut='PENDING'
                ORDER BY id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([$job['id']]);
            $item=$stmt->fetch(PDO::FETCH_ASSOC);

            if(!$item){
                $pdo->commit();
                break;
            }

            $pdo->prepare("
                UPDATE stage_assignment_bulk_job_items
                SET statut='RUNNING',
                    started_at=COALESCE(started_at,NOW()),
                    error_message=NULL
                WHERE id=?
            ")->execute([$item['id']]);

            $pdo->prepare("
                UPDATE stage_assignment_bulk_jobs
                SET current_admission_id=?,
                    current_student=?,
                    heartbeat_at=NOW()
                WHERE id=?
            ")->execute([
                $item['admission_id'],
                $item['student_label'],
                $job['id']
            ]);

            $pdo->commit();

            try{
                $result=stageAssignmentSaveOne(
                    $pdo,
                    (int)$job['host_etablissement_id'],
                    (int)$job['created_by_user_id'],
                    [
                        'admission_id'=>(int)$item['admission_id'],
                        'host_unit_id'=>(int)$job['host_unit_id'],
                        'date_debut'=>$job['date_debut'],
                        'date_fin'=>$job['date_fin'],
                        'observation'=>$job['observation']??''
                    ]
                );

                $pdo->prepare("
                    UPDATE stage_assignment_bulk_job_items
                    SET statut='ASSIGNED',
                        assignment_id=?,
                        error_message=NULL,
                        finished_at=NOW()
                    WHERE id=?
                ")->execute([
                    $result['id'],
                    $item['id']
                ]);

            }catch(Throwable $e){
                $pdo->prepare("
                    UPDATE stage_assignment_bulk_job_items
                    SET statut='FAILED',
                        error_message=?,
                        finished_at=NOW()
                    WHERE id=?
                ")->execute([
                    mb_substr($e->getMessage(),0,4000),
                    $item['id']
                ]);

                error_log(
                    '[BULK ASSIGNMENT ITEM] admission='.
                    $item['admission_id'].' | '.$e->getMessage()
                );
            }

            $pdo->prepare("
                UPDATE stage_assignment_bulk_jobs
                SET heartbeat_at=NOW()
                WHERE id=?
            ")->execute([$job['id']]);
        }

        $stats=bulkWorkerStats($pdo,(int)$job['id']);

        $finalStatus=$stats['failed']>0
            ?'COMPLETED_WITH_ERRORS'
            :'COMPLETED';

        $message=$stats['assigned'].' stagiaire(s) affecté(s)';

        if($stats['failed']>0)
            $message.=', '.$stats['failed'].' échec(s)';

        $pdo->prepare("
            UPDATE stage_assignment_bulk_jobs
            SET statut=?,
                processed_count=?,
                success_count=?,
                failed_count=?,
                message=?,
                current_admission_id=NULL,
                current_student=NULL,
                finished_at=NOW(),
                heartbeat_at=NOW()
            WHERE id=?
        ")->execute([
            $finalStatus,
            $stats['processed'],
            $stats['assigned'],
            $stats['failed'],
            $message.'.',
            $job['id']
        ]);

    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();

        if(isset($job['id'])){
            $pdo->prepare("
                UPDATE stage_assignment_bulk_jobs
                SET statut='FAILED',
                    error_message=?,
                    current_admission_id=NULL,
                    current_student=NULL,
                    finished_at=NOW(),
                    heartbeat_at=NOW()
                WHERE id=?
            ")->execute([
                mb_substr($e->getMessage(),0,4000),
                $job['id']
            ]);
        }

        error_log('[BULK ASSIGNMENT WORKER] '.$e->getMessage());

    }finally{
        try{
            $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
        }catch(Throwable $ignored){}
    }

}catch(Throwable $e){
    error_log('[BULK ASSIGNMENT WORKER FATAL] '.$e->getMessage());
    exit(1);
}

exit(0);

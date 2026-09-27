<?php

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../services/MailService.php';

$jobUuid=trim($argv[1]??'');

if(!preg_match('/^[a-f0-9-]{36}$/i',$jobUuid)){
    exit(2);
}

function workerFailJob(PDO $pdo,string $jobUuid,string $error):void
{
    $stmt=$pdo->prepare("\n        UPDATE student_invitation_jobs\n        SET statut='FAILED',\n            last_error=?,\n            finished_at=NOW(),\n            heartbeat_at=NOW()\n        WHERE uuid=?\n          AND statut IN('PENDING','RUNNING')\n    ");
    $stmt->execute([
        mb_substr($error,0,4000),
        $jobUuid
    ]);
}

function workerTableExists(PDO $pdo,string $table):bool
{
    $stmt=$pdo->prepare("\n        SELECT COUNT(*)\n        FROM information_schema.TABLES\n        WHERE TABLE_SCHEMA=DATABASE()\n          AND TABLE_NAME=?\n    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn()>0;
}

function ensureStudentSelfRole(
    PDO $pdo,
    int $userId,
    int $roleId,
    int $etablissementId,
    ?int $assignedBy
):void {
    if(!workerTableExists($pdo,'role_assignments')) return;

    $stmt=$pdo->prepare("\n        SELECT id\n        FROM role_assignments\n        WHERE user_id=?\n          AND role_id=?\n          AND scope_type='SELF'\n          AND actif=1\n        LIMIT 1\n    ");
    $stmt->execute([$userId,$roleId]);

    if($stmt->fetchColumn()) return;

    $stmt=$pdo->prepare("\n        INSERT INTO role_assignments(\n            user_id,role_id,scope_type,scope_id,etablissement_id,\n            principal,actif,assigned_by\n        )\n        VALUES(?,?,'SELF',?,?,1,1,?)\n    ");
    $stmt->execute([
        $userId,
        $roleId,
        $userId,
        $etablissementId,
        $assignedBy
    ]);
}

try{
    $workerLock='stagia_invitation_worker_'.$jobUuid;
    $stmt=$pdo->prepare('SELECT GET_LOCK(?,0)');
    $stmt->execute([$workerLock]);

    if((int)$stmt->fetchColumn()!==1){
        exit(0);
    }

    try{
        $stmt=$pdo->prepare("\n            SELECT *\n            FROM student_invitation_jobs\n            WHERE uuid=?\n            LIMIT 1\n        ");
        $stmt->execute([$jobUuid]);
        $job=$stmt->fetch(PDO::FETCH_ASSOC);

        if(!$job) exit(3);

        if(in_array($job['statut'],['COMPLETED','COMPLETED_WITH_ERRORS'],true)){
            exit(0);
        }

        $stmt=$pdo->prepare("\n            UPDATE student_invitation_jobs\n            SET statut='RUNNING',\n                started_at=COALESCE(started_at,NOW()),\n                heartbeat_at=NOW(),\n                last_error=NULL\n            WHERE id=?\n        ");
        $stmt->execute([$job['id']]);

        $stmt=$pdo->prepare("SELECT id FROM roles WHERE code='STAGIAIRE' LIMIT 1");
        $stmt->execute();
        $roleId=(int)$stmt->fetchColumn();

        if(!$roleId){
            throw new RuntimeException('Le rôle STAGIAIRE est introuvable.');
        }

        while(true){
            /* Prendre un élément sans dépendre du navigateur. */
            $pdo->beginTransaction();

            $stmt=$pdo->prepare("\n                SELECT *\n                FROM student_invitation_job_items\n                WHERE job_id=?\n                  AND statut='PENDING'\n                ORDER BY id\n                LIMIT 1\n                FOR UPDATE\n            ");
            $stmt->execute([$job['id']]);
            $item=$stmt->fetch(PDO::FETCH_ASSOC);

            if(!$item){
                $pdo->commit();
                break;
            }

            $stmt=$pdo->prepare("\n                UPDATE student_invitation_job_items\n                SET statut='RUNNING',\n                    attempts=attempts+1,\n                    started_at=COALESCE(started_at,NOW()),\n                    error_message=NULL\n                WHERE id=?\n            ");
            $stmt->execute([$item['id']]);

            $pdo->prepare("\n                UPDATE student_invitation_jobs\n                SET heartbeat_at=NOW()\n                WHERE id=?\n            ")->execute([$job['id']]);

            $pdo->commit();

            try{
                $stmt=$pdo->prepare("\n                    SELECT\n                        sp.id,\n                        sp.stagia_code,\n                        sp.nom,\n                        sp.postnom,\n                        sp.prenom,\n                        sp.email AS profile_email,\n                        sp.telephone,\n                        sp.user_id,\n                        se.email_institutionnel,\n                        u.email AS user_email,\n                        u.statut_compte AS user_status\n                    FROM student_profiles sp\n                    INNER JOIN student_enrollments se\n                        ON se.student_id=sp.id\n                       AND se.etablissement_id=?\n                       AND se.statut='ACTIF'\n                    LEFT JOIN users u ON u.id=sp.user_id\n                    WHERE sp.id=?\n                      AND sp.statut='ACTIF'\n                    LIMIT 1\n                ");
                $stmt->execute([
                    (int)$job['etablissement_id'],
                    (int)$item['student_id']
                ]);
                $student=$stmt->fetch(PDO::FETCH_ASSOC);

                if(!$student){
                    throw new RuntimeException('Étudiant introuvable ou hors périmètre.');
                }

                if($student['user_id'] && $student['user_status']!=='A_ACTIVER'){
                    $stmt=$pdo->prepare("\n                        UPDATE student_invitation_job_items\n                        SET statut='SKIPPED',\n                            error_message='Compte déjà actif.',\n                            finished_at=NOW()\n                        WHERE id=?\n                    ");
                    $stmt->execute([$item['id']]);
                    continue;
                }

                $email=trim((string)(
                    $student['user_email']
                    ?: $student['profile_email']
                    ?: $student['email_institutionnel']
                    ?: $item['email']
                    ?: ''
                ));

                if(!filter_var($email,FILTER_VALIDATE_EMAIL)){
                    throw new RuntimeException('Adresse e-mail absente ou invalide.');
                }

                $identifiant=trim((string)($student['stagia_code']??''));

                if($identifiant===''){
                    throw new RuntimeException('Identifiant STAGIA manquant.');
                }

                $token=bin2hex(random_bytes(32));
                $tokenHash=hash('sha256',$token);
                $expiration=date('Y-m-d H:i:s',time()+48*3600);

                $pdo->beginTransaction();

                if(!$student['user_id']){
                    $stmt=$pdo->prepare("\n                        SELECT id\n                        FROM users\n                        WHERE LOWER(TRIM(email))=LOWER(TRIM(?))\n                        LIMIT 1\n                        FOR UPDATE\n                    ");
                    $stmt->execute([$email]);

                    if($stmt->fetchColumn()){
                        throw new RuntimeException('Cette adresse e-mail est déjà utilisée.');
                    }

                    $stmt=$pdo->prepare("\n                        SELECT id\n                        FROM users\n                        WHERE identifiant=?\n                        LIMIT 1\n                        FOR UPDATE\n                    ");
                    $stmt->execute([$identifiant]);

                    if($stmt->fetchColumn()){
                        throw new RuntimeException('Cet identifiant STAGIA possède déjà un compte utilisateur.');
                    }

                    $stmt=$pdo->prepare("\n                        INSERT INTO users(\n                            role_id,nom,postnom,prenom,email,identifiant,password,\n                            telephone,actif,statut_compte,activation_token_hash,\n                            activation_expire_at\n                        )\n                        VALUES(?,?,?,?,?,?,NULL,?,1,'A_ACTIVER',?,?)\n                    ");
                    $stmt->execute([
                        $roleId,
                        $student['nom'],
                        $student['postnom']?:null,
                        $student['prenom']?:null,
                        $email,
                        $identifiant,
                        $student['telephone']?:null,
                        $tokenHash,
                        $expiration
                    ]);

                    $accountUserId=(int)$pdo->lastInsertId();

                    if($accountUserId<=0){
                        throw new RuntimeException(
                            "users.id n'a pas généré d'identifiant AUTO_INCREMENT valide. Exécutez la migration 39."
                        );
                    }

                    $stmt=$pdo->prepare("\n                        UPDATE student_profiles\n                        SET user_id=?\n                        WHERE id=?\n                          AND user_id IS NULL\n                    ");
                    $stmt->execute([
                        $accountUserId,
                        $student['id']
                    ]);

                    if($stmt->rowCount()!==1){
                        throw new RuntimeException('Impossible de relier le compte au profil étudiant.');
                    }

                }else{
                    $accountUserId=(int)$student['user_id'];

                    $stmt=$pdo->prepare("\n                        UPDATE users\n                        SET activation_token_hash=?,\n                            activation_expire_at=?,\n                            statut_compte='A_ACTIVER',\n                            actif=1\n                        WHERE id=?\n                          AND statut_compte='A_ACTIVER'\n                    ");
                    $stmt->execute([
                        $tokenHash,
                        $expiration,
                        $accountUserId
                    ]);

                    if($stmt->rowCount()!==1){
                        throw new RuntimeException('Le compte n’est plus en attente d’activation.');
                    }
                }

                ensureStudentSelfRole(
                    $pdo,
                    $accountUserId,
                    $roleId,
                    (int)$job['etablissement_id'],
                    $job['created_by_user_id']?(int)$job['created_by_user_id']:null
                );

                $pdo->commit();

                $activationUrl=
                    rtrim($job['activation_base_url'],'/').
                    '/activation-etudiant.php?token='.
                    urlencode($token);

                $nomComplet=trim(implode(' ',array_filter([
                    $student['nom']??'',
                    $student['postnom']??'',
                    $student['prenom']??''
                ])));

                $mailSent=MailService::envoyerActivation(
                    $email,
                    $nomComplet,
                    $activationUrl
                );

                if(!$mailSent){
                    throw new RuntimeException(
                        'Échec SMTP : '.(MailService::getLastError()?:'envoi impossible')
                    );
                }

                $stmt=$pdo->prepare("\n                    UPDATE student_invitation_job_items\n                    SET statut='SENT',\n                        sent_at=NOW(),\n                        finished_at=NOW(),\n                        error_message=NULL\n                    WHERE id=?\n                ");
                $stmt->execute([$item['id']]);

            }catch(Throwable $e){
                if($pdo->inTransaction()) $pdo->rollBack();

                $message=mb_substr($e->getMessage(),0,4000);

                $stmt=$pdo->prepare("\n                    UPDATE student_invitation_job_items\n                    SET statut='FAILED',\n                        error_message=?,\n                        finished_at=NOW()\n                    WHERE id=?\n                ");
                $stmt->execute([$message,$item['id']]);

                error_log(
                    '[STUDENT INVITATION ITEM] student='.
                    $item['student_id'].' | '.$message
                );
            }

            $pdo->prepare("\n                UPDATE student_invitation_jobs\n                SET heartbeat_at=NOW()\n                WHERE id=?\n            ")->execute([$job['id']]);
        }

        $stmt=$pdo->prepare("\n            SELECT\n                SUM(statut='FAILED') AS failed,\n                SUM(statut='PENDING') AS pending,\n                SUM(statut='RUNNING') AS running\n            FROM student_invitation_job_items\n            WHERE job_id=?\n        ");
        $stmt->execute([$job['id']]);
        $counts=$stmt->fetch(PDO::FETCH_ASSOC)?:[];

        $failed=(int)($counts['failed']??0);
        $pending=(int)($counts['pending']??0);
        $running=(int)($counts['running']??0);

        if($pending===0 && $running===0){
            $finalStatus=$failed>0?'COMPLETED_WITH_ERRORS':'COMPLETED';

            $stmt=$pdo->prepare("\n                UPDATE student_invitation_jobs\n                SET statut=?,\n                    finished_at=NOW(),\n                    heartbeat_at=NOW()\n                WHERE id=?\n            ");
            $stmt->execute([$finalStatus,$job['id']]);
        }

    }finally{
        try{
            $stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$workerLock]);
        }catch(Throwable $ignored){}
    }

}catch(Throwable $e){
    error_log('[STUDENT INVITATION WORKER] '.$e->getMessage());

    try{
        workerFailJob($pdo,$jobUuid,$e->getMessage());
    }catch(Throwable $ignored){}

    exit(1);
}

exit(0);

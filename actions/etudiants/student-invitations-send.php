<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

verifyAjaxCsrf();

function invitationUuid():string
{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($d),4)
    );
}

function detectPhpCli():?string
{
    $candidates=[];

    if(
        defined('PHP_BINARY') &&
        PHP_BINARY &&
        strtolower(basename(PHP_BINARY))==='php.exe' &&
        is_file(PHP_BINARY)
    ){
        $candidates[]=PHP_BINARY;
    }

    if(defined('PHP_BINARY') && PHP_BINARY){
        $sibling=dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php.exe';
        if(is_file($sibling)) $candidates[]=$sibling;
    }

    if(DIRECTORY_SEPARATOR==='\\'){
        foreach(glob('C:/wamp64/bin/php/php*/php.exe')?:[] as $php){
            if(is_file($php)) $candidates[]=$php;
        }
    }else{
        $which=trim((string)@shell_exec('command -v php 2>/dev/null'));
        if($which && is_file($which)) $candidates[]=$which;
    }

    $candidates=array_values(array_unique($candidates));
    if(!$candidates) return null;

    usort($candidates,fn($a,$b)=>strnatcasecmp($b,$a));
    return $candidates[0];
}

function launchInvitationWorker(string $jobUuid):array
{
    $php=detectPhpCli();
    $worker=realpath(__DIR__.'/../../workers/student-invitations-worker.php');

    if(!$php || !$worker){
        return [
            false,
            !$php?'PHP CLI introuvable.':'Worker introuvable.'
        ];
    }

    if(DIRECTORY_SEPARATOR==='\\'){
        $php=str_replace('"','',$php);
        $worker=str_replace('"','',$worker);
        $jobUuid=preg_replace('/[^a-f0-9-]/i','',$jobUuid);

        $command='cmd /C start "" /B "'.$php.'" "'.$worker.'" "'.$jobUuid.'" >NUL 2>&1';
        $handle=@popen($command,'r');

        if($handle===false)
            return [false,'Impossible de démarrer le worker Windows.'];

        @pclose($handle);
        return [true,''];
    }

    $command=
        escapeshellarg($php).' '.
        escapeshellarg($worker).' '.
        escapeshellarg($jobUuid).
        ' > /dev/null 2>&1 &';

    @exec($command);
    return [true,''];
}

try{
    $etablissementId=currentEtablissementId($pdo);

    if(!$etablissementId){
        jsonResponse(false,'Aucun établissement associé.',[],422);
    }

    $userId=(int)($_SESSION['user_id']??0);

    /* Vérifier users.id avant de lancer une boucle entière. */
    $stmt=$pdo->prepare("
        SELECT EXTRA
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME='users'
          AND COLUMN_NAME='id'
        LIMIT 1
    ");
    $stmt->execute();

    $usersIdExtra=strtolower((string)$stmt->fetchColumn());

    if(strpos($usersIdExtra,'auto_increment')===false){
        jsonResponse(
            false,
            "La table users n'est pas correctement configurée : users.id doit être AUTO_INCREMENT. Exécutez la migration 39.",
            [],
            500
        );
    }

    /* Une seule tâche active par établissement. */
    $lockName='stagia_student_invites_'.$etablissementId;
    $stmt=$pdo->prepare('SELECT GET_LOCK(?,5)');
    $stmt->execute([$lockName]);

    if((int)$stmt->fetchColumn()!==1){
        jsonResponse(false,'Impossible de verrouiller la création de la tâche.',[],409);
    }

    try{
        $stmt=$pdo->prepare("\n            SELECT uuid,total_count,statut\n            FROM student_invitation_jobs\n            WHERE etablissement_id=?\n              AND statut IN('PENDING','RUNNING')\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $stmt->execute([$etablissementId]);
        $active=$stmt->fetch(PDO::FETCH_ASSOC);

        if($active){
            launchInvitationWorker($active['uuid']);

            jsonResponse(
                true,
                'Un envoi d’invitations est déjà en cours.',
                [
                    'job_uuid'=>$active['uuid'],
                    'total'=>(int)$active['total_count'],
                    'statut'=>$active['statut'],
                    'already_running'=>true
                ],
                202
            );
        }

        /* Vérifier le rôle avant de créer le job. */
        $stmt=$pdo->prepare("SELECT id FROM roles WHERE code='STAGIAIRE' LIMIT 1");
        $stmt->execute();

        if(!(int)$stmt->fetchColumn()){
            jsonResponse(false,'Le rôle STAGIAIRE est introuvable.',[],500);
        }

        /*
         * Candidats :
         * - profil ACTIF ;
         * - inscription ACTIF dans l'établissement courant ;
         * - pas encore de compte OU compte encore A_ACTIVER.
         */
        $stmt=$pdo->prepare("\n            SELECT DISTINCT\n                sp.id AS student_id,\n                COALESCE(\n                    NULLIF(TRIM(u.email),''),\n                    NULLIF(TRIM(sp.email),''),\n                    NULLIF(TRIM(se.email_institutionnel),'')\n                ) AS email\n            FROM student_profiles sp\n            INNER JOIN student_enrollments se\n                ON se.student_id=sp.id\n               AND se.etablissement_id=?\n               AND se.statut='ACTIF'\n            LEFT JOIN users u ON u.id=sp.user_id\n            WHERE sp.statut='ACTIF'\n              AND (\n                    sp.user_id IS NULL\n                    OR u.statut_compte='A_ACTIVER'\n                  )\n            ORDER BY sp.id\n        ");
        $stmt->execute([$etablissementId]);
        $students=$stmt->fetchAll(PDO::FETCH_ASSOC);

        if(!$students){
            jsonResponse(
                true,
                'Aucune invitation à envoyer.',
                [
                    'job_uuid'=>null,
                    'total'=>0,
                    'statut'=>'COMPLETED'
                ]
            );
        }

        $scheme=(
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS']!=='off'
        )?'https':'http';

        $host=preg_replace(
            '/[^a-zA-Z0-9.:\-\[\]]/',
            '',
            (string)($_SERVER['HTTP_HOST']??'localhost')
        );

        $activationBaseUrl=$scheme.'://'.$host.BASE_URL;
        $uuid=invitationUuid();

        $pdo->beginTransaction();

        $stmt=$pdo->prepare("\n            INSERT INTO student_invitation_jobs(\n                uuid,etablissement_id,created_by_user_id,\n                activation_base_url,statut,total_count\n            )\n            VALUES(?,?,?,?,'PENDING',?)\n        ");
        $stmt->execute([
            $uuid,
            $etablissementId,
            $userId?:null,
            $activationBaseUrl,
            count($students)
        ]);

        $jobId=(int)$pdo->lastInsertId();

        $insertItem=$pdo->prepare("\n            INSERT INTO student_invitation_job_items(\n                job_id,student_id,email,statut\n            )\n            VALUES(?,?,?,'PENDING')\n        ");

        foreach($students as $student){
            $insertItem->execute([
                $jobId,
                (int)$student['student_id'],
                $student['email']?:null
            ]);
        }

        $pdo->commit();

        [$started,$error]=launchInvitationWorker($uuid);

        if(!$started){
            $stmt=$pdo->prepare("\n                UPDATE student_invitation_jobs\n                SET statut='FAILED',last_error=?,finished_at=NOW()\n                WHERE uuid=?\n            ");
            $stmt->execute([$error,$uuid]);

            jsonResponse(
                false,
                'La tâche a été créée mais le traitement en arrière-plan n’a pas pu démarrer : '.$error,
                ['job_uuid'=>$uuid],
                500
            );
        }

        jsonResponse(
            true,
            'Envoi des invitations démarré en arrière-plan.',
            [
                'job_uuid'=>$uuid,
                'total'=>count($students),
                'processed'=>0,
                'sent'=>0,
                'failed'=>0,
                'skipped'=>0,
                'remaining'=>count($students),
                'progress'=>0,
                'statut'=>'PENDING'
            ],
            202
        );

    }finally{
        try{
            $stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([$lockName]);
        }catch(Throwable $ignored){}
    }

}catch(Throwable $e){
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();

    error_log('[STUDENT INVITATION START] '.$e->getMessage());

    jsonResponse(
        false,
        'Impossible de démarrer l’envoi des invitations.',
        [],
        500
    );
}

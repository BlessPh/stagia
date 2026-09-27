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


function importUuid():string
{
    $d=random_bytes(16);

    $d[6]=chr((ord($d[6])&15)|64);
    $d[8]=chr((ord($d[8])&63)|128);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(
            bin2hex($d),
            4
        )
    );
}


function detectPhpCli():?string
{
    $list=[];


    if(
        defined('PHP_BINARY') &&
        PHP_BINARY
    ){

        if(
            strtolower(
                basename(PHP_BINARY)
            )==='php.exe' &&
            is_file(PHP_BINARY)
        ){
            $list[]=PHP_BINARY;
        }


        $sibling=
            dirname(PHP_BINARY).
            DIRECTORY_SEPARATOR.
            'php.exe';


        if(is_file($sibling))
            $list[]=$sibling;
    }


    if(DIRECTORY_SEPARATOR==='\\'){

        foreach(
            glob(
                'C:/wamp64/bin/php/php*/php.exe'
            )
            ?:[] as $php
        ){

            if(is_file($php))
                $list[]=$php;
        }

    }else{

        $which=
            trim(
                (string)@shell_exec(
                    'command -v php 2>/dev/null'
                )
            );


        if(
            $which &&
            is_file($which)
        ){
            $list[]=$which;
        }
    }


    $list=
        array_values(
            array_unique(
                $list
            )
        );


    if(!$list)
        return null;


    usort(
        $list,
        fn($a,$b)=>
            strnatcasecmp(
                $b,
                $a
            )
    );


    return $list[0];
}


function launchWorker(
    string $uuid
):array {

    $php=
        detectPhpCli();


    $worker=
        realpath(
            __DIR__.
            '/../../workers/student-import-worker.php'
        );


    if(!$php || !$worker){

        return [
            false,
            !$php
                ?'PHP CLI introuvable.'
                :'Worker d’import introuvable.'
        ];
    }


    if(DIRECTORY_SEPARATOR==='\\'){

        $php=
            str_replace(
                '"',
                '',
                $php
            );


        $worker=
            str_replace(
                '"',
                '',
                $worker
            );


        $uuid=
            preg_replace(
                '/[^a-f0-9-]/i',
                '',
                $uuid
            );


        $cmd=
            'cmd /C start "" /B "'.
            $php.
            '" "'.
            $worker.
            '" "'.
            $uuid.
            '" >NUL 2>&1';


        $handle=
            @popen(
                $cmd,
                'r'
            );


        if($handle===false){

            return [
                false,
                'Impossible de démarrer le worker Windows.'
            ];
        }


        @pclose(
            $handle
        );


        return [
            true,
            ''
        ];
    }


    @exec(
        escapeshellarg($php).
        ' '.
        escapeshellarg($worker).
        ' '.
        escapeshellarg($uuid).
        ' > /dev/null 2>&1 &'
    );


    return [
        true,
        ''
    ];
}


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


    /* =====================================================
       UNE SEULE IMPORTATION ACTIVE PAR UTILISATEUR
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT
            uuid,
            statut,
            progress,
            step_label

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


    $active=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if($active){

        launchWorker(
            $active['uuid']
        );


        jsonResponse(
            true,
            'Une importation est déjà en cours.',
            [
                'job_uuid'=>$active['uuid'],
                'statut'=>$active['statut'],
                'progress'=>(int)$active['progress'],
                'step_label'=>$active['step_label']
            ],
            202
        );
    }


    /* =====================================================
       ÉTABLISSEMENT + TYPE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT
            id,
            type_etablissement
        FROM etablissements
        WHERE id=?
        LIMIT 1
    ");


    $stmt->execute([
        $etablissementId
    ]);


    $etablissement=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if(!$etablissement){

        jsonResponse(
            false,
            'Établissement introuvable.',
            [],
            404
        );
    }


    $isUniversite=
        strtoupper(
            (string)$etablissement['type_etablissement']
        )==='UNIVERSITE';


    /* =====================================================
       ANNÉE AUTOMATIQUE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT
            id,
            libelle

        FROM annees_academiques

        WHERE etablissement_id=?
          AND actif=1

        ORDER BY
            CASE
                WHEN CURDATE() BETWEEN date_debut AND date_fin
                THEN 0
                ELSE 1
            END,
            date_debut DESC,
            id DESC

        LIMIT 1
    ");


    $stmt->execute([
        $etablissementId
    ]);


    $annee=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if(!$annee){

        jsonResponse(
            false,
            'Aucune année académique active.',
            [],
            422
        );
    }


    /* =====================================================
       HIÉRARCHIE ACADÉMIQUE
    ====================================================== */

    $faculteId=
        (int)(
            $_POST['faculte_id']
            ??0
        );


    $departementId=
        (int)(
            $_POST['departement_id']
            ??0
        );


    $promotionId=
        (int)(
            $_POST['promotion_id']
            ??0
        );


    if(!$promotionId){

        jsonResponse(
            false,
            'La promotion est obligatoire.',
            [],
            422
        );
    }


    if($isUniversite){

        if(
            !$faculteId ||
            !$departementId
        ){

            jsonResponse(
                false,
                'Faculté et département obligatoires.',
                [],
                422
            );
        }


        $stmt=$pdo->prepare("
            SELECT d.id

            FROM departements d

            INNER JOIN facultes fa
                ON fa.id=d.faculte_id
               AND fa.etablissement_id=?
               AND fa.actif=1

            WHERE d.id=?
              AND d.faculte_id=?
              AND d.etablissement_id=?
              AND d.actif=1

            LIMIT 1
        ");


        $stmt->execute([
            $etablissementId,
            $departementId,
            $faculteId,
            $etablissementId
        ]);


        if(!$stmt->fetchColumn()){

            jsonResponse(
                false,
                'Département invalide pour cette faculté.',
                [],
                422
            );
        }


        $stmt=$pdo->prepare("
            SELECT p.id

            FROM promotions p

            INNER JOIN filieres f
                ON f.id=p.filiere_id
               AND f.etablissement_id=?
               AND f.actif=1

            INNER JOIN departements d
                ON d.id=f.departement_id
               AND d.etablissement_id=?
               AND d.actif=1

            WHERE p.id=?
              AND p.etablissement_id=?
              AND p.actif=1
              AND d.id=?
              AND d.faculte_id=?

            LIMIT 1
        ");


        $stmt->execute([
            $etablissementId,
            $etablissementId,
            $promotionId,
            $etablissementId,
            $departementId,
            $faculteId
        ]);


        if(!$stmt->fetchColumn()){

            jsonResponse(
                false,
                'Promotion invalide pour ce département.',
                [],
                422
            );
        }

    }else{

        $stmt=$pdo->prepare("
            SELECT id
            FROM promotions
            WHERE id=?
              AND etablissement_id=?
              AND actif=1
            LIMIT 1
        ");


        $stmt->execute([
            $promotionId,
            $etablissementId
        ]);


        if(!$stmt->fetchColumn()){

            jsonResponse(
                false,
                'Promotion invalide.',
                [],
                422
            );
        }
    }


    /* =====================================================
       FICHIER
    ====================================================== */

    $file=
        $_FILES['file']
        ??null;


    if(
        !$file ||
        $file['error']!==UPLOAD_ERR_OK ||
        !is_uploaded_file($file['tmp_name'])
    ){

        jsonResponse(
            false,
            'Fichier d’importation invalide.',
            [],
            422
        );
    }


    if(
        (int)$file['size']<=0 ||
        (int)$file['size']>5*1024*1024
    ){

        jsonResponse(
            false,
            'Le fichier doit être compris entre 1 octet et 5 Mo.',
            [],
            422
        );
    }


    $ext=
        strtolower(
            pathinfo(
                $file['name'],
                PATHINFO_EXTENSION
            )
        );


    if(
        !in_array(
            $ext,
            [
                'xlsx',
                'xls',
                'csv'
            ],
            true
        )
    ){

        jsonResponse(
            false,
            'Format de fichier non autorisé.',
            [],
            422
        );
    }


    /* =====================================================
       STOCKAGE PRIVÉ
    ====================================================== */

    $uuid=
        importUuid();


    $storageRoot=
        __DIR__.
        '/../../storage/imports/student-imports';


    $dir=
        $storageRoot.
        '/'.
        $etablissementId;


    if(
        !is_dir($dir) &&
        !mkdir(
            $dir,
            0775,
            true
        ) &&
        !is_dir($dir)
    ){

        throw new RuntimeException(
            'Impossible de créer le dossier d’import.'
        );
    }


    if(
        !file_exists(
            $storageRoot.'/.htaccess'
        )
    ){

        @file_put_contents(
            $storageRoot.'/.htaccess',
            "Require all denied\n"
        );
    }


    $storedName=
        $uuid.
        '.'.
        $ext;


    $absolutePath=
        $dir.
        '/'.
        $storedName;


    if(
        !move_uploaded_file(
            $file['tmp_name'],
            $absolutePath
        )
    ){

        throw new RuntimeException(
            'Impossible de stocker le fichier importé.'
        );
    }


    $relativePath=
        'storage/imports/student-imports/'.
        $etablissementId.
        '/'.
        $storedName;


    /* =====================================================
       URL D'ACTIVATION
    ====================================================== */

    $scheme=
        (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS']!=='off'
        )
        ?'https'
        :'http';


    $host=
        preg_replace(
            '/[^a-zA-Z0-9.:\-\[\]]/',
            '',
            (string)(
                $_SERVER['HTTP_HOST']
                ??'localhost'
            )
        );


    $activationBaseUrl=
        $scheme.
        '://'.
        $host.
        BASE_URL;


    /* =====================================================
       CRÉER LE JOB
    ====================================================== */

    $payload=[
        'annee_academique_id'=>(int)$annee['id'],
        'promotion_id'=>$promotionId,
        'faculte_id'=>$faculteId,
        'departement_id'=>$departementId
    ];


    $stmt=$pdo->prepare("
        INSERT INTO student_import_jobs(
            uuid,
            etablissement_id,
            created_by_user_id,
            file_path,
            original_name,
            payload_json,
            activation_base_url,
            statut,
            progress,
            step_label
        )
        VALUES(
            ?,?,?,?,?,?,?,
            'PENDING',
            5,
            'Fichier reçu. Préparation...'
        )
    ");


    $stmt->execute([
        $uuid,
        $etablissementId,
        $userId?:null,
        $relativePath,
        basename(
            (string)$file['name']
        ),
        json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES
        ),
        $activationBaseUrl
    ]);


    [$started,$error]=
        launchWorker(
            $uuid
        );


    if(!$started){

        $pdo->prepare("
            UPDATE student_import_jobs
            SET
                statut='FAILED',
                step_label='Impossible de démarrer le traitement.',
                error_message=?,
                finished_at=NOW()
            WHERE uuid=?
        ")->execute([
            $error,
            $uuid
        ]);


        jsonResponse(
            false,
            'Le fichier a été reçu mais le traitement en arrière-plan n’a pas pu démarrer : '.$error,
            [
                'job_uuid'=>$uuid
            ],
            500
        );
    }


    jsonResponse(
        true,
        'Importation démarrée en arrière-plan.',
        [
            'job_uuid'=>$uuid,
            'statut'=>'PENDING',
            'progress'=>5,
            'step_label'=>'Fichier reçu. Préparation...',
            'total'=>0,
            'annee_academique'=>$annee['libelle']
        ],
        202
    );


}catch(Throwable $e){

    error_log(
        '[STUDENT IMPORT START] '.
        $e->getMessage()
    );


    jsonResponse(
        false,
        'Importation impossible : '.
        $e->getMessage(),
        [],
        500
    );
}

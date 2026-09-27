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


function enrollUuid():string
{
    $d=random_bytes(16);

    $d[6]=
        chr(
            (ord($d[6])&0x0f)|0x40
        );

    $d[8]=
        chr(
            (ord($d[8])&0x3f)|0x80
        );

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(
            bin2hex($d),
            4
        )
    );
}


function titleCaseFr(string $value):string
{
    $value=
        trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $value
            )
            ??''
        );

    return mb_convert_case(
        $value,
        MB_CASE_TITLE,
        'UTF-8'
    );
}


function detectPhpCli():?string
{
    $candidates=[];


    if(
        defined('PHP_BINARY') &&
        PHP_BINARY
    ){

        $sibling=
            dirname(PHP_BINARY).
            DIRECTORY_SEPARATOR.
            'php.exe';


        if(is_file($sibling))
            $candidates[]=$sibling;


        if(
            strtolower(
                basename(PHP_BINARY)
            )==='php.exe'
            &&
            is_file(PHP_BINARY)
        ){
            $candidates[]=PHP_BINARY;
        }
    }


    if(DIRECTORY_SEPARATOR==='\\'){

        foreach(
            glob(
                'C:/wamp64/bin/php/php*/php.exe'
            )
            ?:[] as $php
        ){

            if(is_file($php))
                $candidates[]=$php;
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
            $candidates[]=$which;
        }
    }


    $candidates=
        array_values(
            array_unique(
                $candidates
            )
        );


    if(!$candidates)
        return null;


    usort(
        $candidates,
        fn($a,$b)=>
            strnatcasecmp(
                $b,
                $a
            )
    );


    return $candidates[0];
}


function launchEnrollWorker(
    string $jobUuid
):array {

    $php=
        detectPhpCli();


    $worker=
        realpath(
            __DIR__.
            '/../../workers/student-enroll-worker.php'
        );


    if(!$php || !$worker){

        return [
            false,
            !$php
                ?'PHP CLI introuvable.'
                :'Worker inscription introuvable.'
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


        $jobUuid=
            preg_replace(
                '/[^a-f0-9-]/i',
                '',
                $jobUuid
            );


        $command=
            'cmd /C start "" /B "'.
            $php.
            '" "'.
            $worker.
            '" "'.
            $jobUuid.
            '" >NUL 2>&1';


        $handle=
            @popen(
                $command,
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


    $command=
        escapeshellarg($php).
        ' '.
        escapeshellarg($worker).
        ' '.
        escapeshellarg($jobUuid).
        ' > /dev/null 2>&1 &';


    @exec($command);


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
       EMPÊCHER 2 INSCRIPTIONS SIMULTANÉES PAR UTILISATEUR
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT
            uuid,
            statut,
            progress,
            step_label

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


    $active=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if($active){

        launchEnrollWorker(
            $active['uuid']
        );


        jsonResponse(
            true,
            'Une inscription est déjà en cours.',
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
       ANNÉE ACADÉMIQUE AUTOMATIQUE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT id,libelle

        FROM annees_academiques

        WHERE etablissement_id=?
          AND actif=1

        ORDER BY
            CASE
                WHEN CURDATE()
                     BETWEEN date_debut
                         AND date_fin
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
            'Aucune année académique active pour cet établissement.',
            [],
            422
        );
    }


    /* =====================================================
       TYPE D'ÉTABLISSEMENT
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT type_etablissement
        FROM etablissements
        WHERE id=?
        LIMIT 1
    ");

    $stmt->execute([
        $etablissementId
    ]);

    $etablissementType=
        strtoupper(
            (string)$stmt->fetchColumn()
        );

    $isUniversite=
        $etablissementType==='UNIVERSITE';


    /* =====================================================
       DONNÉES ET NORMALISATION
    ====================================================== */

    $mode=
        $_POST['mode']
        ??'new';


    if(
        !in_array(
            $mode,
            [
                'existing',
                'new'
            ],
            true
        )
    ){

        jsonResponse(
            false,
            'Mode d’inscription invalide.',
            [],
            422
        );
    }


    $studentId=
        (int)(
            $_POST['student_id']
            ??0
        );


    $promotionId=
        (int)(
            $_POST['promotion_id']
            ??0
        );


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
                'Faculté et département obligatoires pour une université.',
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
                'Département invalide pour la faculté sélectionnée.',
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
                'Promotion invalide pour le département sélectionné.',
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
                'Promotion invalide ou inactive.',
                [],
                422
            );
        }
    }


    $emailInstitutionnel=
        strtolower(
            trim(
                $_POST['email_institutionnel']
                ??''
            )
        );


    if(
        $emailInstitutionnel!=='' &&
        !filter_var(
            $emailInstitutionnel,
            FILTER_VALIDATE_EMAIL
        )
    ){

        jsonResponse(
            false,
            'E-mail institutionnel invalide.',
            [],
            422
        );
    }


    $dateInscription=
        trim(
            $_POST['date_inscription']
            ??''
        )
        ?:date('Y-m-d');


    $dateObject=
        DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $dateInscription
        );


    if(
        !$dateObject ||
        $dateObject->format('Y-m-d')!==$dateInscription
    ){

        jsonResponse(
            false,
            "Date d'inscription invalide.",
            [],
            422
        );
    }


    $payload=[
        'mode'=>$mode,
        'student_id'=>$studentId,
        'promotion_id'=>$promotionId,
        'faculte_id'=>$faculteId,
        'departement_id'=>$departementId,
        'email_institutionnel'=>$emailInstitutionnel,
        'date_inscription'=>$dateInscription,
        'annee_academique_id'=>(int)$annee['id']
    ];


    if($mode==='existing'){

        if(!$studentId){

            jsonResponse(
                false,
                'Sélectionnez d’abord un étudiant STAGIA.',
                [],
                422
            );
        }

    }else{

        $nom=
            mb_strtoupper(
                trim(
                    $_POST['nom']
                    ??''
                ),
                'UTF-8'
            );


        $postnom=
            mb_strtoupper(
                trim(
                    $_POST['postnom']
                    ??''
                ),
                'UTF-8'
            );


        $prenom=
            titleCaseFr(
                $_POST['prenom']
                ??''
            );


        $email=
            strtolower(
                trim(
                    $_POST['email']
                    ??''
                )
            );


        $telephone=
            trim(
                $_POST['telephone']
                ??''
            );


        $sexe=
            trim(
                $_POST['sexe']
                ??''
            );


        $dateNaissance=
            trim(
                $_POST['date_naissance']
                ??''
            );


        if($nom===''){

            jsonResponse(
                false,
                'Le nom de l’étudiant est obligatoire.',
                [],
                422
            );
        }


        if(
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ){

            jsonResponse(
                false,
                "L'adresse e-mail personnelle est obligatoire et doit être valide.",
                [],
                422
            );
        }


        if(
            $sexe!=='' &&
            !in_array(
                $sexe,
                [
                    'M',
                    'F'
                ],
                true
            )
        ){

            jsonResponse(
                false,
                'Sexe invalide.',
                [],
                422
            );
        }


        /* ===============================================
           REFUSER LES MINEURS
        ================================================ */

        if($dateNaissance!==''){

            $birth=
                DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    $dateNaissance
                );


            if(
                !$birth ||
                $birth->format('Y-m-d')!==$dateNaissance
            ){

                jsonResponse(
                    false,
                    'Date de naissance invalide.',
                    [],
                    422
                );
            }


            $today=
                new DateTimeImmutable(
                    'today'
                );


            if($birth>$today){

                jsonResponse(
                    false,
                    'La date de naissance ne peut pas être future.',
                    [],
                    422
                );
            }


            $age=
                $birth
                ->diff($today)
                ->y;


            if($age<18){

                jsonResponse(
                    false,
                    "Inscription refusée : l'étudiant doit avoir au moins 18 ans.",
                    [],
                    422
                );
            }
        }


        $payload+= [
            'nom'=>$nom,
            'postnom'=>$postnom,
            'prenom'=>$prenom,
            'sexe'=>$sexe,
            'date_naissance'=>$dateNaissance,
            'email'=>$email,
            'telephone'=>$telephone
        ];
    }


    /* =====================================================
       URL ABSOLUE POUR L'ACTIVATION
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

    $uuid=
        enrollUuid();


    $stmt=$pdo->prepare("
        INSERT INTO student_enrollment_jobs(
            uuid,
            etablissement_id,
            created_by_user_id,
            payload_json,
            activation_base_url,
            statut,
            progress,
            step_label
        )
        VALUES(
            ?,?,?,?,?,
            'PENDING',
            5,
            'Inscription mise en file d’attente.'
        )
    ");


    $stmt->execute([
        $uuid,
        $etablissementId,
        $userId?:null,
        json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES
        ),
        $activationBaseUrl
    ]);


    [$started,$error]=
        launchEnrollWorker(
            $uuid
        );


    if(!$started){

        $stmt=$pdo->prepare("
            UPDATE student_enrollment_jobs
            SET
                statut='FAILED',
                error_message=?,
                step_label='Impossible de démarrer le traitement.',
                finished_at=NOW()
            WHERE uuid=?
        ");


        $stmt->execute([
            $error,
            $uuid
        ]);


        jsonResponse(
            false,
            'La tâche a été créée mais le traitement en arrière-plan n’a pas pu démarrer : '.$error,
            [
                'job_uuid'=>$uuid
            ],
            500
        );
    }


    jsonResponse(
        true,
        'Inscription démarrée en arrière-plan.',
        [
            'job_uuid'=>$uuid,
            'statut'=>'PENDING',
            'progress'=>5,
            'step_label'=>'Inscription mise en file d’attente.',
            'annee_academique'=>$annee['libelle']
        ],
        202
    );


}catch(Throwable $e){

    error_log(
        '[STUDENT ENROLL START] '.
        $e->getMessage()
    );


    jsonResponse(
        false,
        'Impossible de démarrer l’inscription : '.
        $e->getMessage(),
        [],
        500
    );
}

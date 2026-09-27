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


$jobUuid=
    trim(
        $argv[1]
        ??''
    );


if(
    !preg_match(
        '/^[a-f0-9-]{36}$/i',
        $jobUuid
    )
){
    exit(2);
}


function jobStep(
    PDO $pdo,
    int $jobId,
    int $progress,
    string $label
):void {

    $stmt=$pdo->prepare("
        UPDATE student_enrollment_jobs
        SET
            progress=?,
            step_label=?,
            heartbeat_at=NOW()
        WHERE id=?
    ");


    $stmt->execute([
        $progress,
        $label,
        $jobId
    ]);
}


function finishJob(
    PDO $pdo,
    int $jobId,
    string $status,
    string $message,
    array $result=[]
):void {

    $stmt=$pdo->prepare("
        UPDATE student_enrollment_jobs
        SET
            statut=?,
            progress=100,
            step_label=?,
            message=?,
            result_json=?,
            finished_at=NOW(),
            heartbeat_at=NOW()
        WHERE id=?
    ");


    $stmt->execute([
        $status,
        $message,
        $message,
        json_encode(
            $result,
            JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES
        ),
        $jobId
    ]);
}


function failJob(
    PDO $pdo,
    int $jobId,
    Throwable $e
):void {

    $message=
        mb_substr(
            $e->getMessage(),
            0,
            4000
        );


    $stmt=$pdo->prepare("
        UPDATE student_enrollment_jobs
        SET
            statut='FAILED',
            step_label='Inscription interrompue.',
            message='Échec de l’inscription.',
            error_message=?,
            finished_at=NOW(),
            heartbeat_at=NOW()
        WHERE id=?
    ");


    $stmt->execute([
        $message,
        $jobId
    ]);
}


try{

    $lockName=
        'stagia_enroll_worker_'.
        $jobUuid;


    $stmt=$pdo->prepare(
        "SELECT GET_LOCK(?,0)"
    );


    $stmt->execute([
        $lockName
    ]);


    if(
        (int)$stmt->fetchColumn()!==1
    ){
        exit(0);
    }


    try{

        $stmt=$pdo->prepare("
            SELECT *
            FROM student_enrollment_jobs
            WHERE uuid=?
            LIMIT 1
        ");


        $stmt->execute([
            $jobUuid
        ]);


        $job=
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if(!$job)
            exit(3);


        if(
            in_array(
                $job['statut'],
                [
                    'COMPLETED',
                    'COMPLETED_WITH_WARNING',
                    'FAILED'
                ],
                true
            )
        ){
            exit(0);
        }


        $payload=
            json_decode(
                $job['payload_json'],
                true
            );


        if(!is_array($payload)){

            throw new RuntimeException(
                'Données de la tâche invalides.'
            );
        }


        $stmt=$pdo->prepare("
            UPDATE student_enrollment_jobs
            SET
                statut='RUNNING',
                progress=10,
                step_label='Validation des données...',
                started_at=COALESCE(
                    started_at,
                    NOW()
                ),
                heartbeat_at=NOW(),
                error_message=NULL
            WHERE id=?
        ");


        $stmt->execute([
            $job['id']
        ]);


        $etablissementId=
            (int)$job['etablissement_id'];


        /* =================================================
           ÉTABLISSEMENT
        ================================================== */

        $stmt=$pdo->prepare("
            SELECT
                id,
                code,
                nom,
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

            throw new RuntimeException(
                'Établissement introuvable.'
            );
        }


        /* =================================================
           ANNÉE AUTOMATIQUE, REVALIDÉE CÔTÉ WORKER
        ================================================== */

        $stmt=$pdo->prepare("
            SELECT
                id,
                libelle
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

            throw new RuntimeException(
                'Aucune année académique active.'
            );
        }


        $anneeId=
            (int)$annee['id'];


        $promotionId=
            (int)(
                $payload['promotion_id']
                ??0
            );


        $faculteId=
            (int)(
                $payload['faculte_id']
                ??0
            );


        $departementId=
            (int)(
                $payload['departement_id']
                ??0
            );


        $isUniversite=
            strtoupper(
                (string)(
                    $etablissement['type_etablissement']
                    ??''
                )
            )==='UNIVERSITE';


        if($isUniversite){

            if(
                !$faculteId ||
                !$departementId ||
                !$promotionId
            ){

                throw new RuntimeException(
                    'Faculté, département et promotion obligatoires pour une université.'
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

                throw new RuntimeException(
                    'Département invalide pour la faculté sélectionnée.'
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

                throw new RuntimeException(
                    'Promotion invalide pour le département sélectionné.'
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

                throw new RuntimeException(
                    'Promotion invalide ou inactive.'
                );
            }
        }


        /* =================================================
           RÔLE STAGIAIRE
        ================================================== */

        $stmt=$pdo->query("
            SELECT id
            FROM roles
            WHERE code='STAGIAIRE'
            LIMIT 1
        ");


        $roleId=
            (int)$stmt->fetchColumn();


        if(!$roleId){

            throw new RuntimeException(
                'Le rôle STAGIAIRE est introuvable.'
            );
        }


        jobStep(
            $pdo,
            (int)$job['id'],
            25,
            'Préparation du profil étudiant...'
        );


        $pdo->beginTransaction();


        $mode=
            $payload['mode']
            ??'new';


        $studentId=
            (int)(
                $payload['student_id']
                ??0
            );


        $accountCreated=
            false;

        $mailSent=
            null;

        $token=
            null;

        $expiration=
            null;


        /* =================================================
           ÉTUDIANT EXISTANT
        ================================================== */

        if($mode==='existing'){

            if(!$studentId){

                throw new RuntimeException(
                    'Étudiant STAGIA non sélectionné.'
                );
            }


            $stmt=$pdo->prepare("
                SELECT
                    id,
                    user_id,
                    stagia_code,
                    nom,
                    postnom,
                    prenom,
                    email,
                    telephone

                FROM student_profiles

                WHERE id=?
                  AND statut<>'ARCHIVE'

                LIMIT 1

                FOR UPDATE
            ");


            $stmt->execute([
                $studentId
            ]);


            $student=
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if(!$student){

                throw new RuntimeException(
                    'Profil étudiant introuvable.'
                );
            }


            $stmt=$pdo->prepare("
                SELECT id
                FROM student_enrollments
                WHERE student_id=?
                  AND etablissement_id=?
                LIMIT 1
            ");


            $stmt->execute([
                $studentId,
                $etablissementId
            ]);


            if($stmt->fetchColumn()){

                throw new RuntimeException(
                    'Cet étudiant est déjà rattaché à votre établissement.'
                );
            }


            if(
                empty(
                    $student['stagia_code']
                )
            ){

                $student['stagia_code']=
                    'STG-ETU-'.
                    str_pad(
                        (string)$studentId,
                        8,
                        '0',
                        STR_PAD_LEFT
                    );


                $pdo->prepare("
                    UPDATE student_profiles
                    SET stagia_code=?
                    WHERE id=?
                ")->execute([
                    $student['stagia_code'],
                    $studentId
                ]);
            }


        /* =================================================
           NOUVEAU PROFIL
        ================================================== */

        }else{

            $nom=
                mb_strtoupper(
                    trim(
                        (string)(
                            $payload['nom']
                            ??''
                        )
                    ),
                    'UTF-8'
                );


            $postnom=
                mb_strtoupper(
                    trim(
                        (string)(
                            $payload['postnom']
                            ??''
                        )
                    ),
                    'UTF-8'
                );


            $prenom=
                mb_convert_case(
                    trim(
                        (string)(
                            $payload['prenom']
                            ??''
                        )
                    ),
                    MB_CASE_TITLE,
                    'UTF-8'
                );


            $sexe=
                trim(
                    (string)(
                        $payload['sexe']
                        ??''
                    )
                );


            $dateNaissance=
                trim(
                    (string)(
                        $payload['date_naissance']
                        ??''
                    )
                );


            $email=
                strtolower(
                    trim(
                        (string)(
                            $payload['email']
                            ??''
                        )
                    )
                );


            $telephone=
                trim(
                    (string)(
                        $payload['telephone']
                        ??''
                    )
                );


            if($nom===''){

                throw new RuntimeException(
                    'Le nom de l’étudiant est obligatoire.'
                );
            }


            if(
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ){

                throw new RuntimeException(
                    "L'adresse e-mail personnelle est obligatoire et doit être valide."
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

                throw new RuntimeException(
                    'Sexe invalide.'
                );
            }


            /* =============================================
               CONTRÔLE 18 ANS CÔTÉ SERVEUR
            ============================================== */

            if($dateNaissance!==''){

                $birth=
                    DateTimeImmutable::createFromFormat(
                        '!Y-m-d',
                        $dateNaissance
                    );


                if(
                    !$birth ||
                    $birth->format(
                        'Y-m-d'
                    )!==$dateNaissance
                ){

                    throw new RuntimeException(
                        'Date de naissance invalide.'
                    );
                }


                $today=
                    new DateTimeImmutable(
                        'today'
                    );


                if($birth>$today){

                    throw new RuntimeException(
                        'La date de naissance ne peut pas être future.'
                    );
                }


                if(
                    $birth
                    ->diff($today)
                    ->y<18
                ){

                    throw new RuntimeException(
                        "Inscription refusée : l'étudiant doit avoir au moins 18 ans."
                    );
                }
            }


            $stmt=$pdo->prepare("
                SELECT id
                FROM student_profiles
                WHERE LOWER(TRIM(email))=
                      LOWER(TRIM(?))
                LIMIT 1
            ");


            $stmt->execute([
                $email
            ]);


            if($stmt->fetchColumn()){

                throw new RuntimeException(
                    'Un étudiant STAGIA possède déjà cette adresse e-mail. Recherchez-le au lieu de créer un nouveau profil.'
                );
            }


            $stmt=$pdo->prepare("
                SELECT id
                FROM users
                WHERE LOWER(TRIM(email))=
                      LOWER(TRIM(?))
                LIMIT 1
            ");


            $stmt->execute([
                $email
            ]);


            if($stmt->fetchColumn()){

                throw new RuntimeException(
                    'Cette adresse e-mail est déjà utilisée par un compte STAGIA.'
                );
            }


            $d=
                random_bytes(16);

            $d[6]=
                chr(
                    (ord($d[6])&0x0f)|0x40
                );

            $d[8]=
                chr(
                    (ord($d[8])&0x3f)|0x80
                );

            $profileUuid=
                vsprintf(
                    '%s%s-%s-%s-%s-%s%s%s',
                    str_split(
                        bin2hex($d),
                        4
                    )
                );


            $stmt=$pdo->prepare("
                INSERT INTO student_profiles(
                    uuid,
                    nom,
                    postnom,
                    prenom,
                    sexe,
                    date_naissance,
                    email,
                    telephone,
                    statut
                )
                VALUES(
                    ?,?,?,?,?,?,?,?,
                    'ACTIF'
                )
            ");


            $stmt->execute([
                $profileUuid,
                $nom,
                $postnom?:null,
                $prenom?:null,
                $sexe?:null,
                $dateNaissance?:null,
                $email,
                $telephone?:null
            ]);


            $studentId=
                (int)$pdo->lastInsertId();


            $stagiaCode=
                'STG-ETU-'.
                str_pad(
                    (string)$studentId,
                    8,
                    '0',
                    STR_PAD_LEFT
                );


            $pdo->prepare("
                UPDATE student_profiles
                SET stagia_code=?
                WHERE id=?
            ")->execute([
                $stagiaCode,
                $studentId
            ]);


            $student=[
                'id'=>$studentId,
                'user_id'=>null,
                'stagia_code'=>$stagiaCode,
                'nom'=>$nom,
                'postnom'=>$postnom,
                'prenom'=>$prenom,
                'email'=>$email,
                'telephone'=>$telephone
            ];
        }


        jobStep(
            $pdo,
            (int)$job['id'],
            50,
            'Création de l’inscription académique...'
        );


        /* =================================================
           INSCRIPTION ÉTABLISSEMENT
        ================================================== */

        $tempMatricule=
            'TMP-'.
            strtoupper(
                bin2hex(
                    random_bytes(8)
                )
            );


        $stmt=$pdo->prepare("
            INSERT INTO student_enrollments(
                student_id,
                etablissement_id,
                matricule,
                email_institutionnel,
                date_inscription
            )
            VALUES(
                ?,?,?,?,?
            )
        ");


        $stmt->execute([
            $studentId,
            $etablissementId,
            $tempMatricule,
            !empty(
                $payload['email_institutionnel']
            )
                ?$payload['email_institutionnel']
                :null,
            $payload['date_inscription']
                ?:date('Y-m-d')
        ]);


        $enrollmentId=
            (int)$pdo->lastInsertId();


        preg_match(
            '/\d{4}/',
            $annee['libelle'],
            $yearMatch
        );


        $year=
            $yearMatch[0]
            ??date('Y');


        $etabCode=
            strtoupper(
                preg_replace(
                    '/[^A-Z0-9]/i',
                    '',
                    $etablissement['code']
                    ?:'ETB'
                )
            );


        $etabCode=
            substr(
                $etabCode
                ?:'ETB',
                0,
                12
            );


        $matricule=
            $etabCode.
            '-ETU-'.
            $year.
            '-'.
            str_pad(
                (string)$enrollmentId,
                6,
                '0',
                STR_PAD_LEFT
            );


        $stmt=$pdo->prepare("
            SELECT id
            FROM student_enrollments
            WHERE etablissement_id=?
              AND matricule=?
              AND id<>?
            LIMIT 1
        ");


        $stmt->execute([
            $etablissementId,
            $matricule,
            $enrollmentId
        ]);


        if($stmt->fetchColumn()){

            throw new RuntimeException(
                'Impossible de générer un matricule unique.'
            );
        }


        $pdo->prepare("
            UPDATE student_enrollments
            SET matricule=?
            WHERE id=?
        ")->execute([
            $matricule,
            $enrollmentId
        ]);


        $stmt=$pdo->prepare("
            INSERT INTO student_academic_enrollments(
                enrollment_id,
                annee_academique_id,
                promotion_id
            )
            VALUES(
                ?,?,?
            )
        ");


        $stmt->execute([
            $enrollmentId,
            $anneeId,
            $promotionId
        ]);


        jobStep(
            $pdo,
            (int)$job['id'],
            70,
            'Préparation du compte étudiant...'
        );


        /* =================================================
           CRÉER LE COMPTE SI NÉCESSAIRE
        ================================================== */

        if(
            empty(
                $student['user_id']
            )
        ){

            $accountEmail=
                strtolower(
                    trim(
                        $student['email']
                        ?:
                        (
                            $payload['email_institutionnel']
                            ??''
                        )
                    )
                );


            if(
                !filter_var(
                    $accountEmail,
                    FILTER_VALIDATE_EMAIL
                )
            ){

                throw new RuntimeException(
                    "Une adresse e-mail valide est nécessaire pour créer le compte de l'étudiant."
                );
            }


            $stmt=$pdo->prepare("
                SELECT id
                FROM users
                WHERE LOWER(TRIM(email))=
                      LOWER(TRIM(?))
                LIMIT 1
            ");


            $stmt->execute([
                $accountEmail
            ]);


            if($stmt->fetchColumn()){

                throw new RuntimeException(
                    'Cette adresse e-mail est déjà utilisée par un compte STAGIA.'
                );
            }


            $identifiant=
                $student['stagia_code'];


            $stmt=$pdo->prepare("
                SELECT id
                FROM users
                WHERE identifiant=?
                LIMIT 1
            ");


            $stmt->execute([
                $identifiant
            ]);


            if($stmt->fetchColumn()){

                throw new RuntimeException(
                    'Le code STAGIA possède déjà un compte utilisateur.'
                );
            }


            $token=
                bin2hex(
                    random_bytes(32)
                );


            $tokenHash=
                hash(
                    'sha256',
                    $token
                );


            $expiration=
                date(
                    'Y-m-d H:i:s',
                    time()+(48*3600)
                );


            $passwordHash=
                password_hash(
                    bin2hex(
                        random_bytes(32)
                    ),
                    PASSWORD_DEFAULT
                );


            $stmt=$pdo->prepare("
                INSERT INTO users(
                    role_id,
                    nom,
                    postnom,
                    prenom,
                    email,
                    identifiant,
                    password,
                    telephone,
                    actif,
                    statut_compte,
                    activation_token_hash,
                    activation_expire_at
                )
                VALUES(
                    ?,?,?,?,?,?,?,?,
                    0,
                    'A_ACTIVER',
                    ?,?
                )
            ");


            $stmt->execute([
                $roleId,
                $student['nom'],
                $student['postnom']?:null,
                $student['prenom']?:null,
                $accountEmail,
                $identifiant,
                $passwordHash,
                $student['telephone']?:null,
                $tokenHash,
                $expiration
            ]);


            $userId=
                (int)$pdo->lastInsertId();


            $pdo->prepare("
                UPDATE student_profiles
                SET
                    user_id=?,
                    email=?,
                    updated_at=NOW()
                WHERE id=?
            ")->execute([
                $userId,
                $accountEmail,
                $studentId
            ]);


            $student['user_id']=
                $userId;


            $student['email']=
                $accountEmail;


            $accountCreated=
                true;
        }


        $pdo->commit();


        /* =================================================
           E-MAIL APRÈS COMMIT
        ================================================== */

        if(
            $accountCreated &&
            $token
        ){

            jobStep(
                $pdo,
                (int)$job['id'],
                85,
                'Envoi de l’invitation par e-mail...'
            );


            $activationUrl=
                rtrim(
                    $job['activation_base_url'],
                    '/'
                ).
                '/activate.php?token='.
                urlencode(
                    $token
                );


            $nomComplet=
                trim(
                    implode(
                        ' ',
                        array_filter([
                            $student['prenom']??'',
                            $student['nom']??'',
                            $student['postnom']??''
                        ])
                    )
                );


            $mailSent=
                MailService::envoyerActivation(
                    $student['email'],
                    $nomComplet,
                    $activationUrl
                );
        }


        $result=[
            'student_id'=>$studentId,
            'enrollment_id'=>$enrollmentId,
            'stagia_code'=>$student['stagia_code'],
            'matricule'=>$matricule,
            'user_id'=>$student['user_id']?:null,
            'account_created'=>$accountCreated,
            'email'=>$student['email']??null,
            'mail_sent'=>$mailSent,
            'activation_expires_at'=>$expiration,
            'annee_academique'=>$annee['libelle']
        ];


        if(
            $accountCreated &&
            $mailSent===false
        ){

            finishJob(
                $pdo,
                (int)$job['id'],
                'COMPLETED_WITH_WARNING',
                "Étudiant inscrit, mais l'e-mail d'activation n'a pas pu être envoyé.",
                $result
            );

        }else{

            finishJob(
                $pdo,
                (int)$job['id'],
                'COMPLETED',
                $accountCreated
                    ?'Étudiant inscrit. Le lien d’activation a été envoyé par e-mail.'
                    :'Étudiant inscrit avec succès.',
                $result
            );
        }


    }catch(Throwable $e){

        if(
            isset($pdo) &&
            $pdo->inTransaction()
        ){
            $pdo->rollBack();
        }


        if(
            isset($job) &&
            isset($job['id'])
        ){

            failJob(
                $pdo,
                (int)$job['id'],
                $e
            );
        }


        error_log(
            '[STUDENT ENROLL WORKER] '.
            $e->getMessage()
        );


    }finally{

        try{

            $stmt=$pdo->prepare(
                "SELECT RELEASE_LOCK(?)"
            );


            $stmt->execute([
                $lockName
            ]);

        }catch(Throwable $ignored){}
    }


}catch(Throwable $e){

    error_log(
        '[STUDENT ENROLL WORKER FATAL] '.
        $e->getMessage()
    );

    exit(1);
}

exit(0);

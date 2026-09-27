<?php

if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;


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


function uuidV4():string
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


function step(
    PDO $pdo,
    int $jobId,
    int $progress,
    string $label,
    ?string $student=null
):void {

    $stmt=$pdo->prepare("
        UPDATE student_import_jobs
        SET
            progress=?,
            step_label=?,
            current_student=?,
            heartbeat_at=NOW()
        WHERE id=?
    ");


    $stmt->execute([
        $progress,
        $label,
        $student,
        $jobId
    ]);
}


function failJob(
    PDO $pdo,
    int $jobId,
    string $error
):void {

    $stmt=$pdo->prepare("
        UPDATE student_import_jobs
        SET
            statut='FAILED',
            step_label='Importation interrompue.',
            message='Importation interrompue.',
            error_message=?,
            current_student=NULL,
            finished_at=NOW(),
            heartbeat_at=NOW()
        WHERE id=?
    ");


    $stmt->execute([
        mb_substr(
            $error,
            0,
            4000
        ),
        $jobId
    ]);
}


function normalizeHeader($value):string
{
    $value=
        trim(
            (string)$value
        );


    $value=
        preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $value
        );


    return strtolower(
        trim(
            $value
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


function normalizeBirthDate($value):?string
{
    if(
        $value===null ||
        trim((string)$value)===''
    ){
        return null;
    }


    if(
        is_numeric($value) &&
        (float)$value>1000
    ){

        try{

            return ExcelDate::excelToDateTimeObject(
                (float)$value
            )->format('Y-m-d');

        }catch(Throwable $ignored){}
    }


    $value=
        trim(
            (string)$value
        );


    foreach(
        [
            'Y-m-d',
            'd/m/Y',
            'd-m-Y',
            'Y/m/d'
        ] as $format
    ){

        $date=
            DateTimeImmutable::createFromFormat(
                '!'.$format,
                $value
            );


        if(
            $date &&
            $date->format($format)===$value
        ){

            return $date->format(
                'Y-m-d'
            );
        }
    }


    throw new RuntimeException(
        'Date de naissance invalide.'
    );
}


function assertAdult(?string $birthDate):void
{
    if(!$birthDate)
        return;


    $birth=
        new DateTimeImmutable(
            $birthDate
        );


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
            "Étudiant mineur : l'âge minimum est de 18 ans."
        );
    }
}


function recompute(
    PDO $pdo,
    int $jobId
):array {

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
        $jobId
    ]);


    $x=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?:[];


    foreach(
        [
            'total',
            'processed',
            'imported',
            'failed',
            'remaining'
        ] as $key
    ){
        $x[$key]=
            (int)(
                $x[$key]
                ??0
            );
    }


    return $x;
}


try{

    $lockName=
        'stagia_student_import_'.
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

        /* =================================================
           JOB
        ================================================== */

        $stmt=$pdo->prepare("
            SELECT *
            FROM student_import_jobs
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
                    'COMPLETED_WITH_ERRORS'
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
                'Paramètres du job invalides.'
            );
        }


        $etablissementId=
            (int)$job['etablissement_id'];


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


        $isUniversite=
            strtoupper(
                (string)$etablissement['type_etablissement']
            )==='UNIVERSITE';


        /* =================================================
           ANNÉE + PROMOTION REVALIDÉES
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


        if($isUniversite){

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

                INNER JOIN facultes fa
                    ON fa.id=d.faculte_id
                   AND fa.etablissement_id=?
                   AND fa.actif=1

                WHERE p.id=?
                  AND p.etablissement_id=?
                  AND p.actif=1
                  AND d.id=?
                  AND fa.id=?

                LIMIT 1
            ");


            $stmt->execute([
                $etablissementId,
                $etablissementId,
                $etablissementId,
                $promotionId,
                $etablissementId,
                $departementId,
                $faculteId
            ]);


            if(!$stmt->fetchColumn()){

                throw new RuntimeException(
                    'Parcours Faculté → Département → Promotion invalide.'
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
                    'Promotion invalide.'
                );
            }
        }
/* =================================================
           FICHIER PRIVÉ
        ================================================== */

        $projectRoot=
            realpath(
                __DIR__.
                '/..'
            );


        $storageRoot=
            realpath(
                $projectRoot.
                '/storage/imports/student-imports'
            );


        $file=
            realpath(
                $projectRoot.
                '/'.
                $job['file_path']
            );


        if(
            !$storageRoot ||
            !$file ||
            !is_file($file) ||
            strpos(
                $file,
                $storageRoot.
                DIRECTORY_SEPARATOR
            )!==0
        ){

            throw new RuntimeException(
                'Fichier d’import introuvable.'
            );
        }


        /* =================================================
           PRÉPARER LES LIGNES SI PREMIER PASSAGE
        ================================================== */

        $stmt=$pdo->prepare("
            SELECT COUNT(*)
            FROM student_import_job_items
            WHERE job_id=?
        ");


        $stmt->execute([
            $job['id']
        ]);


        $existingItems=
            (int)$stmt->fetchColumn();


        if($existingItems===0){

            $pdo->prepare("
                UPDATE student_import_jobs
                SET
                    statut='RUNNING',
                    progress=8,
                    step_label='Lecture du fichier...',
                    started_at=COALESCE(
                        started_at,
                        NOW()
                    ),
                    heartbeat_at=NOW()
                WHERE id=?
            ")->execute([
                $job['id']
            ]);


            $spreadsheet=
                IOFactory::load(
                    $file
                );


            $rows=
                $spreadsheet
                ->getActiveSheet()
                ->toArray(
                    null,
                    true,
                    true,
                    false
                );


            if(count($rows)<2){

                throw new RuntimeException(
                    'Le fichier ne contient aucun étudiant.'
                );
            }


            $headers=
                array_map(
                    'normalizeHeader',
                    array_shift($rows)
                );


            foreach(
                [
                    'nom',
                    'email'
                ] as $required
            ){

                if(
                    !in_array(
                        $required,
                        $headers,
                        true
                    )
                ){

                    throw new RuntimeException(
                        'Colonne obligatoire manquante : '.
                        $required
                    );
                }
            }


            $index=
                array_flip(
                    $headers
                );


            $insert=
                $pdo->prepare("
                    INSERT INTO student_import_job_items(
                        job_id,
                        source_row,
                        data_json,
                        statut
                    )
                    VALUES(
                        ?,?,?,
                        'PENDING'
                    )
                ");


            foreach(
                $rows as $offset=>$row
            ){

                $data=[];


                foreach(
                    [
                        'nom',
                        'postnom',
                        'prenom',
                        'sexe',
                        'date_naissance',
                        'email',
                        'telephone',
                        'email_institutionnel'
                    ] as $field
                ){

                    $data[$field]=
                        isset(
                            $index[$field]
                        )
                        ?(
                            $row[
                                $index[$field]
                            ]
                            ??''
                        )
                        :'';
                }


                $hasData=false;


                foreach(
                    $data as $value
                ){

                    if(
                        trim(
                            (string)$value
                        )!==''
                    ){

                        $hasData=true;

                        break;
                    }
                }


                if(!$hasData)
                    continue;


                $insert->execute([
                    $job['id'],
                    $offset+2,
                    json_encode(
                        $data,
                        JSON_UNESCAPED_UNICODE|
                        JSON_UNESCAPED_SLASHES
                    )
                ]);
            }


            $stats=
                recompute(
                    $pdo,
                    (int)$job['id']
                );


            if($stats['total']===0){

                throw new RuntimeException(
                    'Aucune ligne étudiante exploitable.'
                );
            }


            $pdo->prepare("
                UPDATE student_import_jobs
                SET
                    total_count=?,
                    progress=10,
                    step_label='Fichier prêt. Importation des étudiants...',
                    heartbeat_at=NOW()
                WHERE id=?
            ")->execute([
                $stats['total'],
                $job['id']
            ]);
        }


        /* =================================================
           PRÉPARATION MATRICULE
        ================================================== */

        preg_match(
            '/\d{4}/',
            $annee['libelle'],
            $match
        );


        $year=
            $match[0]
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


        /* =================================================
           BOUCLE ITEMS
        ================================================== */

        while(true){

            $pdo->beginTransaction();


            $stmt=$pdo->prepare("
                SELECT *
                FROM student_import_job_items
                WHERE job_id=?
                  AND statut='PENDING'
                ORDER BY id
                LIMIT 1
                FOR UPDATE
            ");


            $stmt->execute([
                $job['id']
            ]);


            $item=
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if(!$item){

                $pdo->commit();

                break;
            }


            $pdo->prepare("
                UPDATE student_import_job_items
                SET
                    statut='RUNNING',
                    started_at=COALESCE(
                        started_at,
                        NOW()
                    ),
                    error_message=NULL
                WHERE id=?
            ")->execute([
                $item['id']
            ]);


            $pdo->commit();


            $data=
                json_decode(
                    $item['data_json'],
                    true
                );


            if(!is_array($data))
                $data=[];


            $namePreview=
                trim(
                    implode(
                        ' ',
                        array_filter([
                            $data['nom']??'',
                            $data['postnom']??'',
                            $data['prenom']??''
                        ])
                    )
                );


            $stats=
                recompute(
                    $pdo,
                    (int)$job['id']
                );


            $progress=
                $stats['total']>0
                ?max(
                    10,
                    (int)round(
                        $stats['processed']*
                        90/
                        $stats['total']
                    )
                )
                :10;


            step(
                $pdo,
                (int)$job['id'],
                min(99,$progress),
                'Importation des étudiants...',
                $namePreview
                ?:(
                    'Ligne '.
                    $item['source_row']
                )
            );


            try{

                /* =========================================
                   NORMALISATION
                ========================================== */

                $nom=
                    mb_strtoupper(
                        trim(
                            (string)(
                                $data['nom']
                                ??''
                            )
                        ),
                        'UTF-8'
                    );


                $postnom=
                    mb_strtoupper(
                        trim(
                            (string)(
                                $data['postnom']
                                ??''
                            )
                        ),
                        'UTF-8'
                    );


                $prenom=
                    titleCaseFr(
                        (string)(
                            $data['prenom']
                            ??''
                        )
                    );


                $sexe=
                    strtoupper(
                        trim(
                            (string)(
                                $data['sexe']
                                ??''
                            )
                        )
                    );


                $dateNaissance=
                    normalizeBirthDate(
                        $data['date_naissance']
                        ??''
                    );


                $email=
                    strtolower(
                        trim(
                            (string)(
                                $data['email']
                                ??''
                            )
                        )
                    );


                $telephone=
                    trim(
                        (string)(
                            $data['telephone']
                            ??''
                        )
                    );


                $emailInstitutionnel=
                    strtolower(
                        trim(
                            (string)(
                                $data['email_institutionnel']
                                ??''
                            )
                        )
                    );


                if($nom===''){

                    throw new RuntimeException(
                        'Nom obligatoire.'
                    );
                }


                if(
                    !filter_var(
                        $email,
                        FILTER_VALIDATE_EMAIL
                    )
                ){

                    throw new RuntimeException(
                        'E-mail personnel invalide.'
                    );
                }


                if(
                    $emailInstitutionnel!=='' &&
                    !filter_var(
                        $emailInstitutionnel,
                        FILTER_VALIDATE_EMAIL
                    )
                ){

                    throw new RuntimeException(
                        'E-mail institutionnel invalide.'
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


                assertAdult(
                    $dateNaissance
                );


                /* =========================================
                   DOUBLONS
                ========================================== */

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
                        'Cette adresse e-mail existe déjà dans STAGIA.'
                    );
                }
/* =========================================
                   TRANSACTION MÉTIER
                ========================================== */

                $pdo->beginTransaction();


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
                    uuidV4(),
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


                if($studentId<=0){

                    throw new RuntimeException(
                        "student_profiles.id n'a pas généré d'identifiant AUTO_INCREMENT valide. ".
                        "Vérifiez la structure de student_profiles."
                    );
                }


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


                $temp=
                    'TMP-'.
                    strtoupper(
                        bin2hex(
                            random_bytes(6)
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
                        ?,?,?,?,
                        CURDATE()
                    )
                ");


                $stmt->execute([
                    $studentId,
                    $etablissementId,
                    $temp,
                    $emailInstitutionnel?:null
                ]);


                $enrollmentId=
                    (int)$pdo->lastInsertId();


                if($enrollmentId<=0){

                    throw new RuntimeException(
                        "student_enrollments.id n'a pas généré d'identifiant AUTO_INCREMENT valide."
                    );
                }


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


                $pdo->prepare("
                    UPDATE student_enrollments
                    SET matricule=?
                    WHERE id=?
                ")->execute([
                    $matricule,
                    $enrollmentId
                ]);


                $pdo->prepare("
                    INSERT INTO student_academic_enrollments(
                        enrollment_id,
                        annee_academique_id,
                        promotion_id
                    )
                    VALUES(
                        ?,?,?
                    )
                ")->execute([
                    $enrollmentId,
                    $anneeId,
                    $promotionId
                ]);


                $pdo->commit();


                /* =========================================
                   IMPORT UNIQUEMENT
                   Le compte et l'invitation sont gérés
                   séparément depuis la liste des étudiants.
                ========================================== */

                $stmt=$pdo->prepare("
                    UPDATE student_import_job_items
                    SET
                        statut='IMPORTED',
                        student_id=?,
                        enrollment_id=?,
                        email_sent=NULL,
                        error_message=NULL,
                        finished_at=NOW()
                    WHERE id=?
                ");


                $stmt->execute([
                    $studentId,
                    $enrollmentId,
                    $item['id']
                ]);


            }catch(Throwable $e){

                if(
                    $pdo->inTransaction()
                ){
                    $pdo->rollBack();
                }


                $pdo->prepare("
                    UPDATE student_import_job_items
                    SET
                        statut='FAILED',
                        error_message=?,
                        finished_at=NOW()
                    WHERE id=?
                ")->execute([
                    mb_substr(
                        $e->getMessage(),
                        0,
                        4000
                    ),
                    $item['id']
                ]);
            }


            $stats=
                recompute(
                    $pdo,
                    (int)$job['id']
                );


            $progress=
                $stats['total']>0
                ?min(
                    99,
                    max(
                        10,
                        (int)round(
                            $stats['processed']*
                            100/
                            $stats['total']
                        )
                    )
                )
                :10;


            step(
                $pdo,
                (int)$job['id'],
                $progress,
                'Importation des étudiants...',
                null
            );
        }


        /* =================================================
           FIN
        ================================================== */

        $stats=
            recompute(
                $pdo,
                (int)$job['id']
            );


        $status=
            $stats['failed']>0
            ?'COMPLETED_WITH_ERRORS'
            :'COMPLETED';


        $message=
            $stats['imported'].
            ' étudiant(s) importé(s)';


        if($stats['failed']>0){

            $message.=
                ', '.
                $stats['failed'].
                ' ligne(s) en échec';
        }
$pdo->prepare("
            UPDATE student_import_jobs
            SET
                statut=?,
                progress=100,
                step_label='Importation terminée.',
                message=?,
                current_student=NULL,
                finished_at=NOW(),
                heartbeat_at=NOW()
            WHERE id=?
        ")->execute([
            $status,
            $message.'.',
            $job['id']
        ]);


        /* Fichier source inutile après traitement final */
        @unlink(
            $file
        );


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
                $e->getMessage()
            );
        }


        error_log(
            '[STUDENT IMPORT WORKER] '.
            $e->getMessage()
        );


    }finally{

        try{

            $pdo
            ->prepare(
                "SELECT RELEASE_LOCK(?)"
            )
            ->execute([
                $lockName
            ]);

        }catch(Throwable $ignored){}
    }


}catch(Throwable $e){

    error_log(
        '[STUDENT IMPORT WORKER FATAL] '.
        $e->getMessage()
    );

    exit(1);
}

exit(0);

<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function sendJson(
    bool $success,
    string $message,
    array $data=[],
    int $status=200
):never{

    http_response_code($status);

    echo json_encode(
        [
            'success'=>$success,
            'message'=>$message,
            'data'=>$data
        ],
        JSON_UNESCAPED_UNICODE|
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function cleanValue(
    string $value,
    int $max=100
):string{

    $value=
        trim(
            preg_replace(
                '/\s+/u',
                ' ',
                $value
            )
            ??''
        );

    return mb_substr(
        $value,
        0,
        $max
    );
}

try{

    if(
        $_SERVER['REQUEST_METHOD']
        !=='POST'
    ){

        sendJson(
            false,
            'Méthode non autorisée.',
            [],
            405
        );
    }


    /* =====================================================
       LIMITATION DES TENTATIVES
    ====================================================== */

    $now=
        time();


    $attempts=
        array_values(
            array_filter(
                $_SESSION['student_verify_attempts']
                ??[],
                fn($t)=>
                    is_int($t)
                    &&
                    $t>$now-600
            )
        );


    if(
        count($attempts)>=15
    ){

        sendJson(
            false,
            'Trop de vérifications. Réessayez dans quelques minutes.',
            [],
            429
        );
    }


    $attempts[]=
        $now;


    $_SESSION['student_verify_attempts']=
        $attempts;


    /* =====================================================
       MATRICULE SAISI
       Accepte :
       - code STAGIA
       - matricule académique
    ====================================================== */

    $matricule=
        mb_strtoupper(
            cleanValue(
                $_POST['matricule']
                ??'',
                100
            )
        );


    if(
        $matricule===''
    ){

        sendJson(
            false,
            'Saisissez le matricule.',
            [],
            422
        );
    }


    /* =====================================================
       1. PRIORITÉ AU CODE STAGIA
    ====================================================== */

    $stmt=
        $pdo->prepare("
            SELECT
                sp.id AS student_id,
                sp.nom,
                sp.postnom,
                sp.prenom,
                e.nom AS etablissement_nom

            FROM student_profiles sp

            INNER JOIN student_enrollments se
                ON se.student_id=sp.id
               AND se.statut='ACTIF'

            INNER JOIN etablissements e
                ON e.id=se.etablissement_id
               AND e.statut IN(
                    'VALIDE',
                    'ACTIF'
               )

            WHERE sp.statut='ACTIF'
              AND UPPER(
                    TRIM(
                        sp.stagia_code
                    )
                  )=?

            ORDER BY
                se.id DESC

            LIMIT 1
        ");


    $stmt->execute([
        $matricule
    ]);


    $student=
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       2. SINON MATRICULE ACADÉMIQUE
    ====================================================== */

    if(
        !$student
    ){

        $stmt=
            $pdo->prepare("
                SELECT
                    sp.id AS student_id,
                    sp.nom,
                    sp.postnom,
                    sp.prenom,
                    e.nom AS etablissement_nom

                FROM student_enrollments se

                INNER JOIN student_profiles sp
                    ON sp.id=se.student_id
                   AND sp.statut='ACTIF'

                INNER JOIN etablissements e
                    ON e.id=se.etablissement_id
                   AND e.statut IN(
                        'VALIDE',
                        'ACTIF'
                   )

                WHERE se.statut='ACTIF'
                  AND UPPER(
                        TRIM(
                            se.matricule
                        )
                      )=?

                ORDER BY
                    se.id DESC

                LIMIT 2
            ");


        $stmt->execute([
            $matricule
        ]);


        $matches=
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        if(
            count($matches)>1
        ){

            sendJson(
                false,
                'Ce matricule existe dans plusieurs établissements. Utilisez le code STAGIA de l’étudiant.',
                [],
                409
            );
        }


        $student=
            $matches[0]
            ??null;
    }


    if(
        !$student
    ){

        sendJson(
            true,
            'Aucun étudiant actif correspondant à ce matricule n’a été trouvé.',
            [
                'found'=>false
            ]
        );
    }


    /* =====================================================
       NOM COMPLET
    ====================================================== */

    $fullName=
        trim(
            implode(
                ' ',
                array_filter(
                    [
                        trim(
                            (string)(
                                $student['nom']
                                ??''
                            )
                        ),

                        trim(
                            (string)(
                                $student['postnom']
                                ??''
                            )
                        ),

                        trim(
                            (string)(
                                $student['prenom']
                                ??''
                            )
                        )
                    ]
                )
            )
        );


    /* =====================================================
       PÉRIODE DE LA SEMAINE
    ====================================================== */

    $monday=
        (
            new DateTimeImmutable(
                'monday this week'
            )
        )
        ->format(
            'Y-m-d'
        );


    $sunday=
        (
            new DateTimeImmutable(
                'sunday this week'
            )
        )
        ->format(
            'Y-m-d'
        );


    $today=
        date(
            'Y-m-d'
        );


    /* =====================================================
       PRÉSENCES
    ====================================================== */

    $stmt=
        $pdo->prepare("
            SELECT
                date_presence,
                statut,
                heure_arrivee,
                heure_depart

            FROM stage_attendances

            WHERE student_id=?
              AND date_presence
                  BETWEEN ?
                      AND ?

            ORDER BY
                date_presence DESC,
                id DESC
        ");


    $stmt->execute([
        (int)$student['student_id'],
        $monday,
        $sunday
    ]);


    $week=
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    $todayAttendance=
        null;


    foreach(
        $week as $attendance
    ){

        if(
            $attendance['date_presence']
            ===$today
        ){

            $todayAttendance=
                $attendance;

            break;
        }
    }


    /* =====================================================
       RÉPONSE
    ====================================================== */

    sendJson(
        true,
        'Étudiant reconnu dans STAGIA-RDC.',
        [
            'found'=>true,

            'full_name'=>
                $fullName,

            'etablissement_nom'=>
                $student['etablissement_nom'],

            'today'=>
                $todayAttendance,

            'week'=>
                $week,

            'week_start'=>
                $monday,

            'week_end'=>
                $sunday
        ]
    );


}catch(Throwable $e){

    error_log(
        '[PUBLIC STUDENT VERIFY] '.
        $e->getMessage().
        ' | '.
        $e->getFile().
        ':'.
        $e->getLine()
    );


    sendJson(
        false,
        'Service de vérification temporairement indisponible.',
        [],
        500
    );
}

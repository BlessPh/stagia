<?php

ob_start();

header('Content-Type: application/json; charset=utf-8');


function respond(
    bool $success,
    string $message='',
    array $data=[],
    int $status=200
){
    http_response_code($status);

    if(ob_get_length()){
        ob_clean();
    }

    echo json_encode([
        'success'=>$success,
        'message'=>$message,
        'data'=>$data
    ],JSON_UNESCAPED_UNICODE);

    exit;
}


if(session_status()!==PHP_SESSION_ACTIVE){
    session_start();
}


require_once __DIR__.'/../../config/database.php';


/* =========================================================
   AUTH
========================================================= */

if(empty($_SESSION['user_id'])){

    respond(
        false,
        'Session expirée.',
        [],
        401
    );
}


if(
    ($_SESSION['role_code']??'')
    !==
    'STAGIAIRE'
){

    respond(
        false,
        'Accès réservé aux étudiants.',
        [],
        403
    );
}


$userId=(int)$_SESSION['user_id'];


try{

    /* =====================================================
       PROFIL ÉTUDIANT
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT
            id,
            stagia_code,
            nom,
            postnom,
            prenom,
            statut
        FROM student_profiles
        WHERE user_id=?
        LIMIT 1
    ");

    $stmt->execute([
        $userId
    ]);

    $student=
        $stmt->fetch(PDO::FETCH_ASSOC);


    if(!$student){

        respond(
            false,
            'Profil étudiant introuvable.',
            [],
            404
        );
    }


    $studentId=
        (int)$student['id'];


    /* =====================================================
       PARCOURS ACADÉMIQUE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT

            ae.id AS academic_enrollment_id,
            ae.statut,

            aa.libelle AS annee_academique,
            aa.date_debut,
            aa.date_fin,

            p.code AS promotion_code,
            p.nom AS promotion,
            p.niveau,

            f.nom AS filiere,

            e.code AS etablissement_code,
            e.nom AS etablissement

        FROM student_academic_enrollments ae

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        INNER JOIN annees_academiques aa
            ON aa.id=ae.annee_academique_id

        INNER JOIN promotions p
            ON p.id=ae.promotion_id

        LEFT JOIN filieres f
            ON f.id=p.filiere_id

        INNER JOIN etablissements e
            ON e.id=se.etablissement_id

        WHERE se.student_id=?

        ORDER BY
            aa.date_debut DESC,
            ae.id DESC
    ");

    $stmt->execute([
        $studentId
    ]);


    $items=
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    /* =====================================================
       KPI
    ====================================================== */

    $stats=[
        'total'=>count($items),
        'current'=>0,
        'completed'=>0,
        'other'=>0
    ];


    $current=null;


    foreach($items as &$item){

        $status=
            strtoupper(
                trim(
                    $item['statut']??''
                )
            );


        if($status==='EN_COURS'){

            $stats['current']++;

            if(!$current){
                $current=$item;
            }

        }elseif(
            in_array(
                $status,
                [
                    'TERMINE',
                    'TERMINEE',
                    'VALIDE',
                    'VALIDEE'
                ],
                true
            )
        ){

            $stats['completed']++;

        }else{

            $stats['other']++;
        }


        $item['is_current']=
            $status==='EN_COURS';
    }

    unset($item);


    respond(
        true,
        '',
        [
            'student'=>[
                'stagia_code'=>
                    $student['stagia_code'],

                'nom'=>
                    $student['nom'],

                'postnom'=>
                    $student['postnom'],

                'prenom'=>
                    $student['prenom']
            ],

            'current'=>$current,

            'items'=>$items,

            'stats'=>$stats
        ]
    );


}catch(Throwable $e){

    respond(
        false,
        'Erreur parcours : '.
        $e->getMessage(),
        [],
        500
    );
}
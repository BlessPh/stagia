<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['STAGIAIRE']);

try{

    $userId=(int)($_SESSION['user_id']??0);

    if(!$userId)
        jsonResponse(false,'Utilisateur non identifié.',[],401);


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

    $stmt->execute([$userId]);
    $student=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$student)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $studentId=(int)$student['id'];


    /* =====================================================
       PARCOURS ACADÉMIQUE
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            ae.id,
            ae.enrollment_id,
            ae.promotion_id,
            ae.annee_academique_id,
            ae.statut,

            se.matricule,
            se.statut AS enrollment_status,

            e.id AS etablissement_id,
            e.code AS etablissement_code,
            e.nom AS etablissement,

            p.code AS promotion_code,
            p.nom AS promotion,
            p.niveau,

            f.nom AS filiere,

            d.nom AS departement,

            fa.nom AS faculte

        FROM student_academic_enrollments ae

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        INNER JOIN etablissements e
            ON e.id=se.etablissement_id

        LEFT JOIN promotions p
            ON p.id=ae.promotion_id

        LEFT JOIN filieres f
            ON f.id=p.filiere_id

        LEFT JOIN departements d
            ON d.id=f.departement_id

        LEFT JOIN facultes fa
            ON fa.id=d.faculte_id

        WHERE se.student_id=?

        ORDER BY
            (ae.statut='EN_COURS') DESC,
            ae.id DESC
    ");

    $stmt->execute([$studentId]);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);


    /* =====================================================
       ANNÉES ACADÉMIQUES

       SELECT * volontaire :
       on normalise ensuite selon les colonnes existantes.
    ====================================================== */
    $yearStmt=$pdo->prepare("
        SELECT *
        FROM annees_academiques
        WHERE id=?
        LIMIT 1
    ");


    foreach($items as &$x){

        $x['id']=(int)$x['id'];
        $x['enrollment_id']=(int)$x['enrollment_id'];
        $x['promotion_id']=(int)$x['promotion_id'];
        $x['annee_academique_id']=
            (int)$x['annee_academique_id'];
        $x['etablissement_id']=
            (int)$x['etablissement_id'];


        /* Année académique */
        $year=[];

        if($x['annee_academique_id']){

            $yearStmt->execute([
                $x['annee_academique_id']
            ]);

            $year=
                $yearStmt->fetch(PDO::FETCH_ASSOC)
                ?:[];

        }


        $x['annee_academique']=
            $year['libelle']
            ??$year['nom']
            ??$year['code']
            ??$year['annee']
            ??$year['annee_academique']
            ??'-';


        $x['date_debut']=
            $year['date_debut']
            ??$year['starts_on']
            ??null;


        $x['date_fin']=
            $year['date_fin']
            ??$year['ends_on']
            ??null;
    }

    unset($x);


    /* =====================================================
       PARCOURS ACTUEL
    ====================================================== */
    $current=null;

    foreach($items as $x){

        if($x['statut']==='EN_COURS'){
            $current=$x;
            break;
        }
    }

    if(!$current && $items)
        $current=$items[0];


    /* =====================================================
       KPI
    ====================================================== */
    $total=count($items);

    $enCours=count(
        array_filter(
            $items,
            fn($x)=>$x['statut']==='EN_COURS'
        )
    );

    $termines=count(
        array_filter(
            $items,
            fn($x)=>in_array(
                $x['statut'],
                ['TERMINE','TERMINEE','VALIDE','CLOTURE'],
                true
            )
        )
    );


    jsonResponse(
        true,
        '',
        [
            'student'=>$student,
            'current'=>$current,
            'items'=>$items,

            'stats'=>[
                'total'=>$total,
                'en_cours'=>$enCours,
                'termines'=>$termines
            ]
        ]
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur parcours : '.$e->getMessage(),
        [],
        500
    );
}
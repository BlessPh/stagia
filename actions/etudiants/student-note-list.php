<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';


/* =========================================================
   ACCÈS
========================================================= */

requireAjaxRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE',
    'STAGIAIRE'
]);


$role=$_SESSION['role_code']??'';
$userId=(int)($_SESSION['user_id']??0);

$enrollmentId=0;
$academicId=(int)($_GET['academic_enrollment_id']??0);
$etablissementId=0;


/* =========================================================
   MODE ÉTUDIANT
   L'étudiant ne fournit jamais son enrollment_id.
========================================================= */

if($role==='STAGIAIRE'){

    if(!$userId){

        jsonResponse(
            false,
            'Session expirée.',
            [],
            401
        );
    }


    $stmt=$pdo->prepare("
        SELECT
            se.id AS enrollment_id,
            se.etablissement_id

        FROM student_profiles sp

        INNER JOIN student_enrollments se
            ON se.student_id=sp.id

        WHERE sp.user_id=?
          AND sp.statut='ACTIF'
          AND se.statut='ACTIF'

        ORDER BY se.id DESC

        LIMIT 1
    ");


    $stmt->execute([
        $userId
    ]);


    $enrollment=
        $stmt->fetch(PDO::FETCH_ASSOC);


    if(!$enrollment){

        jsonResponse(
            false,
            'Aucune inscription active trouvée.',
            [],
            404
        );
    }


    $enrollmentId=
        (int)$enrollment['enrollment_id'];

    $etablissementId=
        (int)$enrollment['etablissement_id'];
}


/* =========================================================
   MODE ADMINISTRATION UNIVERSITAIRE
========================================================= */

else{

    $etablissementId=
        (int)currentEtablissementId($pdo);

    $enrollmentId=
        (int)($_GET['enrollment_id']??0);


    if(
        !$etablissementId ||
        !$enrollmentId
    ){

        jsonResponse(
            false,
            'Étudiant invalide.',
            [],
            422
        );
    }


    /* Vérifier le rattachement */

    $stmt=$pdo->prepare("
        SELECT id

        FROM student_enrollments

        WHERE id=?
          AND etablissement_id=?

        LIMIT 1
    ");


    $stmt->execute([
        $enrollmentId,
        $etablissementId
    ]);


    if(!$stmt->fetchColumn()){

        jsonResponse(
            false,
            'Étudiant introuvable.',
            [],
            404
        );
    }
}


/* =========================================================
   ANNÉES ACADÉMIQUES DE L'ÉTUDIANT
========================================================= */

$stmt=$pdo->prepare("
    SELECT

        ae.id,

        ae.statut,

        ae.promotion_id,

        aa.id AS annee_academique_id,

        aa.libelle AS annee,

        aa.date_debut,
        aa.date_fin,

        p.code AS promotion_code,
        p.nom AS promotion,

        f.code AS filiere_code,
        f.nom AS filiere

    FROM student_academic_enrollments ae

    INNER JOIN student_enrollments se
        ON se.id=ae.enrollment_id
       AND se.etablissement_id=?

    INNER JOIN annees_academiques aa
        ON aa.id=ae.annee_academique_id
       AND aa.etablissement_id=
           se.etablissement_id

    INNER JOIN promotions p
        ON p.id=ae.promotion_id
       AND p.etablissement_id=
           se.etablissement_id

    LEFT JOIN filieres f
        ON f.id=p.filiere_id
       AND f.etablissement_id=
           se.etablissement_id

    WHERE ae.enrollment_id=?

    ORDER BY
        CASE
            WHEN ae.statut='EN_COURS'
            THEN 0
            ELSE 1
        END,
        aa.date_debut DESC,
        ae.id DESC
");


$stmt->execute([
    $etablissementId,
    $enrollmentId
]);


$academics=
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   AUCUN PARCOURS
========================================================= */

if(!$academics){

    jsonResponse(
        true,
        '',
        [
            'academics'=>[],
            'academic'=>null,
            'matieres'=>[],
            'notes'=>[],

            'stats'=>[
                'moyenne'=>0,
                'notes'=>0,
                'evaluations'=>0,
                'matieres'=>0,
                'matieres_evaluees'=>0,
                'derniere_note'=>null
            ]
        ]
    );
}


/* =========================================================
   ANNÉE COURANTE PAR DÉFAUT
========================================================= */

if(!$academicId){

    /*
     * La requête place EN_COURS en premier.
     */
    $academicId=
        (int)$academics[0]['id'];
}


/* =========================================================
   VÉRIFIER QUE L'ANNÉE APPARTIENT À L'ÉTUDIANT
========================================================= */

$academic=null;


foreach($academics as $item){

    if(
        (int)$item['id']
        ===
        $academicId
    ){

        $academic=$item;
        break;
    }
}


if(!$academic){

    jsonResponse(
        false,
        'Année académique invalide.',
        [],
        404
    );
}


/* =========================================================
   MATIÈRES DE LA PROMOTION
========================================================= */

$stmt=$pdo->prepare("
    SELECT

        id,
        code,
        nom,
        credits,
        coefficient,
        note_max

    FROM matieres

    WHERE etablissement_id=?
      AND promotion_id=?
      AND actif=1

    ORDER BY nom
");


$stmt->execute([
    $etablissementId,
    $academic['promotion_id']
]);


$matieres=
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   NOTES
========================================================= */

$stmt=$pdo->prepare("
    SELECT

        sn.id,
        sn.matiere_id,

        sn.type_evaluation,

        sn.note,
        sn.note_sur,

        sn.date_evaluation,

        sn.observation,

        m.code AS matiere_code,

        m.nom AS matiere,

        m.coefficient,

        m.note_max

    FROM student_notes sn

    INNER JOIN matieres m
        ON m.id=sn.matiere_id
       AND m.etablissement_id=?
       AND m.promotion_id=?

    WHERE sn.academic_enrollment_id=?

    ORDER BY
        sn.date_evaluation DESC,
        m.nom,
        sn.id DESC
");


$stmt->execute([
    $etablissementId,
    $academic['promotion_id'],
    $academicId
]);


$notes=
    $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   NORMALISER LES NOTES
========================================================= */

foreach($notes as &$note){

    $value=
        (float)$note['note'];

    $sur=
        (float)$note['note_sur'];


    $note['note']=
        $value;

    $note['note_sur']=
        $sur;


    $note['note_sur_20']=
        $sur>0
            ?round(
                ($value/$sur)*20,
                2
            )
            :null;


    /*
     * Résultat indicatif.
     * 10/20 = réussite.
     */

    if($note['note_sur_20']!==null){

        $note['resultat']=
            $note['note_sur_20']>=10
                ?'REUSSI'
                :'ECHEC';

    }else{

        $note['resultat']=null;
    }
}

unset($note);


/* =========================================================
   STATISTIQUES PAR MATIÈRE
========================================================= */

$groupes=[];

$evaluationTypes=[];


foreach($notes as $note){

    $matiereId=
        (int)$note['matiere_id'];


    if(
        $note['note_sur_20']
        ===
        null
    ){
        continue;
    }


    if(!isset($groupes[$matiereId])){

        $groupes[$matiereId]=[
            'total'=>0,
            'count'=>0,
            'coefficient'=>
                max(
                    1,
                    (float)$note['coefficient']
                )
        ];
    }


    $groupes[$matiereId]['total']+=
        (float)$note['note_sur_20'];

    $groupes[$matiereId]['count']++;


    if(!empty($note['type_evaluation'])){

        $evaluationTypes[
            $note['type_evaluation']
        ]=true;
    }
}


/* =========================================================
   MOYENNE PONDÉRÉE
========================================================= */

$ponderee=0;
$totalCoeff=0;


foreach($groupes as $g){

    if(!$g['count']){
        continue;
    }


    $moyenneMatiere=
        $g['total']/$g['count'];


    $ponderee+=
        $moyenneMatiere*
        $g['coefficient'];


    $totalCoeff+=
        $g['coefficient'];
}


$moyenne=
    $totalCoeff>0
        ?round(
            $ponderee/$totalCoeff,
            2
        )
        :0;


/* =========================================================
   DERNIÈRE NOTE
========================================================= */

$derniereNote=null;


if($notes){

    $last=$notes[0];


    $derniereNote=[

        'matiere'=>
            $last['matiere'],

        'type_evaluation'=>
            $last['type_evaluation'],

        'note'=>
            $last['note'],

        'note_sur'=>
            $last['note_sur'],

        'note_sur_20'=>
            $last['note_sur_20'],

        'date_evaluation'=>
            $last['date_evaluation'],

        'resultat'=>
            $last['resultat']

    ];
}


/* =========================================================
   RÉPONSE
========================================================= */

jsonResponse(
    true,
    '',
    [

        'academics'=>
            $academics,

        'academic'=>
            $academic,

        'matieres'=>
            $matieres,

        'notes'=>
            $notes,

        'stats'=>[

            'moyenne'=>
                $moyenne,

            'notes'=>
                count($notes),

            'evaluations'=>
                count($evaluationTypes),

            'matieres'=>
                count($matieres),

            'matieres_evaluees'=>
                count($groupes),

            'derniere_note'=>
                $derniereNote

        ]

    ]
);
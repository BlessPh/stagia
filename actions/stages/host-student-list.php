<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);

try{

    $hopitalId=currentEtablissementId($pdo);

    if(!$hopitalId)
        jsonResponse(
            false,
            'Aucun hôpital associé.',
            [],
            403
        );


    /* =====================================================
       STAGIAIRES CONFIRMÉS DE L'HÔPITAL
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT

            sr.id AS reservation_id,
            sr.statut AS reservation_statut,
            sr.confirmed_at,

            sa.id AS application_id,

            s.id AS student_id,
            s.stagia_code,
            s.nom,
            s.postnom,
            s.prenom,

            p.nom AS promotion,
            f.nom AS filiere,

            c.id AS campaign_id,
            c.code AS campaign_code,
            c.titre AS campaign_title,
            c.date_debut,
            c.date_fin,

            origine.id AS university_id,
            origine.code AS university_code,
            origine.nom AS university_name,

            ad.id AS admission_id,
            ad.statut AS admission_statut,
            ad.admitted_at

        FROM stage_reservations sr

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments sae
            ON sae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=sae.enrollment_id

        INNER JOIN student_profiles s
            ON s.id=se.student_id

        LEFT JOIN promotions p
            ON p.id=sae.promotion_id

        LEFT JOIN filieres f
            ON f.id=p.filiere_id

        INNER JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        INNER JOIN etablissements origine
            ON origine.id=c.owner_etablissement_id

        LEFT JOIN stage_admissions ad
            ON ad.reservation_id=sr.id
           AND ad.host_etablissement_id=sa.host_etablissement_id

        WHERE sa.host_etablissement_id=?
          AND sr.statut='CONFIRMEE'

        ORDER BY
            sr.confirmed_at DESC,
            s.nom,
            s.prenom
    ");

    $stmt->execute([
        $hopitalId
    ]);

    $items=$stmt->fetchAll();


    /* =====================================================
       STATISTIQUES
    ====================================================== */
    $universites=[];
    $campagnes=[];
    $admis=0;


    foreach($items as &$item){

        /* Si aucune admission : stagiaire attendu */
        if(empty($item['admission_statut']))
            $item['admission_statut']='ATTENDU';


        if(!empty($item['university_id']))
            $universites[
                $item['university_id']
            ]=true;


        if(!empty($item['campaign_id']))
            $campagnes[
                $item['campaign_id']
            ]=true;


        if(
            in_array(
                $item['admission_statut'],
                [
                    'ADMIS',
                    'EN_COURS',
                    'TERMINE'
                ],
                true
            )
        ){
            $admis++;
        }
    }

    unset($item);


    /* =====================================================
       RÉPONSE
    ====================================================== */
    jsonResponse(
        true,
        '',
        [
            'items'=>$items,

            'stats'=>[
                'total'=>count($items),
                'universites'=>count($universites),
                'campagnes'=>count($campagnes),
                'admis'=>$admis
            ]
        ]
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur chargement stagiaires : '.
        $e->getMessage(),
        [],
        500
    );
}
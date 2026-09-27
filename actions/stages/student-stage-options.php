<?php
/**
 * Endpoint AJAX qui propose au stagiaire les campagnes D4 et hôpitaux encore disponibles.
 * Une campagne est masquée lorsque l'étudiant détient déjà une réservation active pour celle-ci.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Les offres de stage sont consultables uniquement dans le contexte du stagiaire connecté. */
requireAjaxRole(['STAGIAIRE']);

try{
    /* Le profil actif est résolu depuis la session, sans identifiant étudiant fourni par le client. */

    /* =====================================================
       UTILISATEUR
    ====================================================== */

    $userId=(int)($_SESSION['user_id']??0);

    if(!$userId)
        jsonResponse(
            false,
            'Session étudiant invalide.',
            [],
            401
        );


    /* =====================================================
       PROFIL ÉTUDIANT
    ====================================================== */

    /* Campagnes universitaires D4 ouvertes, compatibles avec l'inscription académique active. */
    $stmt=$pdo->prepare("
        SELECT
            id,
            stagia_code,
            nom,
            postnom,
            prenom

        FROM student_profiles

        WHERE user_id=?
          AND statut='ACTIF'

        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $student=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$student)
        jsonResponse(
            false,
            'Profil étudiant introuvable.',
            [],
            404
        );


    $studentId=(int)$student['id'];


    /* =====================================================
       CAMPAGNES D4 DISPONIBLES

       IMPORTANT :
       Une campagne disparaît des choix dès que
       l'étudiant possède une réservation active.

       Elle peut réapparaître si la réservation devient :
       - EXPIREE
       - ANNULEE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT DISTINCT
            c.id AS campaign_id,
            c.code,
            c.titre,
            c.date_debut,
            c.date_fin,

            ae.id AS academic_enrollment_id,

            aa.libelle AS annee_academique,
            p.nom AS promotion,
            f.nom AS filiere

        FROM student_enrollments se

        INNER JOIN student_academic_enrollments ae
            ON ae.enrollment_id=se.id

        INNER JOIN stage_campaigns c
            ON c.owner_etablissement_id=se.etablissement_id
           AND c.annee_academique_id=ae.annee_academique_id
           AND c.type_campagne='UNIVERSITAIRE'
           AND c.statut='OUVERTE'

        INNER JOIN stage_types st
            ON st.id=c.stage_type_id
           AND st.code='MEDICAL_D4'

        INNER JOIN stage_campaign_promotions cp
            ON cp.campaign_id=c.id
           AND cp.promotion_id=ae.promotion_id

        INNER JOIN annees_academiques aa
            ON aa.id=ae.annee_academique_id

        INNER JOIN promotions p
            ON p.id=ae.promotion_id

        INNER JOIN filieres f
            ON f.id=p.filiere_id

        WHERE se.student_id=?
          AND se.statut='ACTIF'
          AND ae.statut='EN_COURS'

          /* =============================================
             PAS DE RÉSERVATION ACTIVE POUR CETTE CAMPAGNE
          ============================================== */

          AND NOT EXISTS(

              SELECT 1

              FROM stage_applications sa

              INNER JOIN stage_reservations sr
                  ON sr.application_id=sa.id

              LEFT JOIN stage_placements pl
                  ON pl.reservation_id=sr.id AND pl.statut='CONFIRME'

              LEFT JOIN stage_admissions ad
                  ON ad.reservation_id=sr.id AND ad.statut<>'ANNULE'

              WHERE sa.campaign_id=c.id
                AND sa.academic_enrollment_id=ae.id

                AND (

                    sr.statut IN(
                        'EN_ATTENTE_PAIEMENT',
                        'CONFIRMEE'
                    )

                    OR (

                        sr.statut='RESERVEE_TEMPORAIREMENT'

                        AND (
                            sr.expires_at IS NULL
                            OR sr.expires_at>NOW()
                        )

                    )

                    OR pl.id IS NOT NULL

                    OR ad.id IS NOT NULL

                )

          )

        ORDER BY c.date_debut DESC
    ");

    $stmt->execute([$studentId]);

    $campaigns=
        $stmt->fetchAll(PDO::FETCH_ASSOC);


    /* =====================================================
       HÔPITAUX ACCEPTÉS
    ====================================================== */

    /* Chaque campagne est enrichie de ses hôpitaux acceptés et du nombre de places restantes. */
    foreach($campaigns as &$campaign){

        $stmt=$pdo->prepare("
            SELECT
                sp.id AS participation_id,
                sp.host_etablissement_id,
                sp.capacite_acceptee AS capacite_allouee,
                sp.frais_requis,
                sp.montant_frais,
                sp.devise,
                sp.conditions,

                h.nom AS hopital,
                h.ville,
                h.province,

                GREATEST(

                    sp.capacite_acceptee

                    -

                    COALESCE((

                        SELECT COUNT(*)

                        FROM stage_reservations sr

                        WHERE sr.participation_id=sp.id

                          AND (

                              sr.statut IN(
                                  'EN_ATTENTE_PAIEMENT',
                                  'CONFIRMEE'
                              )

                              OR (

                                  sr.statut=
                                      'RESERVEE_TEMPORAIREMENT'

                                  AND (
                                      sr.expires_at IS NULL
                                      OR sr.expires_at>NOW()
                                  )

                              )

                          )

                    ),0),

                    0

                ) AS places_disponibles

            FROM stage_campaign_participations sp

            INNER JOIN stage_campaigns hc
                ON hc.id=sp.host_campaign_id
               AND hc.type_campagne='ACCUEIL'
               AND hc.statut NOT IN('ANNULEE','TERMINEE')

            INNER JOIN stage_capacity_pools capacity_pool
                ON capacity_pool.host_campaign_id=hc.id

            INNER JOIN etablissements h
                ON h.id=sp.host_etablissement_id

            WHERE sp.university_campaign_id=?
              AND sp.statut='ACCEPTEE'
              AND sp.capacite_acceptee IS NOT NULL
              AND sp.capacite_acceptee>0
              AND h.type_etablissement='HOPITAL'
              AND h.statut IN(
                    'VALIDE',
                    'ACTIF'
              )

            ORDER BY h.nom
        ");

        $stmt->execute([
            $campaign['campaign_id']
        ]);

        $campaign['hopitaux']=
            $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    unset($campaign);


    /* =====================================================
       RÉPONSE
    ====================================================== */

    /* Réponse prête pour l'écran de choix de stage du stagiaire. */
    jsonResponse(
        true,
        '',
        [
            'student'=>$student,
            'campaigns'=>$campaigns
        ]
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur chargement stages : '.$e->getMessage(),
        [],
        500
    );
}

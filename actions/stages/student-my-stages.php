<?php
/**
 * Endpoint AJAX du suivi personnel des stages d'un stagiaire.
 * Il rassemble affectation, établissement d'accueil, unité, clôture et attestation éventuelle.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* La consultation est strictement limitée aux stages de l'utilisateur stagiaire connecté. */
requireAjaxRole(['STAGIAIRE']);

try{
    /* Les jointures partent de l'affectation et remontent au stagiaire lié à la session. */

    $userId=(int)($_SESSION['user_id']??0);

    if(!$userId)
        jsonResponse(false,'Utilisateur non identifié.',[],401);


    /* =====================================================
       STAGES DE L'ÉTUDIANT
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT DISTINCT
            a.id AS assignment_id,
            a.statut AS assignment_status,
            a.date_debut,
            a.date_fin,

            sp.stagia_code,

            c.id AS campaign_id,
            c.code AS campaign_code,
            c.titre AS campaign_title,

            h.id AS host_id,
            h.code AS host_code,
            h.nom AS host_name,
            h.ville AS host_city,
            h.province AS host_province,

            hu.code AS unit_code,
            hu.nom AS unit_name,

            comp.id AS completion_id,
            comp.statut AS completion_status,
            comp.taux_presence,
            comp.note_finale,
            comp.validated_at,

            cert.id AS certificate_id,
            cert.uuid AS certificate_uuid,
            cert.reference AS certificate_reference,
            cert.statut AS certificate_status,
            cert.generated_at

        FROM stage_assignments a

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments sae
            ON sae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=sae.enrollment_id

        INNER JOIN student_profiles sp
            ON sp.id=se.student_id

        INNER JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        INNER JOIN etablissements h
            ON h.id=a.host_etablissement_id

        LEFT JOIN host_units hu
            ON hu.id=a.host_unit_id

        LEFT JOIN stage_completions comp
            ON comp.assignment_id=a.id

        LEFT JOIN stage_certificates cert
            ON cert.completion_id=comp.id

        WHERE sp.user_id=?
          AND a.statut<>'ANNULEE'

        ORDER BY
            COALESCE(comp.validated_at,a.date_debut) DESC,
            a.id DESC
    ");

    $stmt->execute([$userId]);

    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);


    /* Conversion des identifiants, taux et notes pour une réponse JSON typée. */
    foreach($items as &$x){

        $x['assignment_id']=
            (int)$x['assignment_id'];

        $x['campaign_id']=
            (int)$x['campaign_id'];

        $x['host_id']=
            (int)$x['host_id'];

        $x['completion_id']=
            $x['completion_id']!==null
                ?(int)$x['completion_id']
                :null;

        $x['certificate_id']=
            $x['certificate_id']!==null
                ?(int)$x['certificate_id']
                :null;

        $x['taux_presence']=
            $x['taux_presence']!==null
                ?(float)$x['taux_presence']
                :null;

        $x['note_finale']=
            $x['note_finale']!==null
                ?(float)$x['note_finale']
                :null;
    }

    unset($x);


    jsonResponse(
        true,
        '',
        [
            'items'=>$items
        ]
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur stages : '.$e->getMessage(),
        [],
        500
    );
}

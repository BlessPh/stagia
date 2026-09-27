<?php
/**
 * Endpoint AJAX de liste des réservations appartenant au stagiaire connecté.
 * Les réservations temporaires expirées sont synchronisées avant le calcul des compteurs.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Un stagiaire ne peut consulter que son propre historique de réservation. */
requireAjaxRole(['STAGIAIRE']);

try{
    /* Le profil étudiant est recherché depuis l'utilisateur authentifié. */

    $userId=(int)($_SESSION['user_id']??0);

    if(!$userId)
        jsonResponse(false,'Session invalide.',[],401);

    /* Profil étudiant */
    $stmt=$pdo->prepare("
        SELECT id
        FROM student_profiles
        WHERE user_id=?
          AND statut='ACTIF'
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $studentId=(int)$stmt->fetchColumn();

    if(!$studentId)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    /* Expirer automatiquement les réservations dépassées */
    /* Expiration automatique des réservations temporaires dont le délai est dépassé. */
    $pdo->exec("
        UPDATE stage_reservations
        SET statut='EXPIREE'
        WHERE statut='RESERVEE_TEMPORAIREMENT'
          AND expires_at IS NOT NULL
          AND expires_at<=NOW()
    ");

    /* Réservations appartenant uniquement à l'étudiant connecté */
    /* Les réservations sont jointes à la campagne, l'hôpital et aux éventuels frais. */
    $stmt=$pdo->prepare("
        SELECT
            sr.id,
            sr.uuid,
            sr.statut,
            sr.expires_at,
            sr.confirmed_at,
            sr.cancelled_at,
            sr.created_at,

            sa.id application_id,
            sa.statut application_statut,

            c.id campaign_id,
            c.code campaign_code,
            c.titre campaign_title,
            c.date_debut,
            c.date_fin,

            h.id hospital_id,
            h.code hospital_code,
            h.nom hospital_name,
            h.ville,
            h.province,

            sp.frais_requis,
            sp.montant_frais,
            sp.devise

        FROM stage_reservations sr

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments sae
            ON sae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=sae.enrollment_id
           AND se.student_id=?

        INNER JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        INNER JOIN etablissements h
            ON h.id=sa.host_etablissement_id

        INNER JOIN stage_campaign_participations sp
            ON sp.id=sr.participation_id

        ORDER BY sr.created_at DESC
    ");

    $stmt->execute([$studentId]);
    $items=$stmt->fetchAll();

    /* Statistiques */
    /* Répartition des réservations par état destinée aux indicateurs de l'interface. */
    $stats=[
        'total'=>count($items),
        'temporaires'=>0,
        'paiement'=>0,
        'confirmees'=>0,
        'expirees'=>0
    ];

    foreach($items as &$item){

        switch($item['statut']){

            case 'RESERVEE_TEMPORAIREMENT':
                $stats['temporaires']++;
                break;

            case 'EN_ATTENTE_PAIEMENT':
                $stats['paiement']++;
                break;

            case 'CONFIRMEE':
                $stats['confirmees']++;
                break;

            case 'EXPIREE':
                $stats['expirees']++;
                break;
        }

        $item['frais_requis']=
            (bool)$item['frais_requis'];
    }

    unset($item);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>$stats
    ]);

}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur chargement réservations : '.$e->getMessage(),
        [],
        500
    );
}

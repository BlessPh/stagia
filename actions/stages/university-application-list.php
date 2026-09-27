<?php
/**
 * Endpoint AJAX de suivi des candidatures de stage appartenant à l'établissement de formation.
 * Il expose la candidature, sa réservation, l'hôpital retenu et les informations de facturation éventuelles.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Les responsables académiques consultent uniquement les campagnes propriétaires de leur établissement. */
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

try{
    /* La campagne est filtrée dans la jointure par l'établissement universitaire de session. */
    $eid=currentEtablissementId($pdo);
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    $s=$pdo->prepare("
        SELECT
            a.id application_id,a.uuid application_uuid,a.statut,a.motivation,a.motif_refus,
            a.submitted_at,a.responded_at,a.academic_enrollment_id,a.participation_id,
            c.code campaign_code,c.titre campaign_title,
            h.nom hospital_name,h.ville,h.province,
            sp.stagia_code,sp.nom,sp.postnom,sp.prenom,se.matricule,
            p.nom promotion,f.nom filiere,
            r.id reservation_id,r.statut reservation_statut,r.expires_at,r.confirmed_at,
            hp.frais_requis,hp.montant_frais,hp.devise,hp.capacite_acceptee,
            i.reference invoice_reference,i.statut invoice_statut,i.montant invoice_amount
        FROM stage_applications a
        JOIN stage_campaigns c ON c.id=a.campaign_id AND c.owner_etablissement_id=?
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN promotions p ON p.id=ae.promotion_id
        LEFT JOIN filieres f ON f.id=p.filiere_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_reservations r ON r.application_id=a.id
        LEFT JOIN stage_campaign_participations hp ON hp.id=a.participation_id
        LEFT JOIN stage_invoices i ON i.reservation_id=r.id
        ORDER BY COALESCE(a.submitted_at,a.created_at) DESC,a.id DESC
    ");
    $s->execute([$eid]);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Les indicateurs séparent candidatures à traiter, acceptées et réservations confirmées. */
    $stats=['total'=>count($items),'soumises'=>0,'acceptees'=>0,'confirmees'=>0];
    foreach($items as $x){
        if(in_array($x['statut'],['SOUMISE','EN_ETUDE'],true))$stats['soumises']++;
        if($x['statut']==='ACCEPTEE')$stats['acceptees']++;
        if($x['reservation_statut']==='CONFIRMEE')$stats['confirmees']++;
    }

    jsonResponse(true,'',['items'=>$items,'stats'=>$stats]);
}catch(Throwable $e){
    /* L'erreur technique est journalisée côté serveur avant la réponse AJAX contrôlée. */
    error_log('[UNIVERSITY APPLICATION LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

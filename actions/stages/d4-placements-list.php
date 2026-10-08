<?php
/**
 * Endpoint AJAX de suivi universitaire des réservations et placements D4 par structure d'accueil.
 * Il distingue les réservations sélectionnées, prêtes à placer, confirmées et le quota restant par hôpital.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    /* Seules les campagnes universitaires non terminées qui ont des participations sont présentées. */
    requirePermission($pdo,'placement.university.view');
    if(!contextAcademicEnabled())jsonResponse(false,"Cet espace n'est pas un établissement de formation.",[],403);

    $eid=(int)($_SESSION['etablissement_id']??0);
    $campaignId=(int)($_GET['campaign_id']??0);
    if(!$eid)jsonResponse(false,'Aucun établissement actif.',[],403);

    $s=$pdo->prepare("
        SELECT c.id,c.code,c.titre,c.statut,c.date_debut,c.date_fin,
               st.code stage_type_code,st.libelle stage_type_libelle,aa.libelle annee_libelle
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        LEFT JOIN annees_academiques aa ON aa.id=c.annee_academique_id
        WHERE c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE'
          AND c.statut IN('EN_PREPARATION','OUVERTE','CLOTUREE')
          AND EXISTS(SELECT 1 FROM stage_campaign_participations p WHERE p.university_campaign_id=c.id)
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $s->execute([$eid]);$campaigns=$s->fetchAll(PDO::FETCH_ASSOC);

    if($campaignId&&!array_filter($campaigns,fn($c)=>(int)$c['id']===$campaignId))$campaignId=0;
    if(!$campaignId&&$campaigns)$campaignId=(int)$campaigns[0]['id'];

    $items=[];$byHospital=[];

    /* Pour la campagne sélectionnée, les réservations sont regroupées par hôpital pour calculer les quotas. */
    if($campaignId){
        $s=$pdo->prepare("
            SELECT r.id reservation_id,r.statut reservation_status,r.confirmed_at reservation_confirmed_at,r.expires_at,
                   app.id application_id,app.statut application_status,ae.id academic_enrollment_id,
                   sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
                   pr.id promotion_id,pr.code promotion_code,pr.nom promotion_name,al.code level_code,
                   p.id participation_id,p.host_etablissement_id,
                   COALESCE(NULLIF(p.capacite_acceptee,0),NULLIF(p.capacite_allouee,0)) capacite_retenue,
                   p.frais_requis,p.montant_frais,p.devise,
                   h.code host_code,h.nom host_name,
                   pl.id placement_id,pl.uuid placement_uuid,pl.statut placement_status,
                   pl.confirmed_at placement_confirmed_at,pl.university_confirmed_at,
                   pl.cancelled_at placement_cancelled_at,pl.cancellation_reason placement_cancellation_reason
            FROM stage_reservations r
            JOIN stage_applications app ON app.id=r.application_id
            JOIN stage_campaigns c ON c.id=app.campaign_id AND c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE'
            JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
            JOIN student_enrollments se ON se.id=ae.enrollment_id
            JOIN student_profiles sp ON sp.id=se.student_id
            JOIN promotions pr ON pr.id=ae.promotion_id
            LEFT JOIN academic_levels al ON al.id=pr.academic_level_id
            JOIN stage_campaign_participations p ON p.id=r.participation_id
            JOIN etablissements h ON h.id=p.host_etablissement_id
            LEFT JOIN stage_placements pl ON pl.reservation_id=r.id
            WHERE c.id=? AND r.statut IN('RESERVEE_TEMPORAIREMENT','EN_ATTENTE_PAIEMENT','CONFIRMEE')
            ORDER BY h.nom,sp.nom,sp.prenom
        ");
        $s->execute([$eid,$campaignId]);$all=$s->fetchAll(PDO::FETCH_ASSOC);

        /* Les compteurs hospitaliers sont calculés à partir des réservations et placements réellement stockés. */
        foreach($all as $x){
            $hid=(int)$x['host_etablissement_id'];
            $byHospital[$hid]??=[
                'host_etablissement_id'=>$hid,'host_code'=>$x['host_code'],'host_name'=>$x['host_name'],
                'selected_count'=>0,'ready_count'=>0,'placed_count'=>0,
                'allocation'=>(int)$x['capacite_retenue'],'ready_reservation_ids'=>[]
            ];

            if($x['placement_status']!=='CONFIRME')$byHospital[$hid]['selected_count']++;
            if($x['application_status']==='ACCEPTEE'
                &&$x['reservation_status']==='CONFIRMEE'
                &&$x['placement_status']!=='CONFIRME'
            ){
                $byHospital[$hid]['ready_count']++;
                $byHospital[$hid]['ready_reservation_ids'][]=(int)$x['reservation_id'];
            }
            if($x['placement_status']==='CONFIRME')$byHospital[$hid]['placed_count']++;
        }

        foreach($byHospital as &$h){
            $h['consumed_count']=$h['selected_count']+$h['placed_count'];
            $h['remaining_allocation']=max(0,$h['allocation']-$h['consumed_count']);
        }unset($h);

        $byHospital=array_values($byHospital);
        $items=$all;
    }

    jsonResponse(true,'',[
        'campaigns'=>$campaigns,
        'selected_campaign_id'=>$campaignId,
        'items'=>$items,
        'by_hospital'=>$byHospital,
        'permissions'=>['manage'=>hasPermission($pdo,'placement.university.manage')]
    ]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur Affectations / Placements : '.$e->getMessage(),[],500);
}

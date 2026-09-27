<?php
/**
 * Endpoint AJAX de suivi des partenaires hospitaliers d'une campagne universitaire D4.
 * Il fournit les sollicitations, offres, sélections de structures et permissions d'action de l'université.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-d4-participation.php';
require_once __DIR__.'/../../includes/stage-campaign.php';

try{
    /* Seules les campagnes universitaires actives appartenant à l'établissement de session sont retenues. */
    requirePermission($pdo,'campaign.university.view');
    if(!contextAcademicEnabled())jsonResponse(false,"Cet espace n'est pas un établissement de formation.",[],403);

    $eid=(int)($_SESSION['etablissement_id']??0);
    $campaignId=(int)($_GET['campaign_id']??0);
    if(!$eid)jsonResponse(false,'Aucun établissement actif.',[],403);

    $s=$pdo->prepare("
        SELECT c.id,c.code,c.titre,c.statut,c.stage_type_id,c.configuration,c.date_debut,c.date_fin,c.published_at,
               st.code stage_type_code,st.libelle stage_type_libelle,aa.libelle annee_libelle,
               (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.university_campaign_id=c.id) solicited_count,
               (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.university_campaign_id=c.id AND p.statut='ACCEPTEE' AND p.capacite_proposee IS NOT NULL) offers_count,
               (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.university_campaign_id=c.id AND p.statut='ACCEPTEE' AND p.capacite_acceptee IS NOT NULL) finalized_count
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        LEFT JOIN annees_academiques aa ON aa.id=c.annee_academique_id
        WHERE c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE'
          AND c.statut NOT IN('ANNULEE','TERMINEE')
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $s->execute([$eid]);$campaigns=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Les configurations JSON de campagne sont normalisées pour calculer exigences et sélections. */
    foreach($campaigns as &$c){
        $c['configuration']=campaignConfig($c['configuration']);
        foreach(['solicited_count','offers_count','finalized_count'] as $k)$c[$k]=(int)$c[$k];
        $selected=(array)($c['configuration']['selected_host_ids']??[]);
        $c['selected_count']=count($selected);
        $c['requires_hosting']=(bool)stagePolicyValue($pdo,(int)$c['stage_type_id'],'requires_hosting_participation',false);
        $c['minimum_hospitals']=$c['requires_hosting']?d4MinimumHospitals($pdo,(int)$c['stage_type_id']):0;
    }unset($c);

    $campaigns=array_values(array_filter($campaigns,fn($c)=>
        $c['requires_hosting']||$c['selected_count']>0||$c['solicited_count']>0
    ));

    if($campaignId&&!array_filter($campaigns,fn($c)=>(int)$c['id']===$campaignId))$campaignId=0;
    if(!$campaignId&&$campaigns)$campaignId=(int)$campaigns[0]['id'];

    $participations=[];$hospitals=[];

    /* Pour la campagne sélectionnée, la liste distingue les partenaires sollicités des hôpitaux disponibles. */
    if($campaignId){
        $current=array_values(array_filter($campaigns,fn($c)=>(int)$c['id']===$campaignId))[0]??null;
        $selectedHosts=array_map('intval',(array)($current['configuration']['selected_host_ids']??[]));

        $s=$pdo->prepare("
            SELECT p.id,p.host_etablissement_id,p.host_campaign_id,p.statut,p.capacite_demandee,p.capacite_proposee,
                   p.capacite_acceptee,p.capacite_allouee,p.date_debut,p.date_fin,p.conditions,p.motif_refus,
                   p.frais_requis,p.montant_frais,p.devise,p.requested_at,p.responded_at,p.finalized_at,
                   p.cancellation_reason,e.code host_code,e.nom host_nom,e.province,e.ville,
                   hc.code host_campaign_code,hc.titre host_campaign_title
            FROM stage_campaign_participations p
            JOIN etablissements e ON e.id=p.host_etablissement_id
            LEFT JOIN stage_campaigns hc ON hc.id=p.host_campaign_id
            WHERE p.university_campaign_id=?
            ORDER BY p.created_at DESC,p.id DESC
        ");
        $s->execute([$campaignId]);$participations=$s->fetchAll(PDO::FETCH_ASSOC);

        $s=$pdo->prepare("
            SELECT e.id,e.code,e.nom,e.province,e.ville,IF(p.id IS NULL,0,1) already_solicited,
                   p.id participation_id,p.statut participation_status
            FROM etablissements e
            JOIN establishment_types et ON et.code=e.type_etablissement AND et.host_enabled=1 AND et.actif=1
            LEFT JOIN stage_campaign_participations p ON p.host_etablissement_id=e.id AND p.university_campaign_id=?
            WHERE e.statut IN('VALIDE','ACTIF') AND e.id<>?
            ORDER BY e.nom
        ");
        $s->execute([$campaignId,$eid]);$hospitals=$s->fetchAll(PDO::FETCH_ASSOC);

        foreach($hospitals as &$h)$h['selected']=in_array((int)$h['id'],$selectedHosts,true)?1:0;
        unset($h);
    }

    /* Le front-end reçoit le contexte complet et les capacités d'action de l'acteur connecté. */
    jsonResponse(true,'',[
        'campaigns'=>$campaigns,
        'selected_campaign_id'=>$campaignId,
        'participations'=>$participations,
        'hospitals'=>$hospitals,
        'permissions'=>[
            'solicit'=>hasPermission($pdo,'campaign.d4.solicit'),
            'finalize'=>hasPermission($pdo,'campaign.d4.finalize'),
            'cancel'=>hasPermission($pdo,'campaign.d4.cancel'),
            'open_students'=>hasPermission($pdo,'campaign.d4.open_students')
        ]
    ]);
}catch(Throwable $e){
    error_log('[HOSPITAL PARTNERS] '.$e->getMessage());
    jsonResponse(false,'Erreur Hôpitaux partenaires : '.$e->getMessage(),[],500);
}

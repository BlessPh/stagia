<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    requirePermission($pdo,'campaign.hosting.respond');
    if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

    $eid=(int)($_SESSION['etablissement_id']??0);
    if(!$eid)jsonResponse(false,'Aucun établissement actif.',[],403);

    $status=strtoupper(trim((string)($_GET['statut']??'')));
    $allowed=['SOLLICITEE','EN_ETUDE','ACCEPTEE','REFUSEE','ANNULEE'];
    if($status!==''&&!in_array($status,$allowed,true))jsonResponse(false,'Statut invalide.',[],422);

    $where=['p.host_etablissement_id=?'];$params=[$eid];
    if($status!==''){$where[]='p.statut=?';$params[]=$status;}

    $s=$pdo->prepare("\n        SELECT p.id,p.university_campaign_id,p.host_campaign_id,p.statut,
               p.capacite_demandee,p.capacite_proposee,p.capacite_acceptee,p.capacite_allouee,
               p.date_debut,p.date_fin,p.conditions,p.motif_refus,p.frais_requis,p.montant_frais,p.devise,
               p.requested_at,p.responded_at,p.finalized_at,
               c.code university_campaign_code,c.titre university_campaign_title,
               st.code stage_type_code,st.libelle stage_type_libelle,
               u.nom university_name,
               hc.code host_campaign_code,hc.titre host_campaign_title
        FROM stage_campaign_participations p
        JOIN stage_campaigns c ON c.id=p.university_campaign_id AND c.type_campagne='UNIVERSITAIRE'
        JOIN stage_types st ON st.id=c.stage_type_id
        JOIN etablissements u ON u.id=c.owner_etablissement_id
        LEFT JOIN stage_campaigns hc ON hc.id=p.host_campaign_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY COALESCE(p.requested_at,p.created_at) DESC,p.id DESC
    ");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("\n        SELECT COUNT(*) total,
               COALESCE(SUM(statut='SOLLICITEE'),0) new_count,
               COALESCE(SUM(statut='EN_ETUDE'),0) studying,
               COALESCE(SUM(statut='ACCEPTEE'),0) accepted
        FROM stage_campaign_participations
        WHERE host_etablissement_id=?
    ");
    $s->execute([$eid]);$k=$s->fetch(PDO::FETCH_ASSOC)?:[];

    $s=$pdo->prepare("\n        SELECT id,code,titre,date_debut,date_fin,statut
        FROM stage_campaigns
        WHERE owner_etablissement_id=? AND type_campagne='ACCUEIL'
          AND statut NOT IN('ANNULEE','TERMINEE')
        ORDER BY date_debut DESC,id DESC
    ");
    $s->execute([$eid]);$hostCampaigns=$s->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'items'=>$items,'host_campaigns'=>$hostCampaigns,
        'kpi'=>[
            'total'=>(int)($k['total']??0),'new_count'=>(int)($k['new_count']??0),
            'studying'=>(int)($k['studying']??0),'accepted'=>(int)($k['accepted']??0)
        ]
    ]);
}catch(Throwable $e){
    error_log('[HOST SOLICITATIONS] '.$e->getMessage());
    jsonResponse(false,'Erreur Sollicitations : '.$e->getMessage(),[],500);
}

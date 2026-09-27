<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';
require_once __DIR__.'/../../includes/stage-type.php';

try{
    requirePermission($pdo,'campaign.hosting.view');
    if(!contextHostEnabled())
        jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

    $eid=stageTypeEtablissementId($pdo);

    $s=$pdo->prepare("
        SELECT
            c.id,c.code,c.titre,c.description,c.objectif_stage,
            c.stage_type_id,c.date_debut,c.date_fin,c.configuration,
            c.statut,c.published_at,c.created_at,c.cancellation_reason,
            st.code stage_type_code,st.libelle stage_type_libelle,
            st.owner_etablissement_id stage_type_owner_id,
            (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.host_campaign_id=c.id) participations_count,
            (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.host_campaign_id=c.id AND p.statut='ACCEPTEE') accepted_count
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        WHERE c.owner_etablissement_id=?
          AND c.type_campagne='ACCUEIL'
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $s->execute([$eid]);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$x){
        $x['id']=(int)$x['id'];
        $x['stage_type_id']=(int)$x['stage_type_id'];
        $x['participations_count']=(int)$x['participations_count'];
        $x['accepted_count']=(int)$x['accepted_count'];
        $x['configuration']=campaignConfig($x['configuration']);
        $x['financial']=campaignFinancialConfig($x['configuration']);
        $x['stage_type_local']=$x['stage_type_owner_id']!==null && (int)$x['stage_type_owner_id']===$eid;
    }unset($x);

    $s=$pdo->prepare("
        SELECT id,code,libelle,owner_etablissement_id
        FROM stage_types
        WHERE actif=1
          AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?)
        ORDER BY owner_etablissement_id IS NULL,libelle,id
    ");
    $s->execute([$eid]);
    $types=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($types as &$t){
        $t['id']=(int)$t['id'];
        $t['owner_etablissement_id']=$t['owner_etablissement_id']!==null?(int)$t['owner_etablissement_id']:null;
        $t['local']=$t['owner_etablissement_id']!==null && (int)$t['owner_etablissement_id']===$eid;
        $t['legacy']=$t['owner_etablissement_id']===null;
        $t['policies']=stagePolicySnapshot($pdo,$t['id']);
        $t['financial']=stageTypeFinancialFromPolicies($t['policies'],$t['legacy']);
    }unset($t);

    jsonResponse(true,'',[
        'items'=>$items,
        'types'=>$types,
        'permissions'=>[
            'create'=>hasPermission($pdo,'campaign.hosting.create'),
            'update'=>hasPermission($pdo,'campaign.hosting.update'),
            'publish'=>hasPermission($pdo,'campaign.hosting.publish'),
            'types_manage'=>stageTypeCanManage($pdo)
        ]
    ]);
}catch(Throwable $e){
    jsonResponse(false,"Erreur Campagnes d'accueil : ".$e->getMessage(),[],500);
}

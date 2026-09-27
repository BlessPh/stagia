<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';
require_once __DIR__.'/../../includes/stage-host-campaign.php';

requirePermission($pdo,'campaign.hosting.update');
verifyAjaxCsrf();

if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);
$eid=currentEtablissementId($pdo)?:((int)($_SESSION['etablissement_id']??0));
$id=(int)($_POST['id']??0);
if(!$eid||!$id)jsonResponse(false,'Campagne invalide.',[],422);

try{
    $v=validateHostCampaignPayload($pdo,$eid,$_POST);
    $pdo->beginTransaction();
    $c=hostCampaign($pdo,$id,$eid,true);

    if($c['statut']!=='BROUILLON')
        throw new RuntimeException("Seule une campagne d'accueil en brouillon peut être modifiée.");

    if((int)$c['stage_type_id']!==$v['stage_type_id']){
        $s=$pdo->prepare("SELECT COUNT(*) FROM stage_campaign_participations WHERE host_campaign_id=?");
        $s->execute([$id]);
        if((int)$s->fetchColumn()>0)
            throw new RuntimeException('Le type de stage ne peut plus être changé car des participations sont déjà liées à cette campagne.');
    }

    /* Snapshot financier : conserver celui de la campagne si le type ne change pas. */
    $oldConfig=campaignConfig($c['configuration']);
    $financial=((int)$c['stage_type_id']===$v['stage_type_id'] && isset($oldConfig['financial']) && is_array($oldConfig['financial']))
        ?$oldConfig['financial']
        :$v['financial'];
    $config=hostCampaignMergeFinancialConfig($c['configuration'],$financial);

    $pdo->prepare("
        UPDATE stage_campaigns
        SET stage_type_id=?,titre=?,description=?,objectif_stage=?,
            date_debut=?,date_fin=?,configuration=?
        WHERE id=? AND owner_etablissement_id=? AND type_campagne='ACCUEIL'
    ")->execute([
        $v['stage_type_id'],$v['titre'],$v['description'],$v['objectif_stage'],
        $v['date_debut'],$v['date_fin'],
        json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $id,$eid
    ]);

    campaignStatusHistory(
        $pdo,$id,'BROUILLON','BROUILLON',
        "Configuration de la campagne d'accueil mise à jour.",
        (int)($_SESSION['user_id']??0)?:null
    );

    $pdo->commit();
    jsonResponse(true,"Campagne d'accueil mise à jour.");
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

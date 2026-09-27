<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';
require_once __DIR__.'/../../includes/stage-host-campaign.php';

requirePermission($pdo,'campaign.hosting.publish');
verifyAjaxCsrf();

if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);
$eid=currentEtablissementId($pdo)?:((int)($_SESSION['etablissement_id']??0));
$id=(int)($_POST['id']??0);
$action=strtoupper(trim((string)($_POST['action']??'')));
$reason=trim((string)($_POST['reason']??''));

if(!$eid||!$id||!in_array($action,['PUBLISH','CLOSE','CANCEL'],true))
    jsonResponse(false,'Action invalide.',[],422);

try{
    $pdo->beginTransaction();
    $c=hostCampaign($pdo,$id,$eid,true);
    $old=$c['statut'];$new=$old;$message='';
    $userId=(int)($_SESSION['user_id']??0)?:null;

    if($action==='PUBLISH'){
        if($old!=='BROUILLON')throw new RuntimeException('Seul un brouillon peut être publié.');

        $config=campaignConfig($c['configuration']);
        $config['policy_snapshot']=stagePolicySnapshot($pdo,(int)$c['stage_type_id']);
        $config['configuration_frozen_at']=date('c');
        $new='OUVERTE';

        $pdo->prepare("
            UPDATE stage_campaigns
            SET statut='OUVERTE',configuration=?,published_by_user_id=?,published_at=NOW()
            WHERE id=? AND owner_etablissement_id=? AND type_campagne='ACCUEIL'
        ")->execute([
            json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $userId,$id,$eid
        ]);
        $message="Campagne d'accueil ouverte aux participations compatibles.";
    }

    if($action==='CLOSE'){
        if($old!=='OUVERTE')throw new RuntimeException('Seule une campagne ouverte peut être clôturée.');
        $new='CLOTUREE';
        $pdo->prepare("
            UPDATE stage_campaigns SET statut='CLOTUREE'
            WHERE id=? AND owner_etablissement_id=? AND type_campagne='ACCUEIL'
        ")->execute([$id,$eid]);
        $message="Campagne d'accueil clôturée.";
    }

    if($action==='CANCEL'){
        if(in_array($old,['ANNULEE','TERMINEE'],true))
            throw new RuntimeException('Cette campagne ne peut plus être annulée.');
        if($reason==='')throw new RuntimeException("Le motif d'annulation est obligatoire.");

        $s=$pdo->prepare("
            SELECT COUNT(*)
            FROM stage_campaign_participations
            WHERE host_campaign_id=?
              AND statut='ACCEPTEE'
              AND capacite_acceptee IS NOT NULL
        ");
        $s->execute([$id]);
        if((int)$s->fetchColumn()>0)
            throw new RuntimeException('Impossible d’annuler : des engagements universitaires sont déjà finalisés.');

        $new='ANNULEE';
        $pdo->prepare("
            UPDATE stage_campaigns
            SET statut='ANNULEE',cancelled_by_user_id=?,cancelled_at=NOW(),cancellation_reason=?
            WHERE id=? AND owner_etablissement_id=? AND type_campagne='ACCUEIL'
        ")->execute([$userId,$reason,$id,$eid]);
        $message="Campagne d'accueil annulée.";
    }

    campaignStatusHistory($pdo,$id,$old,$new,$reason!==''?$reason:$message,$userId);
    $pdo->commit();
    jsonResponse(true,$message,['statut'=>$new]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';
require_once __DIR__.'/../../includes/stage-host-campaign.php';

requirePermission($pdo,'campaign.hosting.create');
verifyAjaxCsrf();

if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);
$eid=currentEtablissementId($pdo)?:((int)($_SESSION['etablissement_id']??0));
if(!$eid)jsonResponse(false,'Aucun établissement d’accueil actif.',[],403);

try{
    $v=validateHostCampaignPayload($pdo,$eid,$_POST);
    $config=hostCampaignMergeFinancialConfig(null,$v['financial']);
    $pdo->beginTransaction();

    $pdo->prepare("
        INSERT INTO stage_campaigns(
            uuid,code,stage_type_id,owner_etablissement_id,owner_faculte_id,
            annee_academique_id,type_campagne,titre,description,objectif_stage,
            date_debut,date_fin,ouverture_candidatures,cloture_candidatures,
            configuration,statut,created_by_user_id
        ) VALUES(
            ?,NULL,?,?,NULL,NULL,'ACCUEIL',?,?,?, ?,?,NULL,NULL,?,'BROUILLON',?
        )
    ")->execute([
        stageUuidV4(),$v['stage_type_id'],$eid,$v['titre'],$v['description'],$v['objectif_stage'],
        $v['date_debut'],$v['date_fin'],
        json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        (int)($_SESSION['user_id']??0)?:null
    ]);

    $id=(int)$pdo->lastInsertId();
    $code='ACC-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
    $pdo->prepare("UPDATE stage_campaigns SET code=? WHERE id=? AND owner_etablissement_id=?")
        ->execute([$code,$id,$eid]);

    campaignStatusHistory(
        $pdo,$id,null,'BROUILLON',
        "Création de la session d'accueil.",
        (int)($_SESSION['user_id']??0)?:null
    );

    $pdo->commit();
    jsonResponse(true,"Session d'accueil $code créée en brouillon.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

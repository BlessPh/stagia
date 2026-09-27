<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';

requirePermission($pdo,'campaign.university.create');
verifyAjaxCsrf();
if(!contextAcademicEnabled())jsonResponse(false,"Cet établissement n'est pas configuré comme établissement de formation.",[],403);

$eid=(int)($_SESSION['etablissement_id']??0);
if(!$eid)jsonResponse(false,'Aucun établissement académique actif.',[],403);

try{
    $data=$_POST;$data['promotion_ids']=$_POST['promotion_ids']??[];
    $v=validateUniversityCampaignPayload($pdo,$eid,$data);
    $requirements=parseCampaignRequirements((string)($_POST['requirements_text']??''));
    $hosts=campaignSelectedHosts($pdo,(int)$v['stage_type_id']);$demands=[];

    foreach($hosts as $hid){
        $n=(int)($_POST['host_capacity'][$hid]??0);
        if($n<1)throw new RuntimeException("Indiquez le nombre de places souhaitées pour chaque hôpital sélectionné.");
        $demands[(string)$hid]=$n;
    }

    $v['configuration']['selected_host_ids']=$hosts;
    $v['configuration']['selected_host_demands']=$demands;
    $v['configuration']['hosting_mode']=$hosts?'SOLICITATION':'NONE';

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO stage_campaigns(uuid,code,stage_type_id,owner_etablissement_id,owner_faculte_id,annee_academique_id,type_campagne,titre,description,objectif_stage,date_debut,date_fin,ouverture_candidatures,cloture_candidatures,configuration,statut,created_by_user_id)
                   VALUES(?,NULL,?,?,?,?, 'UNIVERSITAIRE',?,?,?,?,?,?,?,?,'BROUILLON',?)")
        ->execute([stageUuidV4(),$v['stage_type_id'],$eid,$v['owner_faculte_id'],$v['annee_academique_id'],$v['titre'],$v['description'],$v['objectif_stage'],$v['date_debut'],$v['date_fin'],$v['ouverture_candidatures'],$v['cloture_candidatures'],json_encode($v['configuration'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)($_SESSION['user_id']??0)?:null]);

    $id=(int)$pdo->lastInsertId();$code='CAM-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
    $pdo->prepare("UPDATE stage_campaigns SET code=? WHERE id=? AND owner_etablissement_id=?")->execute([$code,$id,$eid]);

    $ins=$pdo->prepare("INSERT INTO stage_campaign_promotions(campaign_id,promotion_id,stage_type_id,promotion_stage_config_id) VALUES(?,?,?,?)");
    foreach($v['promotions'] as $p)$ins->execute([$id,(int)$p['id'],$v['stage_type_id'],(int)($p['promotion_stage_config_id']??0)?:null]);

    $req=$pdo->prepare("INSERT INTO stage_campaign_requirements(campaign_id,requirement_type,code,libelle,obligatoire,ordre) VALUES(?,'DOCUMENT',?,?,1,?)");
    foreach($requirements as $i=>$label)$req->execute([$id,'DOC-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),$label,($i+1)*10]);

    campaignStatusHistory($pdo,$id,null,'BROUILLON','Création de la campagne universitaire.',(int)($_SESSION['user_id']??0)?:null);
    $pdo->commit();jsonResponse(true,"Campagne $code créée en brouillon.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';

requirePermission($pdo,'campaign.university.update');
verifyAjaxCsrf();
if(!contextAcademicEnabled())jsonResponse(false,"Cet établissement n'est pas configuré comme établissement de formation.",[],403);

$eid=(int)($_SESSION['etablissement_id']??0);$id=(int)($_POST['id']??0);
if(!$eid||!$id)jsonResponse(false,'Campagne invalide.',[],422);

try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT id,statut,stage_type_id,configuration FROM stage_campaigns WHERE id=? AND owner_etablissement_id=? AND type_campagne='UNIVERSITAIRE' LIMIT 1 FOR UPDATE");
    $s->execute([$id,$eid]);$current=$s->fetch(PDO::FETCH_ASSOC);
    if(!$current)throw new RuntimeException('Campagne introuvable.');
    if($current['statut']!=='BROUILLON')throw new RuntimeException('Seule une campagne en brouillon peut modifier sa configuration structurante.');

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

    if((int)$current['stage_type_id']===(int)$v['stage_type_id']){
        $old=campaignConfig($current['configuration']);
        if(isset($old['financial'])&&is_array($old['financial'])){
            $v['configuration']['financial']=$old['financial'];
            $v['configuration']['payment_expected']=(bool)($old['financial']['required']??$old['payment_expected']??false);
        }
    }

    $pdo->prepare("UPDATE stage_campaigns SET stage_type_id=?,owner_faculte_id=?,annee_academique_id=?,titre=?,description=?,objectif_stage=?,date_debut=?,date_fin=?,ouverture_candidatures=?,cloture_candidatures=?,configuration=? WHERE id=? AND owner_etablissement_id=?")
        ->execute([$v['stage_type_id'],$v['owner_faculte_id'],$v['annee_academique_id'],$v['titre'],$v['description'],$v['objectif_stage'],$v['date_debut'],$v['date_fin'],$v['ouverture_candidatures'],$v['cloture_candidatures'],json_encode($v['configuration'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id,$eid]);

    $pdo->prepare("DELETE FROM stage_campaign_promotions WHERE campaign_id=?")->execute([$id]);
    $ins=$pdo->prepare("INSERT INTO stage_campaign_promotions(campaign_id,promotion_id,stage_type_id,promotion_stage_config_id) VALUES(?,?,?,?)");
    foreach($v['promotions'] as $p)$ins->execute([$id,(int)$p['id'],$v['stage_type_id'],(int)($p['promotion_stage_config_id']??0)?:null]);

    $pdo->prepare("DELETE FROM stage_campaign_requirements WHERE campaign_id=?")->execute([$id]);
    $req=$pdo->prepare("INSERT INTO stage_campaign_requirements(campaign_id,requirement_type,code,libelle,obligatoire,ordre) VALUES(?,'DOCUMENT',?,?,1,?)");
    foreach($requirements as $i=>$label)$req->execute([$id,'DOC-'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),$label,($i+1)*10]);

    campaignStatusHistory($pdo,$id,'BROUILLON','BROUILLON','Configuration du brouillon mise à jour.',(int)($_SESSION['user_id']??0)?:null);
    $pdo->commit();jsonResponse(true,'Campagne mise à jour.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

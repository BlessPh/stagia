<?php
/**
 * Endpoint AJAX de modification d'un plan de formation en brouillon.
 * La campagne ou le référentiel sont verrouillés dès lors que le plan possède des éléments.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-training.php';
verifyAjaxCsrf();
try{
    /* Le plan est verrouillé dans une transaction avant la vérification de son état et de sa campagne. */
    requireTrainingPlanAccess($pdo,'update');$eid=trainingEtablissementId($pdo);$id=(int)($_POST['id']??0);$campaignId=(int)($_POST['campaign_id']??0);$refId=(int)($_POST['referential_id']??0);$title=trim((string)($_POST['titre']??''));$desc=trim((string)($_POST['description']??''));
    if($title===''||mb_strlen($title)>200)throw new RuntimeException('Le titre est obligatoire et limité à 200 caractères.');if(mb_strlen($desc)>3000)throw new RuntimeException('La description est limitée à 3000 caractères.');
    $pdo->beginTransaction();$p=trainingPlan($pdo,$id,$eid,true);if($p['statut']!=='BROUILLON')throw new RuntimeException('Seul un plan en brouillon peut être modifié.');$types=trainingAllowedCampaignTypes($pdo,'update');$c=trainingCampaign($pdo,$campaignId,$eid,$types,true);if(in_array($c['statut'],['ANNULEE','TERMINEE'],true))throw new RuntimeException("Cette campagne n'accepte plus la modification du plan.");trainingValidateReferential($pdo,$refId,$eid,(int)$c['stage_type_id']);$s=$pdo->prepare("SELECT COUNT(*) FROM stage_training_plan_items WHERE plan_id=?");$s->execute([$id]);if((int)$s->fetchColumn()>0&&((int)$p['campaign_id']!==$campaignId||(int)($p['referential_id']??0)!==$refId))throw new RuntimeException('Le plan contient déjà des éléments. La campagne ou le référentiel ne peuvent plus être changés.');
    $s=$pdo->prepare("SELECT id FROM stage_training_plans WHERE campaign_id=? AND id<>? AND statut<>'ARCHIVE' LIMIT 1");$s->execute([$campaignId,$id]);if($s->fetchColumn())throw new RuntimeException('Cette campagne possède déjà un autre plan de formation actif.');
    $pdo->prepare("UPDATE stage_training_plans SET campaign_id=?,referential_id=?,titre=?,description=? WHERE id=?")->execute([$campaignId,$refId?:null,$title,$desc?:null,$id]);$pdo->commit();jsonResponse(true,'Plan de formation modifié.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

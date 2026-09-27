<?php
/**
 * Endpoint AJAX de création d'un plan de formation en brouillon.
 * Il vérifie l'accès, la campagne, le référentiel compatible et l'unicité d'un plan actif par campagne.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-training.php';
verifyAjaxCsrf();
try{
    /* Le service métier centralise les droits et les types de campagnes accessibles à l'acteur. */
    requireTrainingPlanAccess($pdo,'create');$eid=trainingEtablissementId($pdo);$uid=(int)($_SESSION['user_id']??0)?:null;$campaignId=(int)($_POST['campaign_id']??0);$refId=(int)($_POST['referential_id']??0);$title=trim((string)($_POST['titre']??''));$desc=trim((string)($_POST['description']??''));
    if($title===''||mb_strlen($title)>200)throw new RuntimeException('Le titre est obligatoire et limité à 200 caractères.');if(mb_strlen($desc)>3000)throw new RuntimeException('La description est limitée à 3000 caractères.');
    $types=trainingAllowedCampaignTypes($pdo,'create');$c=trainingCampaign($pdo,$campaignId,$eid,$types);if(in_array($c['statut'],['ANNULEE','TERMINEE'],true))throw new RuntimeException("Cette campagne n'accepte plus de nouveau plan de formation.");trainingValidateReferential($pdo,$refId,$eid,(int)$c['stage_type_id']);
    $s=$pdo->prepare("SELECT id FROM stage_training_plans WHERE campaign_id=? AND statut<>'ARCHIVE' LIMIT 1");$s->execute([$campaignId]);if($s->fetchColumn())throw new RuntimeException('Cette campagne possède déjà un plan de formation actif. Modifiez-le ou archivez-le avant d’en créer un autre.');
    $pdo->prepare("INSERT INTO stage_training_plans(uuid,campaign_id,referential_id,titre,description,statut,created_by_user_id) VALUES(?,?,?,?,?,'BROUILLON',?)")->execute([trainingUuidV4(),$campaignId,$refId?:null,$title,$desc?:null,$uid]);$id=(int)$pdo->lastInsertId();jsonResponse(true,'Plan de formation créé en brouillon.',['id'=>$id]);
}catch(Throwable $e){jsonResponse(false,$e->getMessage(),[],422);}

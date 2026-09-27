<?php
/**
 * Endpoint AJAX de retrait ou réactivation logique d'un élément pédagogique.
 * L'élément historique est conservé et le plan publié ou archivé reste verrouillé.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-training.php';
verifyAjaxCsrf();
try{
    /* DISABLE et RESTORE modifient seulement le drapeau actif après vérification du plan parent. */
    requireTrainingPlanAccess($pdo,'update');$eid=trainingEtablissementId($pdo);$id=(int)($_POST['id']??0);$action=strtoupper(trim((string)($_POST['action']??'')));if(!$id||!in_array($action,['DISABLE','RESTORE'],true))throw new RuntimeException('Action invalide.');$i=trainingItem($pdo,$id,$eid);if($i['plan_statut']!=='BROUILLON')throw new RuntimeException('Le contenu d’un plan publié ou archivé est verrouillé.');$active=$action==='RESTORE'?1:0;$pdo->prepare("UPDATE stage_training_plan_items SET actif=? WHERE id=?")->execute([$active,$id]);jsonResponse(true,$active?'Élément réactivé.':'Élément retiré du plan.');
}catch(Throwable $e){jsonResponse(false,$e->getMessage(),[],422);}

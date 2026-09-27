<?php
/**
 * Endpoint AJAX de publication ou archivage d'un plan de formation.
 * La publication verrouille le contenu après contrôle qu'au moins un élément actif existe.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-training.php';
verifyAjaxCsrf();
try{
    /* La transition est traitée sous transaction avec le plan verrouillé. */
    requireTrainingPlanAccess($pdo,'publish');$eid=trainingEtablissementId($pdo);$uid=(int)($_SESSION['user_id']??0)?:null;$id=(int)($_POST['id']??0);$action=strtoupper(trim((string)($_POST['action']??'')));if(!$id||!in_array($action,['PUBLISH','ARCHIVE'],true))throw new RuntimeException('Action invalide.');
    $pdo->beginTransaction();$p=trainingPlan($pdo,$id,$eid,true);
    if($action==='PUBLISH'){
        if($p['statut']!=='BROUILLON')throw new RuntimeException('Seul un plan en brouillon peut être publié.');$s=$pdo->prepare("SELECT COUNT(*) FROM stage_training_plan_items WHERE plan_id=? AND actif=1");$s->execute([$id]);if((int)$s->fetchColumn()<1)throw new RuntimeException('Ajoutez au moins un élément actif avant de publier le plan.');$pdo->prepare("UPDATE stage_training_plans SET statut='PUBLIE',published_by_user_id=?,published_at=NOW() WHERE id=?")->execute([$uid,$id]);$msg='Plan de formation publié. Il est maintenant verrouillé.';
    }else{
        if($p['statut']==='ARCHIVE')throw new RuntimeException('Ce plan est déjà archivé.');$pdo->prepare("UPDATE stage_training_plans SET statut='ARCHIVE' WHERE id=?")->execute([$id]);$msg='Plan de formation archivé.';
    }
    $pdo->commit();jsonResponse(true,$msg);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

<?php
/**
 * Endpoint AJAX de modification d'un élément d'un plan de formation.
 * Le contenu reste éditable uniquement tant que le plan associé est à l'état BROUILLON.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-training.php';
verifyAjaxCsrf();
try{
    /* L'élément, son plan et ses règles de campagne sont vérifiés dans une transaction unique. */
    requireTrainingPlanAccess($pdo,'update');$eid=trainingEtablissementId($pdo);$id=(int)($_POST['id']??0);$type=strtoupper(trim((string)($_POST['type_item']??'ACTIVITE')));$title=trim((string)($_POST['titre']??''));$desc=trim((string)($_POST['description']??''));$objectiveId=(int)($_POST['referential_objective_id']??0);$unitId=(int)($_POST['host_unit_id']??0);$responsibleId=(int)($_POST['responsible_user_id']??0);$order=max(1,(int)($_POST['ordre']??1));$required=!empty($_POST['obligatoire'])?1:0;
    if(!in_array($type,['MODULE','OBJECTIF','ACTIVITE','ETAPE'],true))throw new RuntimeException("Type d'élément invalide.");if($title===''||mb_strlen($title)>200)throw new RuntimeException('Le titre est obligatoire et limité à 200 caractères.');if(mb_strlen($desc)>3000)throw new RuntimeException('La description est limitée à 3000 caractères.');
    $pdo->beginTransaction();$i=trainingItem($pdo,$id,$eid,true);if($i['plan_statut']!=='BROUILLON')throw new RuntimeException('Le contenu d’un plan publié ou archivé est verrouillé.');trainingValidateObjective($pdo,$objectiveId,$i['referential_id']!==null?(int)$i['referential_id']:null);trainingValidateHostUnit($pdo,$unitId,$eid);trainingValidateResponsible($pdo,$responsibleId,$eid);[$start,$end]=trainingValidateItemDates($_POST['date_debut_prevue']??null,$_POST['date_fin_prevue']??null,$i['campaign_start'],$i['campaign_end']);
    $pdo->prepare("UPDATE stage_training_plan_items SET referential_objective_id=?,host_unit_id=?,responsible_user_id=?,ordre=?,type_item=?,titre=?,description=?,date_debut_prevue=?,date_fin_prevue=?,obligatoire=? WHERE id=?")->execute([$objectiveId?:null,$unitId?:null,$responsibleId?:null,$order,$type,$title,$desc?:null,$start,$end,$required,$id]);$pdo->commit();jsonResponse(true,'Élément du plan modifié.');
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

<?php
/**
 * Endpoint AJAX d'ajout d'un élément pédagogique à un plan de formation brouillon.
 * Les objectifs, responsables, unités et dates sont validés par rapport au plan et à sa campagne.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-training.php';
verifyAjaxCsrf();
try{
    /* Le plan est verrouillé avant la génération éventuelle de l'ordre et l'insertion de l'élément. */
    requireTrainingPlanAccess($pdo,'update');$eid=trainingEtablissementId($pdo);$planId=(int)($_POST['plan_id']??0);$type=strtoupper(trim((string)($_POST['type_item']??'ACTIVITE')));$title=trim((string)($_POST['titre']??''));$desc=trim((string)($_POST['description']??''));$objectiveId=(int)($_POST['referential_objective_id']??0);$unitId=(int)($_POST['host_unit_id']??0);$responsibleId=(int)($_POST['responsible_user_id']??0);$order=max(0,(int)($_POST['ordre']??0));$required=!empty($_POST['obligatoire'])?1:0;
    if(!in_array($type,['MODULE','OBJECTIF','ACTIVITE','ETAPE'],true))throw new RuntimeException("Type d'élément invalide.");if($title===''||mb_strlen($title)>200)throw new RuntimeException('Le titre est obligatoire et limité à 200 caractères.');if(mb_strlen($desc)>3000)throw new RuntimeException('La description est limitée à 3000 caractères.');
    $pdo->beginTransaction();$p=trainingPlan($pdo,$planId,$eid,true);if($p['statut']!=='BROUILLON')throw new RuntimeException('Le contenu d’un plan publié ou archivé est verrouillé.');trainingValidateObjective($pdo,$objectiveId,$p['referential_id']!==null?(int)$p['referential_id']:null);trainingValidateHostUnit($pdo,$unitId,$eid);trainingValidateResponsible($pdo,$responsibleId,$eid);[$start,$end]=trainingValidateItemDates($_POST['date_debut_prevue']??null,$_POST['date_fin_prevue']??null,$p['campaign_start'],$p['campaign_end']);
    if(!$order){$s=$pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM stage_training_plan_items WHERE plan_id=?");$s->execute([$planId]);$order=(int)$s->fetchColumn();}
    $pdo->prepare("INSERT INTO stage_training_plan_items(plan_id,referential_objective_id,host_unit_id,responsible_user_id,ordre,type_item,titre,description,date_debut_prevue,date_fin_prevue,obligatoire,actif) VALUES(?,?,?,?,?,?,?,?,?,?,?,1)")->execute([$planId,$objectiveId?:null,$unitId?:null,$responsibleId?:null,$order,$type,$title,$desc?:null,$start,$end,$required]);$id=(int)$pdo->lastInsertId();$pdo->commit();jsonResponse(true,'Élément ajouté au plan.',['id'=>$id]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

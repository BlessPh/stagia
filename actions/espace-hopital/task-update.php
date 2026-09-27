<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-task.php';
requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','EVALUATEUR_CLINIQUE']);
if(function_exists('verifyAjaxCsrf'))verifyAjaxCsrf();
try{
    stageTaskEnsureSchema($pdo);
    $hostId=(int)currentEtablissementId($pdo);$userId=(int)($_SESSION['user_id']??0);$role=$_SESSION['role_code']??'';
    $id=(int)($_POST['id']??0);$task=stageTaskRow($pdo,$hostId,$id,$userId,$role);
    if(!$task)jsonResponse(false,'Tâche introuvable ou non autorisée.',[],404);
    if(!in_array($task['statut'],['A_FAIRE','A_REVOIR'],true))jsonResponse(false,'Cette tâche ne peut plus être modifiée.',[],422);
    $titre=trim($_POST['titre']??'');if($titre==='')jsonResponse(false,'Titre obligatoire.',[],422);
    $rotationId=(int)($_POST['rotation_id']??0);$rotationId=$rotationId>0?$rotationId:null;
    $priorite=in_array($_POST['priorite']??'NORMALE',['BASSE','NORMALE','HAUTE','URGENTE'],true)?$_POST['priorite']:'NORMALE';
    $due=trim($_POST['date_echeance']??'');$due=$due!==''?$due:null;
    $stmt=$pdo->prepare("UPDATE stage_tasks SET rotation_id=?,training_plan_item_id=?,updated_by=?,titre=?,description=?,priorite=?,date_echeance=? WHERE id=? AND host_etablissement_id=?");
    $stmt->execute([$rotationId,($_POST['training_plan_item_id']??'')!==''?(int)$_POST['training_plan_item_id']:null,$userId,$titre,trim($_POST['description']??''),$priorite,$due,$id,$hostId]);
    jsonResponse(true,'Tâche modifiée.');
}catch(Throwable $e){error_log('[TASK UPDATE] '.$e->getMessage());jsonResponse(false,'Erreur modification tâche : '.$e->getMessage(),[],500);}

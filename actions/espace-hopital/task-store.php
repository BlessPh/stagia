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
    if(!$hostId||!taskHostCanManage())jsonResponse(false,'Accès refusé.',[],403);
    $assignmentId=(int)($_POST['assignment_id']??0);$row=stageTaskAssignmentRow($pdo,$hostId,$assignmentId,$userId,$role);
    if(!$row)jsonResponse(false,'Affectation introuvable ou non autorisée.',[],404);
    $titre=trim($_POST['titre']??'');if($titre==='')jsonResponse(false,'Titre obligatoire.',[],422);
    $rotationId=(int)($_POST['rotation_id']??0);$rotationId=$rotationId>0?$rotationId:null;
    if($rotationId){
        $p=[$rotationId,$assignmentId,$hostId];$sql="SELECT COUNT(*) FROM stage_rotations WHERE id=? AND assignment_id=? AND host_etablissement_id=?";
        if(stageTaskClinicalRole($role)){$sql.=" AND EXISTS(SELECT 1 FROM stage_rotation_supervisors s WHERE s.rotation_id=stage_rotations.id AND s.user_id=? AND s.actif=1)";$p[]=$userId;}
        $s=$pdo->prepare($sql);$s->execute($p);if(!(int)$s->fetchColumn())jsonResponse(false,'Rotation non autorisée.',[],403);
    }
    $priorite=in_array($_POST['priorite']??'NORMALE',['BASSE','NORMALE','HAUTE','URGENTE'],true)?$_POST['priorite']:'NORMALE';
    $due=trim($_POST['date_echeance']??'');$due=$due!==''?$due:null;
    $stmt=$pdo->prepare("INSERT INTO stage_tasks(uuid,host_etablissement_id,assignment_id,rotation_id,training_plan_item_id,student_id,created_by,titre,description,priorite,date_echeance)
        VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([stageTaskUuid(),$hostId,$assignmentId,$rotationId,($_POST['training_plan_item_id']??'')!==''?(int)$_POST['training_plan_item_id']:null,(int)$row['student_id'],$userId,$titre,trim($_POST['description']??''),$priorite,$due]);
    jsonResponse(true,'Tâche créée.',['id'=>(int)$pdo->lastInsertId()]);
}catch(Throwable $e){error_log('[TASK STORE] '.$e->getMessage());jsonResponse(false,'Erreur création tâche : '.$e->getMessage(),[],500);}

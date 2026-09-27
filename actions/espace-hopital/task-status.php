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
    $id=(int)($_POST['id']??0);$action=$_POST['action']??'';$comment=trim($_POST['commentaire']??'');
    $task=stageTaskRow($pdo,$hostId,$id,$userId,$role);if(!$task)jsonResponse(false,'Tâche introuvable ou non autorisée.',[],404);
    if($action==='VALIDATE'){
        if($task['statut']!=='TERMINEE')jsonResponse(false,'La tâche doit être terminée par le stagiaire.',[],422);
        $s=$pdo->prepare("UPDATE stage_tasks SET statut='VALIDEE',commentaire_encadreur=?,validated_at=NOW(),validated_by=?,updated_by=? WHERE id=? AND host_etablissement_id=?");
        $s->execute([$comment,$userId,$userId,$id,$hostId]);jsonResponse(true,'Tâche validée.');
    }elseif($action==='REVIEW'){
        if($task['statut']!=='TERMINEE')jsonResponse(false,'Seule une tâche terminée peut être renvoyée.',[],422);
        if($comment==='')jsonResponse(false,'Commentaire obligatoire pour demander une correction.',[],422);
        $s=$pdo->prepare("UPDATE stage_tasks SET statut='A_REVOIR',commentaire_encadreur=?,updated_by=? WHERE id=? AND host_etablissement_id=?");
        $s->execute([$comment,$userId,$id,$hostId]);jsonResponse(true,'Tâche renvoyée au stagiaire.');
    }elseif($action==='CANCEL'){
        if(in_array($task['statut'],['VALIDEE','ANNULEE'],true))jsonResponse(false,'Action impossible.',[],422);
        if($comment==='')jsonResponse(false,'Motif obligatoire.',[],422);
        $s=$pdo->prepare("UPDATE stage_tasks SET statut='ANNULEE',commentaire_encadreur=?,cancelled_at=NOW(),cancelled_by=?,updated_by=? WHERE id=? AND host_etablissement_id=?");
        $s->execute([$comment,$userId,$userId,$userId,$id,$hostId]);jsonResponse(true,'Tâche annulée.');
    }
    jsonResponse(false,'Action inconnue.',[],422);
}catch(Throwable $e){error_log('[TASK STATUS] '.$e->getMessage());jsonResponse(false,'Erreur statut tâche : '.$e->getMessage(),[],500);}

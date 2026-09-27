<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-task.php';

requireAjaxRole(['STAGIAIRE']);
if(function_exists('verifyAjaxCsrf'))verifyAjaxCsrf();
try{
    $userId=(int)($_SESSION['user_id']??0);$studentId=stageTaskStudentId($pdo,$userId);
    if(!$studentId)jsonResponse(false,'Profil etudiant introuvable.',[],403);
    $result=stageTaskStudentChange($pdo,$studentId,$userId,(string)(int)($_POST['id']??0),(string)($_POST['action']??''),(string)($_POST['commentaire']??''));
    jsonResponse(true,$result['message'],['status'=>$result['status']]);
}catch(OutOfBoundsException $e){
    jsonResponse(false,$e->getMessage(),[],404);
}catch(InvalidArgumentException|RuntimeException $e){
    jsonResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    error_log('[STUDENT TASK STATUS] '.$e->getMessage());
    jsonResponse(false,'Erreur statut tache : '.$e->getMessage(),[],500);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-task.php';

requireAjaxRole(['STAGIAIRE']);
try{
    $userId=(int)($_SESSION['user_id']??0);$studentId=stageTaskStudentId($pdo,$userId);
    if(!$studentId)jsonResponse(false,'Profil etudiant introuvable.',[],403);
    jsonResponse(true,'',stageTaskStudentList($pdo,$studentId,['status'=>$_GET['status']??'']));
}catch(Throwable $e){
    error_log('[STUDENT TASK LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur taches : '.$e->getMessage(),[],500);
}

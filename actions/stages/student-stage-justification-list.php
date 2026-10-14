<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-justification.php';
requireAjaxRole(['STAGIAIRE']);
try{
    $studentId=stageJustificationStudentId($pdo,(int)($_SESSION['user_id']??0));
    if(!$studentId)jsonResponse(false,'Profil stagiaire introuvable.',[],404);
    jsonResponse(true,'',stageJustificationListForStudent($pdo,$studentId,true));
}catch(Throwable $e){error_log('[STUDENT JUSTIFICATION LIST] '.$e->getMessage());jsonResponse(false,'Impossible de charger les justifications.',[],500);}

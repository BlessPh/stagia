<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-justification.php';
requireAjaxRole(['STAGIAIRE']);
$csrf=(string)($_POST['csrf']??'');if(empty($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],$csrf))jsonResponse(false,'Jeton de sécurité invalide.',[],419);
try{
    $uid=(int)($_SESSION['user_id']??0);$studentId=stageJustificationStudentId($pdo,$uid);if(!$studentId)jsonResponse(false,'Profil stagiaire introuvable.',[],404);
    $result=stageJustificationSubmit($pdo,$studentId,$uid,$_POST,$_FILES['document']??[]);jsonResponse(true,$result['message'],['justification'=>$result]);
}catch(InvalidArgumentException $e){jsonResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){error_log('[STUDENT JUSTIFICATION SAVE] '.$e->getMessage());jsonResponse(false,'Une erreur interne empêche l’envoi de la justification.',[],500);}

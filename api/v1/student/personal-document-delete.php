<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-personal-document-service.php';

$method=strtoupper($_SERVER['REQUEST_METHOD']??'');
if(!in_array($method,['DELETE','POST'],true)){header('Allow: DELETE, POST');apiResponse(false,'Methode HTTP non autorisee.',[],405);}
$student=requireApiStudent($pdo);$input=apiInput();$uuid=trim((string)($_GET['uuid']??$input['uuid']??''));
if($uuid==='')apiResponse(false,'Document invalide.',[],422);
try{
    if(!studentPersonalDocumentDelete($pdo,(int)$student['student_id'],$uuid))apiResponse(false,'Document introuvable.',[],404);
    apiResponse(true,'Document supprime avec succes.');
}catch(Throwable $e){apiResponse(false,'Erreur suppression : '.$e->getMessage(),[],500);}

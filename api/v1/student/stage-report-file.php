<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-stage-report-service.php';

requireApiMethod('GET');$student=requireApiStudent($pdo);$uuid=trim((string)($_GET['uuid']??''));
if($uuid==='')apiResponse(false,'Rapport invalide.',[],422);
try{
    $report=studentStageReportRecord($pdo,(int)$student['student_id'],$uuid);
    if(!$report)apiResponse(false,'Rapport introuvable.',[],404);
    $path=studentStageReportPath($report);if(!$path)apiResponse(false,'Fichier introuvable.',[],404);
    $filename=preg_replace('/[\r\n"]+/','',(string)$report['nom_original'])?:('rapport.'.($report['extension']??'pdf'));
    header('Content-Type: '.((string)$report['mime_type']?:'application/octet-stream'));header('Content-Length: '.filesize($path));
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$filename.'"');
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($path);exit;
}catch(Throwable $e){error_log('[STUDENT STAGE REPORT FILE] '.$e->getMessage());apiResponse(false,'Une erreur interne empeche le chargement du fichier.',[],500);}

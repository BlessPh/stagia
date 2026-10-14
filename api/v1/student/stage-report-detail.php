<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../includes/student-stage-report-service.php';

requireApiMethod('GET');$student=requireApiStudent($pdo);$uuid=trim((string)($_GET['uuid']??''));
if($uuid==='')apiResponse(false,'Rapport invalide.',[],422);
try{
    $report=studentStageReport($pdo,(int)$student['student_id'],$uuid);
    if(!$report)apiResponse(false,'Rapport introuvable.',[],404);
    $endpoint=rtrim(BASE_URL,'/').'/api/v1/student/stage-reports/'.rawurlencode($uuid);
    $report['detail_endpoint']=$endpoint;$report['file_endpoint']=$endpoint.'/file';$report['download_endpoint']=$endpoint.'/file?download=1';
    apiResponse(true,'',['report'=>$report]);
}catch(Throwable $e){error_log('[STUDENT STAGE REPORT DETAIL] '.$e->getMessage());apiResponse(false,'Une erreur interne empeche le chargement du rapport.',[],500);}

<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-application-workflow.php';

requireAjaxRole(['STAGIAIRE']);
verifyAjaxCsrf();

$userId=(int)($_SESSION['user_id']??0);
$campaignId=(int)($_POST['campaign_id']??0);
$academicId=(int)($_POST['academic_enrollment_id']??0);
$participationId=(int)($_POST['participation_id']??0);
$motivation=trim((string)($_POST['motivation']??''));

try{
    $student=d4StudentProfile($pdo,$userId);
    $result=submitStudentStageApplication(
        $pdo,(int)$student['id'],$userId,$campaignId,$academicId,$participationId,$motivation
    );
    jsonResponse(
        true,
        $result['created']
            ?'Candidature soumise. La place est réservée temporairement dans l’attente de la décision universitaire.'
            :'Cette réservation est déjà active.',
        $result,
        $result['created']?201:200
    );
}catch(InvalidArgumentException $e){
    jsonResponse(false,$e->getMessage(),[],422);
}catch(RuntimeException $e){
    jsonResponse(false,$e->getMessage(),[],409);
}catch(Throwable $e){
    error_log('[STUDENT STAGE RESERVE] '.$e->getMessage());
    jsonResponse(false,'Une erreur interne empêche la réservation.',[],500);
}

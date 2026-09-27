<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-application-workflow.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=apiInput();

$campaignId=(int)($input['campaign_id']??0);
$academicId=(int)($input['academic_enrollment_id']??0);
$participationId=(int)($input['participation_id']??0);
$motivation=trim((string)($input['motivation']??''));

try{
    $result=submitD4Application(
        $pdo,
        (int)$student['student_id'],
        (int)$student['user_id'],
        $campaignId,
        $academicId,
        $participationId,
        $motivation
    );
    apiResponse(
        true,
        $result['created']
            ?'Candidature soumise. La place est réservée temporairement dans l’attente de la décision universitaire.'
            :'Cette réservation est déjà active.',
        $result,
        $result['created']?201:200
    );
}catch(InvalidArgumentException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(RuntimeException $e){
    apiResponse(false,$e->getMessage(),[],409);
}catch(Throwable $e){
    error_log('[API STUDENT RESERVE] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche la réservation.',[],500);
}

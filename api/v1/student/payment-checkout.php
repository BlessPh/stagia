<?php
/**
 * Endpoint historique conservé pour compatibilité.
 * Il prépare désormais le paiement sans créer de transaction; l'application
 * doit ensuite appeler /student/payments/initiate avec un canal existant.
 */
require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-payment.php';

apiDeprecation('/api/v1/student/payments/initiate');

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=apiInput();
$reservationUuid=trim((string)($input['reservation_uuid']??''));

try{
    $state=synchronizeStudentStagePayment($pdo,(int)$student['student_id'],$reservationUuid);
    $state['channels']=studentPaymentChannels();
    $state['initiate_endpoint']='/api/v1/student/payments/initiate';
    apiResponse(true,'État du paiement préparé.',$state);
}catch(InvalidArgumentException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(DomainException $e){
    apiResponse(false,$e->getMessage(),[],409);
}catch(Throwable $e){
    error_log('[API PAYMENT CHECKOUT] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche la préparation du paiement.',[],500);
}

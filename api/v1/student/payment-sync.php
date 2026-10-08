<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-payment.php';
require_once __DIR__.'/../../../includes/payment/payment-workflow.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=apiInput();
$reservationUuid=trim((string)($input['reservation_uuid']??''));
$obligationUuid=trim((string)($input['obligation_uuid']??''));

try{
    $result=$obligationUuid!==''
        ?synchronizeFinancialPaymentWorkflow($pdo,(int)$student['user_id'],$obligationUuid)
        :synchronizeStudentStagePayment($pdo,(int)$student['student_id'],$reservationUuid);
    $status=(string)($result['payment_status']??'');
    $messages=[
        'PAID'=>'Paiement confirmé. La réservation attend le placement universitaire.',
        'NOT_REQUIRED'=>'Aucun paiement requis. La réservation attend le placement universitaire.',
        'PARTIAL'=>'Paiement partiel enregistré.',
        'PENDING'=>'Paiement en attente de confirmation.',
        'FAILED'=>'Le dernier paiement a échoué. Une nouvelle tentative peut être initiée.',
        'NOT_STARTED'=>'Aucun paiement n’a encore été initié.',
        'NOT_CONFIRMED'=>'Le paiement n’a pas été confirmé.'
    ];
    apiResponse(true,$messages[$status]??'État du paiement synchronisé.',$result);
}catch(InvalidArgumentException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(DomainException $e){
    apiResponse(false,$e->getMessage(),[],409);
}catch(Throwable $e){
    error_log('[API PAYMENT SYNC] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche la synchronisation.',[],500);
}

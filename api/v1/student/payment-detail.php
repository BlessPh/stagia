<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/payment/payment-workflow.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$uuid=trim((string)($_GET['uuid']??''));

try{
    ensurePayableFinancialObligationsForUser($pdo,(int)$student['user_id']);
    $obligation=userFinancialObligationDetail($pdo,(int)$student['user_id'],$uuid);
    apiResponse(true,'Obligation financière chargée.',['obligation'=>$obligation]);
}catch(InvalidArgumentException $error){
    apiResponse(false,$error->getMessage(),[],422);
}catch(OutOfBoundsException $error){
    /* La même réponse est utilisée si l'UUID appartient à un autre utilisateur. */
    apiResponse(false,$error->getMessage(),[],404);
}catch(Throwable $error){
    error_log('[API PAYMENT DETAIL] '.$error->getMessage());
    apiResponse(false,"Une erreur interne empêche le chargement de l'obligation financière.",[],500);
}

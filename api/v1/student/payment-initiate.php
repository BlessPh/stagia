<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-payment.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=apiInput();
if(trim((string)($input['idempotency_key']??''))===''&&!empty($_SERVER['HTTP_IDEMPOTENCY_KEY']))
    $input['idempotency_key']=trim((string)$_SERVER['HTTP_IDEMPOTENCY_KEY']);
if(trim((string)($input['idempotency_key']??''))==='')
    apiResponse(false,"La clé d'idempotence est obligatoire.",[],422);

try{
    $result=initiateStudentStagePayment($pdo,(int)$student['student_id'],(int)$student['user_id'],$input,'STAGIA_MOBILE');
    $created=(bool)($result['payment']['created']??false);
    apiResponse(true,$created?'Paiement initié. En attente de confirmation de l’opérateur.':'Cette demande de paiement a déjà été prise en compte.',$result,$created?201:200);
}catch(InvalidArgumentException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(DomainException $e){
    apiResponse(false,$e->getMessage(),[],409);
}catch(Throwable $e){
    error_log('[API PAYMENT INITIATE] '.$e->getMessage());
    apiResponse(false,"Une erreur interne empêche l'initiation du paiement.",[],500);
}

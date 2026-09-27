<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-reservation.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=isset($_GET['uuid'])?[]:apiInput();
$uuid=trim((string)($_GET['uuid']??$input['reservation_uuid']??''));
if($uuid==='')apiResponse(false,'La réservation est obligatoire.',[],422);

try{
    $result=confirmStudentReservation($pdo,(int)$student['student_id'],$uuid);
    apiResponse(true,'Réservation déjà confirmée.',$result);
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(DomainException $e){
    apiResponse(false,$e->getMessage(),['reservation_uuid'=>$uuid],409);
}catch(Throwable $e){
    error_log('[API RESERVATION CONFIRM] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche la confirmation.',[],500);
}

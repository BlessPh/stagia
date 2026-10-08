<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-reservation.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=isset($_GET['uuid'])?[]:apiInput();
$uuid=trim((string)($_GET['uuid']??$input['reservation_uuid']??''));
if($uuid==='')apiResponse(false,'La réservation est obligatoire.',[],422);

try{
    $result=cancelStudentReservation($pdo,(int)$student['student_id'],(int)$student['user_id'],$uuid);
    apiResponse(
        true,
        $result['idempotent']
            ?'Réservation déjà annulée. Vous pouvez choisir un autre hôpital.'
            :'Réservation temporaire annulée. Vous pouvez maintenant choisir un autre hôpital.',
        $result
    );
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(DomainException $e){
    apiResponse(false,$e->getMessage(),['reservation_uuid'=>$uuid],409);
}catch(Throwable $e){
    error_log('[API RESERVATION CANCEL] '.$e->getMessage());
    apiResponse(false,"Une erreur interne empêche l'annulation.",[],500);
}

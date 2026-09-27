<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-attendance-punch.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=apiInput();
$action=strtoupper(trim((string)($input['action']??'')));

try{
    $result=studentAttendancePunch($pdo,(int)$student['user_id'],$action);
    apiResponse(true,$result['message'],['punch'=>$result['punch']]);
}catch(RuntimeException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    apiResponse(false,'Erreur pointage : '.$e->getMessage(),[],500);
}

<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-attendance-punch.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$action=strtoupper(trim((string)($_GET['action']??'')));

/*
 * Les routes /arrival et /departure injectent l'action dans l'URL interne.
 * Le corps reste accepté pour la compatibilité avec l'ancien endpoint,
 * mais il n'est plus lu lorsque la route fournit déjà l'action.
 */
if($action===''){
    $input=apiInput();
    $action=strtoupper(trim((string)($input['action']??'')));
}

try{
    $result=studentAttendancePunch($pdo,(int)$student['user_id'],$action);
    apiResponse(true,$result['message'],['punch'=>$result['punch']]);
}catch(RuntimeException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    apiResponse(false,'Erreur pointage : '.$e->getMessage(),[],500);
}

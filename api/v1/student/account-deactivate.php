<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-account-deletion.php';

requireApiMethod('POST');$student=requireApiStudent($pdo);$input=apiInput();
try{
    $ip=trim((string)($_SERVER['HTTP_X_FORWARDED_FOR']??$_SERVER['REMOTE_ADDR']??''));if(str_contains($ip,','))$ip=trim(explode(',',$ip)[0]);
    $request=studentAccountScheduleDeletion($pdo,(int)$student['user_id'],(int)$student['student_id'],(string)($input['password']??''),(string)($input['confirmation']??''),$ip);
    apiResponse(true,'Compte desactive. Sa suppression est programmee dans 30 jours.',['deletion_request'=>$request]);
}catch(InvalidArgumentException $e){apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[ACCOUNT DEACTIVATE] '.$e->getMessage());apiResponse(false,'Impossible de programmer la suppression du compte.',[],500);}

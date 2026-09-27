<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-task.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$input=apiInput();
$uuid=trim((string)($_GET['uuid']??$input['uuid']??''));
$action=strtoupper(trim((string)($_GET['action']??$input['action']??'')));
$comment=trim((string)($input['comment']??$input['commentaire']??''));
if($uuid==='')apiResponse(false,'La tache est obligatoire.',[],422);

try{
    $result=stageTaskStudentChange($pdo,(int)$student['student_id'],(int)$student['user_id'],$uuid,$action,$comment);
    apiResponse(true,$result['message'],['task'=>['uuid'=>$result['task_uuid'],'status'=>$result['status']]]);
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(InvalidArgumentException|RuntimeException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    apiResponse(false,'Erreur tache : '.$e->getMessage(),[],500);
}

<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-task.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
if(str_contains($contentType,'application/json')){
    $raw=file_get_contents('php://input')?:'';
    $input=trim($raw)===''?[]:json_decode($raw,true);
    if(!is_array($input)||json_last_error()!==JSON_ERROR_NONE)apiResponse(false,'Corps JSON invalide.',[],400);
}else $input=is_array($_POST)?$_POST:[];
$uuid=trim((string)($_GET['uuid']??$input['uuid']??''));
$action=strtoupper(trim((string)($_GET['action']??$input['action']??'')));
$comment=trim((string)($input['comment']??$input['commentaire']??''));
if($uuid==='')apiResponse(false,'La tache est obligatoire.',[],422);

try{
    $result=stageTaskStudentChange($pdo,(int)$student['student_id'],(int)$student['user_id'],$uuid,$action,$comment);
    $task=stageTaskStudentApiItem($pdo,(int)$student['student_id'],$result['task_uuid']);
    apiResponse(true,$result['message'],['task'=>$task]);
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(InvalidArgumentException|RuntimeException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    apiResponse(false,'Erreur tache : '.$e->getMessage(),[],500);
}

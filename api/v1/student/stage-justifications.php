<?php
require_once __DIR__.'/../api-auth.php';require_once __DIR__.'/../../../config/config.php';require_once __DIR__.'/../../../includes/stage-justification.php';
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');apiResponse(false,'Méthode HTTP non autorisée.',[],405);}
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];$userId=(int)$student['user_id'];
$decorate=static function(array $x):array{
    $uuid=(string)($x['justification_uuid']??'');$base=rtrim(BASE_URL,'/').'/api/v1/student/stage-justifications';
    $x['file_endpoint']=$uuid!==''?$base.'/'.rawurlencode($uuid).'/file':null;$x['read_endpoint']=$uuid!==''?$base.'/'.rawurlencode($uuid).'/read':null;return $x;
};
try{
    if($method==='POST'){$input=str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')?apiInput():$_POST;$result=stageJustificationSubmit($pdo,$studentId,$userId,$input,$_FILES['document']??[]);$data=stageJustificationListForStudent($pdo,$studentId,false);$item=null;foreach($data['items'] as $candidate)if(($candidate['justification_uuid']??'')===$result['uuid']){$item=$decorate($candidate);break;}apiResponse(true,$result['message'],['justification'=>$item],201);}
    $data=stageJustificationListForStudent($pdo,$studentId,false);$data['items']=array_map($decorate,$data['items']);$data['allowed_values']=['types'=>STAGE_JUSTIFICATION_TYPES,'reasons'=>STAGE_JUSTIFICATION_REASONS,'statuses'=>STAGE_JUSTIFICATION_STATUSES];apiResponse(true,'',$data);
}catch(InvalidArgumentException $e){apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[API STAGE JUSTIFICATIONS] '.$e->getMessage());apiResponse(false,'Une erreur interne empêche le traitement des justifications.',[],500);}

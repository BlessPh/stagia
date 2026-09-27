<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../includes/student-personal-document-service.php';

$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];

try{
    if($method==='GET'){
        $items=studentPersonalDocumentList($pdo,$studentId);
        foreach($items as &$item){
            $endpoint=rtrim(BASE_URL,'/').'/api/v1/student/personal-documents/'.$item['uuid'].'/file';
            $item['view_endpoint']=$endpoint;$item['download_endpoint']=$endpoint.'?download=1';$item['requires_bearer']=true;
        }unset($item);
        apiResponse(true,'',['items'=>$items,'total'=>count($items)]);
    }
    if($method==='POST'){
        $document=studentPersonalDocumentUpload($pdo,$studentId,$_POST,$_FILES['document']??[]);
        $endpoint=rtrim(BASE_URL,'/').'/api/v1/student/personal-documents/'.$document['uuid'].'/file';
        $document['view_endpoint']=$endpoint;$document['download_endpoint']=$endpoint.'?download=1';$document['requires_bearer']=true;
        apiResponse(true,'Document ajoute avec succes.',['document'=>$document],201);
    }
    header('Allow: GET, POST');apiResponse(false,'Methode HTTP non autorisee.',[],405);
}catch(InvalidArgumentException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    apiResponse(false,'Erreur documents personnels : '.$e->getMessage(),[],500);
}

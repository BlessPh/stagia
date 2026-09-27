<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/certificate-service.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$uuid=trim((string)($_GET['uuid']??$_GET['token']??''));
if($uuid==='')apiResponse(false,'Document invalide.',[],422);
try{
    $data=certificateData($pdo,$uuid);
    if(!$data||(int)$data['student_id']!==(int)$student['student_id'])apiResponse(false,'Document introuvable.',[],404);
    if(($data['statut']??'')!=='GENERE'||($data['completion_status']??'')!=='VALIDE')apiResponse(false,"Ce document n'est pas disponible.",[],409);
    $projectRoot=realpath(dirname(__DIR__,3));
    $storageRoot=realpath(dirname(__DIR__,3).'/storage/certificates');
    $stored=$projectRoot&&!empty($data['fichier'])?realpath($projectRoot.'/'.ltrim((string)$data['fichier'],'/\\')):false;
    if($storageRoot&&$stored&&str_starts_with($stored,$storageRoot.DIRECTORY_SEPARATOR)&&is_file($stored)){
        $file=['path'=>$stored,'mime'=>(strtolower(pathinfo($stored,PATHINFO_EXTENSION))==='pdf'?'application/pdf':'text/html')];
    }else{
        $file=certificateEnsurePdf($pdo,$data);
    }
    $path=$file['path']??null;
    if(!$path||!is_file($path))apiResponse(false,'Fichier introuvable.',[],404);
    $mime=(string)($file['mime']??'text/html');$extension=$mime==='application/pdf'?'pdf':'html';
    $filename=preg_replace('/[\r\n"]+/','',(string)($data['reference']??'attestation')).'.'.$extension;
    header('Content-Type: '.$mime.($mime==='text/html'?'; charset=utf-8':''));header('Content-Length: '.filesize($path));
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$filename.'"');
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($path);exit;
}catch(Throwable $e){apiResponse(false,'Erreur document : '.$e->getMessage(),[],500);}

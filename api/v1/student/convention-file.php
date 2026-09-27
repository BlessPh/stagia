<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/convention-service.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$uuid=trim((string)($_GET['uuid']??''));
if($uuid==='')apiResponse(false,'Convention invalide.',[],422);
try{
    $data=conventionData($pdo,$uuid);
    if(!$data||(int)$data['student_id']!==(int)$student['student_id'])apiResponse(false,'Convention introuvable.',[],404);
    if(!in_array((string)($data['statut']??''),['SIGNEE','ARCHIVEE'],true)||empty($data['document_id']))apiResponse(false,"Cette convention n'est pas disponible.",[],409);
    $file=conventionEnsurePdf($pdo,$data);$path=$file['path']??null;
    if(!$path||!is_file($path))apiResponse(false,'Fichier introuvable.',[],404);
    $filename=preg_replace('/[\r\n"]+/','',(string)$data['reference']).'.pdf';
    header('Content-Type: application/pdf');header('Content-Length: '.filesize($path));
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$filename.'"');
    header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($path);exit;
}catch(Throwable $e){apiResponse(false,'Erreur convention : '.$e->getMessage(),[],500);}

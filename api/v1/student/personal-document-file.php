<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-personal-document-service.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$uuid=trim((string)($_GET['uuid']??''));
$document=studentPersonalDocument($pdo,(int)$student['student_id'],$uuid);
if(!$document)apiResponse(false,'Document introuvable.',[],404);
$path=studentPersonalDocumentPath($document);
if(!$path)apiResponse(false,'Fichier introuvable.',[],404);
$filename=preg_replace('/[\r\n"]+/','',(string)$document['nom_original'])?:('document.'.($document['extension']??'bin'));
header('Content-Type: '.((string)$document['mime_type']?:'application/octet-stream'));
header('Content-Length: '.filesize($path));
header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$filename.'"');
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
readfile($path);exit;

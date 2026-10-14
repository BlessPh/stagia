<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-justification.php';
requireRole(['STAGIAIRE']);
$studentId=stageJustificationStudentId($pdo,(int)($_SESSION['user_id']??0));$record=stageJustificationFile($pdo,trim((string)($_GET['token']??'')),$studentId);
if(!$record){http_response_code(404);exit('Document introuvable.');}
$name=preg_replace('/[\r\n"]+/','',(string)$record['document_nom_original'])?:'justification';
header('Content-Type: '.($record['document_mime_type']?:'application/octet-stream'));header('Content-Length: '.filesize($record['absolute_path']));
header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$name.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($record['absolute_path']);exit;

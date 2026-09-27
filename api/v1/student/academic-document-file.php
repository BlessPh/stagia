<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$id=(int)($_GET['id']??0);
if(!$id)apiResponse(false,'Document invalide.',[],422);
$s=$pdo->prepare("SELECT * FROM student_documents WHERE id=? AND student_id=? AND statut='ACTIF' AND type_code<>'CONVENTION_STAGE' LIMIT 1");
$s->execute([$id,(int)$student['student_id']]);$document=$s->fetch(PDO::FETCH_ASSOC);
if(!$document)apiResponse(false,'Document introuvable.',[],404);
$projectRoot=realpath(dirname(__DIR__,3));$storageRoot=realpath(dirname(__DIR__,3).'/storage');$relative=ltrim((string)$document['chemin'],'/\\');
$candidate=$projectRoot?realpath($projectRoot.'/'.$relative):false;if(!$candidate&&$projectRoot)$candidate=realpath($projectRoot.'/storage/'.$relative);
if(!$storageRoot||!$candidate||!str_starts_with($candidate,$storageRoot.DIRECTORY_SEPARATOR)||!is_file($candidate))apiResponse(false,'Fichier introuvable.',[],404);
$filename=preg_replace('/[\r\n"]+/','',basename((string)$document['nom_fichier']))?:'document';
header('Content-Type: '.((string)$document['mime_type']?:'application/octet-stream'));header('Content-Length: '.filesize($candidate));
header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.$filename.'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');readfile($candidate);exit;

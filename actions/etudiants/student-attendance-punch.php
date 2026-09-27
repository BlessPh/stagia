<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/student-attendance-punch.php';

requireRole(['STAGIAIRE']);

$userId=(int)($_SESSION['user_id']??0);
$action=strtoupper(trim((string)($_POST['action']??'')));
$csrf=(string)($_POST['csrf']??'');

if(!$userId)jsonResponse(false,'Session invalide.',[],401);
if(empty($_SESSION['csrf'])||!hash_equals((string)$_SESSION['csrf'],$csrf)){
    jsonResponse(false,'Jeton CSRF invalide.',[],419);
}

try{
    $result=studentAttendancePunch($pdo,$userId,$action);
    jsonResponse(true,$result['message'],['punch'=>$result['punch']]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

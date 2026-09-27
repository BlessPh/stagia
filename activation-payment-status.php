<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';

header('Content-Type: application/json; charset=utf-8');

if(empty($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['status'=>'SESSION_EXPIREE']);
    exit;
}

$reference=trim((string)($_GET['reference']??''));
if($reference===''){
    http_response_code(422);
    echo json_encode(['status'=>'INVALIDE']);
    exit;
}

$pdo->prepare("UPDATE student_activation_subscriptions SET statut='EXPIRE' WHERE user_id=? AND reference=? AND statut='EN_ATTENTE' AND created_at<DATE_SUB(NOW(),INTERVAL 2 MINUTE)")->execute([(int)$_SESSION['user_id'],$reference]);

$stmt=$pdo->prepare('SELECT statut,expires_at FROM student_activation_subscriptions WHERE user_id=? AND reference=? LIMIT 1');
$stmt->execute([(int)$_SESSION['user_id'],$reference]);
$payment=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$payment){
    http_response_code(404);
    echo json_encode(['status'=>'INTROUVABLE']);
    exit;
}

echo json_encode([
    'status'=>$payment['statut'],
    'expires_at'=>$payment['expires_at']
]);
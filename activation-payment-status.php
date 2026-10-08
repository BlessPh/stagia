<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/payment/activation-subscription.php';

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

$stmt=$pdo->prepare("SELECT p.status,fe.valid_until expires_at FROM financial_payments p JOIN financial_obligations o ON o.id=p.obligation_id LEFT JOIN financial_entitlements fe ON fe.source_obligation_id=o.id AND fe.entitlement_code='STUDENT_ACCESS' WHERE o.user_id=? AND p.merchant_reference=? LIMIT 1");
$stmt->execute([(int)$_SESSION['user_id'],$reference]);
$payment=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$payment){
    http_response_code(404);
    echo json_encode(['status'=>'INTROUVABLE']);
    exit;
}

echo json_encode([
    'status'=>match($payment['status']){'SUCCEEDED'=>'VALIDE','FAILED'=>'ECHOUE','CANCELLED'=>'ANNULE',default=>'EN_ATTENTE'},
    'expires_at'=>$payment['expires_at']
]);

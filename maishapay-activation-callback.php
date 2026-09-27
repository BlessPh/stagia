<?php
declare(strict_types=1);

require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/settings.php';
require_once __DIR__.'/includes/payment/activation-subscription.php';

header('Content-Type: application/json; charset=utf-8');

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    echo json_encode(['error'=>'Méthode non autorisée.']);
    exit;
}

$expectedToken=(string)setting($pdo,'maishapay.callback_token','');
$providedToken=(string)($_GET['token']??'');
if($expectedToken==='' || !hash_equals($expectedToken,$providedToken)){
    http_response_code(403);
    echo json_encode(['error'=>'Callback non autorisé.']);
    exit;
}

$payload=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($payload)){
    http_response_code(400);
    echo json_encode(['error'=>'Corps JSON invalide.']);
    exit;
}

$reference=trim((string)($payload['originatingTransactionId']??$payload['transactionReference']??''));
$transactionReference=trim((string)($payload['transactionId']??''));
$status=strtoupper(trim((string)($payload['transactionStatus']??'')));

if($reference==='' || !in_array($status,['SUCCESS','FAILED','CANCELLED'],true)){
    http_response_code(422);
    echo json_encode(['error'=>'Données de transaction invalides.']);
    exit;
}

try{
    $result=finalizeActivationSubscription($pdo,$reference,$status,$transactionReference,$payload);
    echo json_encode(['status'=>'OK']+$result);
}catch(Throwable $e){
    error_log('STAGIA activation callback failed: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'Impossible de traiter le paiement.']);
}
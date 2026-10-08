<?php
declare(strict_types=1);

require_once __DIR__.'/config/database.php';
require_once __DIR__.'/config/payment.php';
require_once __DIR__.'/includes/payment/payment-workflow.php';

header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    header('Allow: POST');http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Méthode non autorisée.']);exit;
}

$rawBody=(string)file_get_contents('php://input');
if(!verifyMaishapayWebhook($rawBody)){
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Signature MaishaPay invalide.']);exit;
}

$payload=json_decode($rawBody,true);
if(!is_array($payload))$payload=is_array($_POST)?$_POST:[];
$data=is_array($payload['original']['data']??null)
    ?$payload['original']['data']
    :(is_array($payload['data']??null)?$payload['data']:$payload);
$reference=trim((string)($data['originatingTransactionId']??$data['transactionReference']??$data['transactionRefId']??$data['merchant_reference']??''));
$transactionId=trim((string)($data['transactionId']??$data['operatorRefId']??$data['provider_transaction_id']??''));
$status=strtoupper(trim((string)($data['transactionStatus']??$data['status']??$data['statusCode']??$data['result']??'')));

if($reference===''||$status===''){
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Référence ou statut de transaction absent.']);exit;
}

try{
    /* Un accusé 202 ne constitue pas une preuve de paiement : seule une issue finale est appliquée. */
    if(in_array($status,['202','PENDING','INITIATED','PROCESSING','EN_ATTENTE'],true)){
        $stmt=$pdo->prepare("UPDATE financial_payments SET status='PENDING',provider_transaction_id=COALESCE(NULLIF(?,''),provider_transaction_id),callback_payload=? WHERE merchant_reference=? AND status IN('INITIATED','PENDING')");
        $stmt->execute([$transactionId,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$reference]);
        echo json_encode(['success'=>true,'status'=>'PENDING','reference'=>$reference],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    $result=finalizeFinancialPaymentWorkflow($pdo,$reference,$status,$transactionId,$payload);
    echo json_encode(['success'=>true,'status'=>'OK','data'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(OutOfBoundsException $error){
    http_response_code(404);echo json_encode(['success'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException|DomainException $error){
    http_response_code(422);echo json_encode(['success'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){
    error_log('[MAISHAPAY CALLBACK] '.$error->getMessage());
    http_response_code(500);echo json_encode(['success'=>false,'message'=>'Impossible de finaliser le paiement.'],JSON_UNESCAPED_UNICODE);
}

<?php
declare(strict_types=1);

require_once __DIR__.'/financial-obligation.php';
require_once dirname(__DIR__).'/settings.php';

function activationObligation(PDO $pdo,int $userId):?array{
    $s=$pdo->prepare("SELECT * FROM financial_obligations WHERE user_id=? AND obligation_type='STUDENT_ACTIVATION' ORDER BY id DESC LIMIT 1");$s->execute([$userId]);
    return $s->fetch(PDO::FETCH_ASSOC)?:null;
}

function activationPendingPayment(PDO $pdo,int $userId):?array{
    $s=$pdo->prepare("SELECT p.merchant_reference reference,p.created_at FROM financial_payments p JOIN financial_obligations o ON o.id=p.obligation_id WHERE o.user_id=? AND o.obligation_type='STUDENT_ACTIVATION' AND p.status IN('INITIATED','PENDING') AND p.created_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) ORDER BY p.id DESC LIMIT 1");$s->execute([$userId]);
    return $s->fetch(PDO::FETCH_ASSOC)?:null;
}

function initiateActivationSubscription(PDO $pdo,int $userId,string $provider,string $walletPhone):array{
    if(!settingBool($pdo,'student_activation.payment_enabled',false))throw new DomainException("Le paiement annuel étudiant n'est pas activé.");
    $amount=round((float)setting($pdo,'student_activation.amount','0'),2);$currency=strtoupper(trim((string)setting($pdo,'student_activation.currency','USD')));
    if($amount<=0)throw new DomainException("Le montant de l'abonnement étudiant est invalide.");
    $obligation=ensureFinancialObligation($pdo,[
        'user_id'=>$userId,'obligation_type'=>'STUDENT_ACTIVATION','subject_type'=>'USER_YEAR',
        'subject_key'=>$userId.':'.date('Y'),'label'=>'Activation annuelle du compte étudiant','amount'=>$amount,'currency'=>$currency
    ]);
    $result=initiateFinancialPayment($pdo,(int)$obligation['id'],$userId,[
        'channel'=>$provider,'wallet_phone'=>$walletPhone,'idempotency_key'=>'ACT-'.$userId.'-'.date('YmdHi')
    ],activationCustomer($pdo,$userId));
    if(!empty($result['already_paid']))return ['status'=>'SUCCESS','reference'=>$obligation['reference'],'created_at'=>$obligation['created_at']];
    return ['status'=>'PENDING','reference'=>$result['payment']['merchant_reference'],'created_at'=>$result['payment']['created_at']];
}

function activationCustomer(PDO $pdo,int $userId):array{
    $s=$pdo->prepare("SELECT TRIM(CONCAT_WS(' ',prenom,nom,postnom)) full_name,email FROM users WHERE id=? LIMIT 1");$s->execute([$userId]);
    return $s->fetch(PDO::FETCH_ASSOC)?:[];
}

function lastActivationSubscriptionResponse(PDO $pdo,int $userId):?array{
    $s=$pdo->prepare("SELECT p.provider_response FROM financial_payments p JOIN financial_obligations o ON o.id=p.obligation_id WHERE o.user_id=? AND o.obligation_type='STUDENT_ACTIVATION' ORDER BY p.id DESC LIMIT 1");$s->execute([$userId]);$raw=$s->fetchColumn();
    $decoded=is_string($raw)?json_decode($raw,true):null;return is_array($decoded)?$decoded:null;
}

function finalizeActivationSubscription(PDO $pdo,string $reference,string $status,?string $transactionReference,array $payload=[]):array{
    $result=finalizeFinancialPayment($pdo,$reference,$status,$transactionReference,$payload);
    if($result['obligation_status']==='PAID'){
        $s=$pdo->prepare('SELECT user_id FROM financial_obligations WHERE id=?');$s->execute([$result['obligation_id']]);$userId=(int)$s->fetchColumn();
        $pdo->prepare("INSERT INTO financial_entitlements(user_id,entitlement_code,source_obligation_id,valid_from,valid_until) VALUES(?,'STUDENT_ACCESS',?,NOW(),DATE_ADD(NOW(),INTERVAL 1 YEAR)) ON DUPLICATE KEY UPDATE source_obligation_id=VALUES(source_obligation_id),valid_from=NOW(),valid_until=DATE_ADD(GREATEST(valid_until,NOW()),INTERVAL 1 YEAR)")->execute([$userId,$result['obligation_id']]);
    }
    return $result;
}

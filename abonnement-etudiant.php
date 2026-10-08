<?php
declare(strict_types=1);

if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/settings.php';
require_once __DIR__.'/includes/payment/financial-obligation.php';

if(empty($_SESSION['user_id'])){
    header('Location: '.BASE_URL.'/login.php');exit;
}
try{
    if(!settingBool($pdo,'student_activation.payment_enabled',false)){
        header('Location: '.BASE_URL.'/dashboard.php');exit;
    }
    $amount=round((float)setting($pdo,'student_activation.amount','0'),2);
    $currency=strtoupper(trim((string)setting($pdo,'student_activation.currency','USD')));
    if($amount<=0)throw new DomainException("Le montant de l'abonnement étudiant est invalide.");
    $obligation=ensureFinancialObligation($pdo,[
        'user_id'=>(int)$_SESSION['user_id'],'obligation_type'=>'STUDENT_ACTIVATION','subject_type'=>'USER_YEAR',
        'subject_key'=>(int)$_SESSION['user_id'].':'.date('Y'),'label'=>'Activation annuelle du compte étudiant',
        'amount'=>$amount,'currency'=>$currency
    ]);
    header('Location: '.BASE_URL.'/views/paiements/index.php?obligation='.rawurlencode((string)$obligation['uuid']));exit;
}catch(Throwable $error){
    error_log('[STUDENT ACTIVATION PAYMENT] '.$error->getMessage());
    http_response_code(500);
    exit('Impossible de préparer le paiement de votre abonnement.');
}

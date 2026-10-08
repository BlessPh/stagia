<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/payment/payment-workflow.php';

requireAjaxAuth();

try{
    ensurePayableFinancialObligationsForUser($pdo,(int)$_SESSION['user_id']);
    $raw=$_GET['status']??$_GET['statuses']??[];
    if(!is_array($raw))$raw=explode(',',(string)$raw);
    $result=listUserFinancialObligations($pdo,(int)$_SESSION['user_id'],[
        'statuses'=>$raw,'type'=>$_GET['type']??''
    ]);
    $result['channels']=[
        'MPESA'=>'M-Pesa','ORANGE_MONEY'=>'Orange Money',
        'AIRTEL_MONEY'=>'Airtel Money','AFRIMONEY'=>'Afrimoney'
    ];
    jsonResponse(true,'',$result);
}catch(Throwable $error){
    error_log('[MY FINANCIAL OBLIGATIONS] '.$error->getMessage());
    jsonResponse(false,'Impossible de charger vos paiements.',[],500);
}


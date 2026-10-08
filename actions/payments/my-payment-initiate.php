<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/payment/payment-workflow.php';

requireAjaxAuth();
verifyAjaxCsrf();

try{
    $result=initiateUserFinancialPayment($pdo,(int)$_SESSION['user_id'],$_POST);
    $created=(bool)($result['created']??false);
    jsonResponse(true,
        $created?'Demande envoyée. Confirmez le paiement sur votre téléphone.':'Cette demande est déjà prise en compte.',
        $result,$created?201:200
    );
}catch(InvalidArgumentException $error){jsonResponse(false,$error->getMessage(),[],422);
}catch(OutOfBoundsException $error){jsonResponse(false,$error->getMessage(),[],404);
}catch(DomainException $error){jsonResponse(false,$error->getMessage(),[],409);
}catch(Throwable $error){
    error_log('[MY PAYMENT INITIATE] '.$error->getMessage());
    jsonResponse(false,"Impossible d'initier le paiement.",[],500);
}


<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/payment/payment-workflow.php';

requireAjaxAuth();
verifyAjaxCsrf();

try{
    $uuid=trim((string)($_POST['obligation_uuid']??''));
    $result=synchronizeFinancialPaymentWorkflow($pdo,(int)$_SESSION['user_id'],$uuid);
    jsonResponse(true,'État du paiement actualisé.',$result);
}catch(InvalidArgumentException $error){jsonResponse(false,$error->getMessage(),[],422);
}catch(OutOfBoundsException $error){jsonResponse(false,$error->getMessage(),[],404);
}catch(DomainException $error){jsonResponse(false,$error->getMessage(),[],409);
}catch(Throwable $error){
    error_log('[MY PAYMENT SYNC] '.$error->getMessage());
    jsonResponse(false,'Impossible de synchroniser le paiement.',[],500);
}


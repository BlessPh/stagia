<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/config/payment.php';

function maishapayProvider(string $channel):string{
    return match(strtoupper(trim($channel))){
        'MPESA'=>'MPESA',
        'ORANGE','ORANGE_MONEY'=>'ORANGE',
        'AIRTEL','AIRTEL_MONEY'=>'AIRTEL',
        'AFRICELL','AFRIMONEY'=>'AFRICEL',
        'MTN','MTN_MONEY'=>'MTN',
        default=>throw new InvalidArgumentException('Operateur Mobile Money non pris en charge.')
    };
}

function maishapayPhone(string $phone):string{
    $phone=preg_replace('/[^0-9+]/','',trim($phone))??'';
    if(str_starts_with($phone,'00'))$phone='+'.substr($phone,2);
    elseif(str_starts_with($phone,'0'))$phone='+243'.substr($phone,1);
    elseif(str_starts_with($phone,'243'))$phone='+'.$phone;
    if(!preg_match('/^\+[1-9][0-9]{8,14}$/',$phone))throw new InvalidArgumentException('Numero Mobile Money invalide. Utilisez le format international.');
    return $phone;
}

/** Appelle le C2B MaishaPay. Les secrets ne sont jamais retournes ni journalises. */
function maishapayCollect(array $payment,array $customer):array{
    $config=requireMaishapayConfig();
    if(!function_exists('curl_init'))throw new RuntimeException("L'extension PHP cURL est requise pour MaishaPay.");
    $payload=[
        'gatewayMode'=>$config['gateway_mode'],
        'publicApiKey'=>$config['public_api_key'],
        'secretApiKey'=>$config['secret_api_key'],
        'transactionReference'=>(string)$payment['merchant_reference'],
        'amount'=>(float)$payment['amount'],
        'currency'=>strtoupper((string)$payment['currency']),
        'customerFullName'=>trim((string)($customer['full_name']??'')),
        'customerPhoneNumber'=>maishapayPhone((string)$payment['wallet_phone']),
        'customerEmailAddress'=>trim((string)($customer['email']??'')),
        'chanel'=>'MOBILEMONEY',
        'provider'=>maishapayProvider((string)$payment['channel']),
        'walletID'=>maishapayPhone((string)$payment['wallet_phone']),
        'callbackUrl'=>$config['callback_url']
    ];
    $ch=curl_init($config['c2b_url']);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$config['timeout_seconds']]);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($raw===false||$error!=='')throw new RuntimeException('MaishaPay est injoignable.');
    $response=json_decode((string)$raw,true);
    if(!is_array($response))throw new RuntimeException('Reponse MaishaPay invalide.');
    $data=is_array($response['original']['data']??null)?$response['original']['data']:$response;
    $accepted=$http>=200&&$http<300&&in_array((int)($data['statusCode']??$http),[200,201,202],true);
    return ['accepted'=>$accepted,'http_status'=>$http,'transaction_id'=>trim((string)($data['transactionId']??$response['transactionId']??'')),'response'=>$response,'request'=>array_diff_key($payload,['publicApiKey'=>true,'secretApiKey'=>true])];
}

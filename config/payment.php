<?php
declare(strict_types=1);

require_once __DIR__.'/env.php';

/** Configuration MaishaPay provenant exclusivement de l'environnement. */
function maishapayConfig():array{
    return [
        'enabled'=>filter_var(getenv('MAISHAPAY_ENABLED')?:'false',FILTER_VALIDATE_BOOL),
        'gateway_mode'=>(int)(getenv('MAISHAPAY_GATEWAY_MODE')?:0),
        'c2b_url'=>trim((string)(getenv('MAISHAPAY_C2B_URL')?:'https://marchand.maishapay.online/api/payment/rest/vers1.0/merchant')),
        'public_api_key'=>trim((string)(getenv('MAISHAPAY_PUBLIC_API_KEY')?:'')),
        'secret_api_key'=>trim((string)(getenv('MAISHAPAY_SECRET_API_KEY')?:'')),
        'callback_url'=>trim((string)(getenv('MAISHAPAY_CALLBACK_URL')?:'')),
        'timeout_seconds'=>max(5,min(120,(int)(getenv('MAISHAPAY_TIMEOUT_SECONDS')?:30)))
    ];
}

function requireMaishapayConfig():array{
    $config=maishapayConfig();
    if(!$config['enabled'])throw new RuntimeException('Le paiement MaishaPay est desactive.');
    if(!in_array($config['gateway_mode'],[0,1],true))throw new RuntimeException('MAISHAPAY_GATEWAY_MODE doit valoir 0 ou 1.');
    foreach(['c2b_url','public_api_key','secret_api_key','callback_url'] as $key){
        if($config[$key]==='')throw new RuntimeException('Configuration MaishaPay incomplete : '.$key.'.');
    }
    if(!filter_var($config['c2b_url'],FILTER_VALIDATE_URL))throw new RuntimeException('MAISHAPAY_C2B_URL est invalide.');
    if(!filter_var($config['callback_url'],FILTER_VALIDATE_URL))throw new RuntimeException('MAISHAPAY_CALLBACK_URL est invalide.');
    return $config;
}

/** Vérifie la signature HMAC-SHA256 officielle du webhook MaishaPay. */
function verifyMaishapayWebhook(string $rawBody):bool{
    $config=maishapayConfig();
    $provided=trim((string)($_SERVER['HTTP_X_MAISHAPAY_SIGNATURE']??''));
    if($provided===''||$config['secret_api_key']==='')return false;
    if(str_starts_with(strtolower($provided),'sha256='))$provided=substr($provided,7);
    $hex=hash_hmac('sha256',$rawBody,$config['secret_api_key']);
    $base64=base64_encode(hex2bin($hex));
    return hash_equals(strtolower($hex),strtolower($provided))||hash_equals($base64,$provided);
}

<?php
declare(strict_types=1);

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/payment.php';

$errors=[];
$config=maishapayConfig();

echo "Configuration MaishaPay :\n";
echo '  - activation : '.($config['enabled']?'OUI':'NON')."\n";
echo '  - mode : '.($config['gateway_mode']===1?'PRODUCTION':'SANDBOX')."\n";
echo '  - endpoint C2B : '.($config['c2b_url']!==''?'CONFIGURE':'ABSENT')."\n";
echo '  - callback : '.($config['callback_url']!==''?'CONFIGURE':'ABSENT')."\n";
echo '  - cle publique : '.($config['public_api_key']!==''?'CONFIGUREE':'ABSENTE')."\n";
echo '  - cle secrete : '.($config['secret_api_key']!==''?'CONFIGUREE':'ABSENTE')."\n";
echo '  - extension cURL : '.(function_exists('curl_init')?'OK':'ABSENTE')."\n";

if(!$config['enabled'])$errors[]='MAISHAPAY_ENABLED doit valoir true.';
if(!in_array($config['gateway_mode'],[0,1],true))$errors[]='MAISHAPAY_GATEWAY_MODE doit valoir 0 ou 1.';
foreach(['c2b_url','callback_url','public_api_key','secret_api_key'] as $key){
    if($config[$key]==='')$errors[]="Variable MaishaPay absente : {$key}.";
}
if($config['c2b_url']!==''&&!filter_var($config['c2b_url'],FILTER_VALIDATE_URL))$errors[]='URL C2B invalide.';
if($config['callback_url']!==''&&!filter_var($config['callback_url'],FILTER_VALIDATE_URL))$errors[]='URL de callback invalide.';
if(!function_exists('curl_init'))$errors[]="L'extension PHP cURL est absente.";

echo "Schema financier :\n";
foreach(['financial_obligations','financial_payments','financial_entitlements','stage_invoices','stage_payments'] as $table){
    $s=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->execute([$table]);$engine=$s->fetchColumn();
    echo "  - {$table} : ".($engine?:'ABSENTE')."\n";
    if(!$engine)$errors[]="Table absente : {$table}.";
    elseif(strtoupper((string)$engine)!=='INNODB')$errors[]="{$table} doit utiliser InnoDB.";
}

$legacy=(int)$pdo->query("SELECT COUNT(*) FROM system_settings WHERE setting_key LIKE 'maishapay.%'")->fetchColumn();
echo '  - anciens secrets en base : '.($legacy===0?'AUCUN':(string)$legacy)."\n";
if($legacy>0)$errors[]='Des paramètres maishapay.* existent encore dans system_settings.';

if($errors){
    echo "\nConfiguration incomplete :\n";
    foreach($errors as $error)echo "  - {$error}\n";
    exit(1);
}

echo "\nConfiguration de paiement prete pour un test sandbox.\n";

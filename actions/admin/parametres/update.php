<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();

$values=$_POST['settings']??[];
if(!is_array($values))jsonResponse(false,'Données invalides.',[],422);

$allowed=[
    'platform.name',
    'platform.subtitle',
    'platform.support_email',
    'platform.support_phone',
    'platform.timezone',
    'security.session_timeout_minutes',
    'security.activation_expiry_hours',
    'security.max_login_attempts',
    'security.lockout_minutes',
    'adhesion.enabled',
    'notifications.email_enabled',
    'maintenance.enabled',
    'maintenance.message'
];

try{
    $pdo->beginTransaction();

    $select=$pdo->prepare("SELECT setting_key,setting_type FROM system_settings WHERE setting_key=? LIMIT 1");
    $update=$pdo->prepare("UPDATE system_settings SET setting_value=?,updated_by=? WHERE setting_key=?");

    foreach($allowed as $key){
        if(!array_key_exists($key,$values))continue;

        $select->execute([$key]);
        $setting=$select->fetch(PDO::FETCH_ASSOC);
        if(!$setting)continue;

        $value=$values[$key];
        if(is_array($value))throw new RuntimeException("Valeur invalide pour $key.");

        $value=trim((string)$value);

        switch($setting['setting_type']){
            case 'BOOLEAN':
                $value=in_array($value,['1','true','on','yes'],true)?'1':'0';
                break;

            case 'INTEGER':
                if($value===''||filter_var($value,FILTER_VALIDATE_INT)===false)
                    throw new RuntimeException("La valeur de $key doit être un nombre entier.");
                $n=(int)$value;
                $ranges=[
                    'security.session_timeout_minutes'=>[5,1440],
                    'security.activation_expiry_hours'=>[1,720],
                    'security.max_login_attempts'=>[1,20],
                    'security.lockout_minutes'=>[1,1440]
                ];
                if(isset($ranges[$key])&&($n<$ranges[$key][0]||$n>$ranges[$key][1]))
                    throw new RuntimeException("La valeur de $key est hors limite autorisée.");
                $value=(string)$n;
                break;

            case 'EMAIL':
                if($value!==''&&!filter_var($value,FILTER_VALIDATE_EMAIL))
                    throw new RuntimeException('Adresse e-mail de support invalide.');
                break;

            case 'TEXT':
                if(mb_strlen($value)>1000)
                    throw new RuntimeException('Le message de maintenance est trop long.');
                break;

            default:
                if(mb_strlen($value)>255)
                    throw new RuntimeException("La valeur de $key est trop longue.");
        }

        if($key==='platform.timezone'){
            try{new DateTimeZone($value);}catch(Throwable $e){throw new RuntimeException('Fuseau horaire invalide.');}
        }

        $update->execute([$value,$_SESSION['user_id']??null,$key]);
    }

    $pdo->commit();
    jsonResponse(true,'Paramètres enregistrés.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

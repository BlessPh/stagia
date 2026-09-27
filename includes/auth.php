<?php
/**
 * Garde commune des pages Web protégées.
 * Elle valide la session, le compte, les rôles, le contexte établissement
 * et les permissions effectives avant de laisser la page continuer.
 */
if(session_status()===PHP_SESSION_NONE)session_start();

if(!defined('BASE_URL'))require_once __DIR__.'/../config/config.php';
if(!isset($pdo))require_once __DIR__.'/../config/database.php';

require_once __DIR__.'/permissions.php';
require_once __DIR__.'/settings.php';
require_once __DIR__.'/rbac-session.php';
require_once __DIR__.'/establishment-context.php';

/** Détruit la session courante puis redirige vers la connexion avec un code de diagnostic. */
function authRedirectToLogin(string $error=''):never{
    $_SESSION=[];
    if(ini_get('session.use_cookies')){
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();

    $url=BASE_URL.'/login.php';
    if($error!=='')$url.='?error='.urlencode($error);

    header('Location: '.$url);
    exit;
}

/* Toute page qui inclut ce fichier exige une identité utilisateur en session. */
if(empty($_SESSION['user_id']))authRedirectToLogin();

$userId=(int)$_SESSION['user_id'];

/* L'état du compte est relu en base à chaque requête protégée. */
$stmt=$pdo->prepare("SELECT id,nom,prenom,actif,statut_compte FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$authUser=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$authUser || !(int)$authUser['actif'] || ($authUser['statut_compte']??'ACTIF')!=='ACTIF'){
    authRedirectToLogin('account');
}

/* Le délai d'inactivité est configurable, avec un minimum de cinq minutes. */
$timeoutMinutes=max(5,settingInt($pdo,'security.session_timeout_minutes',120));
$now=time();

if(isset($_SESSION['last_activity']) && ($now-(int)$_SESSION['last_activity'])>$timeoutMinutes*60){
    authRedirectToLogin('session');
}

/* 1. RBAC : rôles et périmètres. */
$access=loadUserAccessContext($pdo,$userId);
if(empty($access['ok']))authRedirectToLogin('access');
applyUserAccessContextToSession($access);

/* 2. Établissement : type + modèle académique + capacités. */
$preferredId=$access['etablissement_id']??null;
$establishmentId=resolveUserEstablishmentId($pdo,$userId,$preferredId);
$establishmentContext=loadEstablishmentContext($pdo,$establishmentId);

/* 3. Permissions effectives dans CE contexte. */
$contextPermissions=loadContextPermissionCodes($pdo,$userId,$establishmentId);
applyEstablishmentContextToSession($establishmentContext,$contextPermissions);

$_SESSION['nom']=$authUser['nom'];
$_SESSION['prenom']=$authUser['prenom'];
$_SESSION['last_activity']=$now;

/* Renouvellement périodique de l'identifiant de session pour limiter le risque de détournement. */
if(empty($_SESSION['session_regenerated_at']) || ($now-(int)$_SESSION['session_regenerated_at'])>1800){
    session_regenerate_id(true);
    $_SESSION['session_regenerated_at']=$now;
}

/* Le mode maintenance laisse les super-administrateurs accéder à la plateforme. */
if(settingBool($pdo,'maintenance.enabled',false) && !hasRole('SUPER_ADMIN')){
    http_response_code(503);
    $message=(string)setting(
        $pdo,
        'maintenance.message',
        'Maintenance STAGIA en cours. Merci de réessayer plus tard.'
    );
    exit('<!doctype html><html lang="fr"><meta charset="utf-8"><title>Maintenance | STAGIA-RDC</title>
    <body style="font-family:Segoe UI,Arial,sans-serif;background:#f7f7f7;margin:0;display:grid;place-items:center;min-height:100vh">
    <div style="max-width:620px;background:#fff;border:1px solid #e5e7eb;border-top:5px solid #ff751f;border-radius:16px;padding:32px;text-align:center">
    <h2 style="margin-top:0">STAGIA-RDC est temporairement en maintenance</h2>
    <p style="color:#64748b">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>
    </div></body></html>');
}

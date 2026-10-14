<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/session-security.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/login-throttle.php';

$checks=0;
$check=static function(bool $condition,string $message)use(&$checks):void{
    if(!$condition)throw new RuntimeException($message);
    $checks++;
};

/* Migration et index indispensables a l'atomicite du compteur. */
$stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
$stmt->execute(['login_throttle_attempts']);
$check((int)$stmt->fetchColumn()===1,'Table login_throttle_attempts manquante.');
$stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='login_throttle_attempts' AND index_name='uq_login_throttle_subject' AND non_unique=0");
$check((int)$stmt->fetchColumn()===1,'Index unique du compteur manquant.');

/* Les identifiants et adresses ne doivent jamais etre conserves en clair. */
$_SERVER['REMOTE_ADDR']='203.0.113.42';
$unknownKey=loginThrottleSubjectKey(null,' Test@Example.cd ');
$knownKey=loginThrottleSubjectKey(['id'=>42],'alias-inutile');
$check(strlen($unknownKey)===64&&!str_contains($unknownKey,'example'),'Identifiant de connexion non anonymise.');
$check($knownKey===loginThrottleSubjectKey(['id'=>42],'autre-alias'),'Les alias d’un compte ne partagent pas leur compteur.');
$check(loginThrottleIpHash()!==hash('sha256','203.0.113.42'),'Adresse IP insuffisamment cloisonnee.');

/* Un jeu de cles isole valide le seuil, le verrouillage puis sa remise a zero. */
$testKeys=[hash('sha256','test-subject:'.bin2hex(random_bytes(8))),hash('sha256','test-ip:'.bin2hex(random_bytes(8)))];
$limits=loginThrottleLimits($pdo);
loginThrottleClear($pdo,$testKeys);
$remaining=0;
for($attempt=0;$attempt<$limits['max_attempts'];$attempt++){
    $remaining=loginThrottleRegisterFailure($pdo,$testKeys);
}
$check($remaining>0,'Le seuil de verrouillage n’est pas applique.');
$check(loginThrottleRemainingSeconds($pdo,$testKeys)>0,'Le verrouillage n’est pas relu.');
loginThrottleClear($pdo,$testKeys);
$check(loginThrottleRemainingSeconds($pdo,$testKeys)===0,'Le compteur n’est pas efface apres authentification.');

/* Nettoyage des identifiants reserves au smoke test HTTP local. */
$e2eKeys=[
    loginThrottleSubjectKey(null,'codex-security-e2e-20261013'),
    hash('sha256','ip-lock:'.hash('sha256','ip:127.0.0.1')),
    hash('sha256','ip-lock:'.hash('sha256','ip:::1'))
];
loginThrottleClear($pdo,$e2eKeys);

/* Le cookie local reste utilisable sous WAMP tout en interdisant JavaScript et les SID URL. */
$cookie=session_get_cookie_params();
$check(session_name()==='STAGIASESSID','Nom de session locale inattendu.');
$check($cookie['httponly']===true&&($cookie['samesite']??'')==='Lax','Attributs du cookie de session incomplets.');
$check((string)ini_get('session.use_only_cookies')==='1'&&(string)ini_get('session.use_strict_mode')==='1','Options de session strictes absentes.');

/* Les messages metier restent precis, les erreurs techniques sont neutralisees. */
$business='Votre session a expire.';
$check(appPublicErrorMessage($business)===$business,'Un message metier a ete masque.');
$check(!str_contains(strtolower(appPublicErrorMessage('SQLSTATE[42S02] table C:\\wamp64\\www\\secret')),'sqlstate'),'Une erreur SQL est exposee.');

/* Verification de branchement sur les deux parcours et maintien de la permission mobile. */
$web=(string)file_get_contents(__DIR__.'/../login.php');
$api=(string)file_get_contents(__DIR__.'/../api/v1/auth/login.php');
$bootstrap=(string)file_get_contents(__DIR__.'/../api/v1/bootstrap.php');
$check(str_contains($web,'loginThrottleRegisterFailure')&&str_contains($web,'loginThrottleClear'),'Limitation Web non branchee.');
$check(str_contains($api,'loginThrottleRegisterFailure')&&str_contains($api,"in_array('STAGIAIRE'"),'Limitation ou permission API non conservee.');
$check(str_contains($bootstrap,'appPublicErrorMessage'),'Assainissement central des erreurs API absent.');

echo $checks." controles de securite de l'authentification reussis.\n";

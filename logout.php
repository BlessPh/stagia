<?php
if(session_status()===PHP_SESSION_NONE)session_start();

/* Efface les donnees et le cookie afin que la redirection n'essaie pas de rouvrir l'ancien fichier verrouille. */
$_SESSION=[];
if(ini_get('session.use_cookies')){
    $params=session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time()-42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}
session_destroy();

header('Location: login.php');
exit;

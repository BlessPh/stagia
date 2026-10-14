<?php
declare(strict_types=1);

require_once __DIR__.'/../config/env.php';

/** Les en-tetes de proxy ne sont fiables que lorsque leur usage est explicitement active. */
function stagiaTrustProxyHeaders():bool{
    return filter_var(getenv('TRUST_PROXY_HEADERS')?:'false',FILTER_VALIDATE_BOOL);
}

function stagiaSessionUsesHttps():bool{
    if((!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')
        ||(int)($_SERVER['SERVER_PORT']??0)===443){
        return true;
    }

    if(stagiaTrustProxyHeaders()){
        $proto=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]));
        return $proto==='https';
    }

    return false;
}

function stagiaSessionEnvironment():string{
    if(PHP_SAPI==='cli')return 'local';

    $environment=strtolower(trim((string)(getenv('APP_ENV')?:'')));
    if(in_array($environment,['local','development','production'],true))return $environment;

    $host=strtolower(trim((string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??'')));
    $host=preg_replace('/:\d+$/','',$host)??'';
    return in_array($host,['localhost','127.0.0.1','::1'],true)?'local':'production';
}

/** Configure le cookie avant le premier demarrage de session. */
function stagiaStartSecureSession():void{
    if(session_status()===PHP_SESSION_ACTIVE)return;

    $production=stagiaSessionEnvironment()==='production';
    if($production&&!stagiaSessionUsesHttps()){
        http_response_code(403);
        exit('Connexion securisee HTTPS requise.');
    }

    ini_set('session.use_only_cookies','1');
    ini_set('session.use_trans_sid','0');
    ini_set('session.use_strict_mode','1');
    ini_set('session.cookie_httponly','1');

    session_name($production?'__Host-STAGIASESSID':'STAGIASESSID');
    session_set_cookie_params([
        'lifetime'=>0,
        'path'=>'/',
        'secure'=>$production,
        'httponly'=>true,
        'samesite'=>'Lax'
    ]);
    session_start();
}

stagiaStartSecureSession();

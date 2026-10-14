<?php
declare(strict_types=1);

function appErrorIsJsonRequest():bool{
    $script=strtolower((string)($_SERVER['SCRIPT_NAME']??''));
    $accept=strtolower((string)($_SERVER['HTTP_ACCEPT']??''));
    $requestedWith=strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''));
    return str_contains($script,'/api/')
        ||str_contains($script,'/actions/')
        ||str_contains($accept,'application/json')
        ||$requestedWith==='xmlhttprequest';
}

/** Conserve les erreurs metier mais masque les details techniques exploitables. */
function appPublicErrorMessage(string $message,string $fallback='Une erreur technique est survenue. Veuillez reessayer plus tard.'):string{
    $technicalPattern='/sqlstate|pdo|mysql|mysqli|database|constraint|duplicate entry|unknown (column|table|database)|syntax error|stack trace|fatal error|warning|notice|parse error|undefined (variable|index|array key|offset)|call to (undefined|a member)|argument #|typeerror|exception| in .+ on line \d+|[a-z]:\\\\|\/(?:var|home|wamp|www|htdocs)\//i';
    return preg_match($technicalPattern,$message)===1?$fallback:$message;
}

function appSendPublicError(int $status=500):never{
    $message='Une erreur technique est survenue. Veuillez reessayer plus tard.';
    if(!headers_sent()){
        http_response_code($status);
        if(appErrorIsJsonRequest()){
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['success'=>false,'message'=>$message,'data'=>[]],JSON_UNESCAPED_UNICODE);
        }else{
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Service indisponible</title></head><body><h1>Service temporairement indisponible</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p></body></html>';
        }
    }
    exit;
}

function appHandleUncaughtThrowable(Throwable $e):void{
    error_log('[STAGIA UNCAUGHT] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    appSendPublicError(500);
}

if(PHP_SAPI!=='cli'){
    ini_set('display_errors','0');
    ini_set('display_startup_errors','0');
    error_reporting(E_ALL);
    set_exception_handler('appHandleUncaughtThrowable');
    set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{
        if(!(error_reporting()&$severity))return false;
        error_log('[STAGIA PHP] '.$message.' | '.$file.':'.$line);
        return true;
    });
    register_shutdown_function(static function():void{
        $last=error_get_last();
        if($last&&in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)){
            error_log('[STAGIA FATAL] '.$last['message'].' | '.$last['file'].':'.$last['line']);
            appSendPublicError(500);
        }
    });
}

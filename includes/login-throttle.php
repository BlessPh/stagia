<?php
declare(strict_types=1);

require_once __DIR__.'/settings.php';

/** La migration reste la source du schema; une installation non migree reste utilisable. */
function loginThrottleStorageAvailable(PDO $pdo):bool{
    static $availability=[];
    $key=spl_object_id($pdo);
    if(array_key_exists($key,$availability))return $availability[$key];
    try{
        $pdo->query('SELECT 1 FROM login_throttle_attempts LIMIT 1');
        return $availability[$key]=true;
    }catch(Throwable $e){
        error_log('[LOGIN THROTTLE] Stockage indisponible : '.$e->getMessage());
        return $availability[$key]=false;
    }
}

function loginThrottleLimits(PDO $pdo):array{
    return [
        'max_attempts'=>min(20,max(1,settingInt($pdo,'security.max_login_attempts',5))),
        'lockout_minutes'=>min(1440,max(1,settingInt($pdo,'security.lockout_minutes',15)))
    ];
}

function loginThrottleSubjectKey(?array $user,string $login):string{
    if($user&&isset($user['id']))return hash('sha256','user:'.(int)$user['id']);
    $login=trim($login);
    $login=function_exists('mb_strtolower')?mb_strtolower($login,'UTF-8'):strtolower($login);
    return hash('sha256','unknown-login:'.$login);
}

function loginThrottleIpHash():string{
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    if(!filter_var($ip,FILTER_VALIDATE_IP))$ip='unknown';
    return hash('sha256','ip:'.$ip);
}

function loginThrottleKeys(?array $user,string $login):array{
    return [loginThrottleSubjectKey($user,$login),hash('sha256','ip-lock:'.loginThrottleIpHash())];
}

function loginThrottleRemainingSeconds(PDO $pdo,array $subjectKeys):int{
    if(!loginThrottleStorageAvailable($pdo))return 0;
    try{
        $select=$pdo->prepare('SELECT last_attempt_at,locked_until FROM login_throttle_attempts WHERE subject_key=? LIMIT 1');
        $reset=$pdo->prepare('UPDATE login_throttle_attempts SET failed_attempts=0,locked_until=NULL,updated_at=NOW() WHERE subject_key=?');
        $now=time();$limits=loginThrottleLimits($pdo);$remaining=0;
        foreach(array_unique($subjectKeys) as $subjectKey){
            $select->execute([$subjectKey]);$row=$select->fetch(PDO::FETCH_ASSOC);
            if(!$row)continue;
            $lockedUntil=!empty($row['locked_until'])?(strtotime($row['locked_until'])?:0):0;
            if($lockedUntil>$now){$remaining=max($remaining,$lockedUntil-$now);continue;}
            $lastAttempt=strtotime($row['last_attempt_at'])?:0;
            if($lockedUntil||$lastAttempt<=($now-$limits['lockout_minutes']*60))$reset->execute([$subjectKey]);
        }
        return $remaining;
    }catch(Throwable $e){error_log('[LOGIN THROTTLE] Lecture impossible : '.$e->getMessage());return 0;}
}

function loginThrottleRegisterFailure(PDO $pdo,array $subjectKeys):int{
    if(!loginThrottleStorageAvailable($pdo))return 0;
    $started=false;
    try{
        $limits=loginThrottleLimits($pdo);$now=time();$nowSql=date('Y-m-d H:i:s',$now);$ipHash=loginThrottleIpHash();
        if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}
        $select=$pdo->prepare('SELECT id,failed_attempts,last_attempt_at FROM login_throttle_attempts WHERE subject_key=? FOR UPDATE');
        $update=$pdo->prepare('UPDATE login_throttle_attempts SET failed_attempts=?,last_ip_hash=?,last_attempt_at=?,locked_until=?,updated_at=? WHERE id=?');
        $insert=$pdo->prepare('INSERT INTO login_throttle_attempts (subject_key,last_ip_hash,failed_attempts,last_attempt_at,locked_until,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
        $remaining=0;
        foreach(array_unique($subjectKeys) as $subjectKey){
            $select->execute([$subjectKey]);$row=$select->fetch(PDO::FETCH_ASSOC);
            $recent=$row&&(strtotime($row['last_attempt_at'])?:0)>($now-$limits['lockout_minutes']*60);
            $attempts=$recent?(int)$row['failed_attempts']+1:1;
            $lockedUntil=$attempts>=$limits['max_attempts']?date('Y-m-d H:i:s',$now+$limits['lockout_minutes']*60):null;
            if($row)$update->execute([$attempts,$ipHash,$nowSql,$lockedUntil,$nowSql,$row['id']]);
            else $insert->execute([$subjectKey,$ipHash,$attempts,$nowSql,$lockedUntil,$nowSql,$nowSql]);
            if($lockedUntil)$remaining=max($remaining,(strtotime($lockedUntil)?:$now)-$now);
        }
        if($started)$pdo->commit();
        return $remaining;
    }catch(Throwable $e){
        if($started&&$pdo->inTransaction())$pdo->rollBack();
        error_log('[LOGIN THROTTLE] Ecriture impossible : '.$e->getMessage());
        return 0;
    }
}

function loginThrottleClear(PDO $pdo,array $subjectKeys):void{
    if(!loginThrottleStorageAvailable($pdo))return;
    try{
        $keys=array_values(array_unique($subjectKeys));if(!$keys)return;
        $placeholders=implode(',',array_fill(0,count($keys),'?'));
        $pdo->prepare("DELETE FROM login_throttle_attempts WHERE subject_key IN ($placeholders)")->execute($keys);
    }catch(Throwable $e){error_log('[LOGIN THROTTLE] Reinitialisation impossible : '.$e->getMessage());}
}

function loginThrottleMessage(int $remainingSeconds):string{
    $minutes=max(1,(int)ceil($remainingSeconds/60));
    return 'Trop de tentatives de connexion. Reessayez dans '.$minutes.' minute'.($minutes>1?'s':'').'.';
}

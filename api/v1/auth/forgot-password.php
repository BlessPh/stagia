<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../services/MailService.php';

requireApiMethod('POST');
$input=apiInput();
$identifier=trim((string)($input['identifiant']??$input['email']??''));

if($identifier===''){
    apiResponse(false,"L'identifiant ou l'adresse e-mail est obligatoire.",[],422);
}

$genericMessage='Si un compte actif correspond à ces informations, un e-mail de réinitialisation sera envoyé.';

try{
    $user=apiFindRecoveryUser($pdo,$identifier);
    if(!$user
        || !(int)$user['actif']
        || ($user['statut_compte']??'ACTIF')!=='ACTIF'
        || !filter_var($user['email'],FILTER_VALIDATE_EMAIL)
    ){
        apiResponse(true,$genericMessage,[],202);
    }

    $stmt=$pdo->prepare("
        SELECT COUNT(*)
        FROM password_reset_tokens
        WHERE user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)
    ");
    $stmt->execute([$user['id']]);
    if((int)$stmt->fetchColumn()>=3){
        apiResponse(true,$genericMessage,[],202);
    }

    $resetBase=trim((string)(getenv('MOBILE_PASSWORD_RESET_URL')?:''));
    if($resetBase===''){
        error_log('[PASSWORD RESET] MOBILE_PASSWORD_RESET_URL manquant.');
        apiResponse(true,$genericMessage,[],202);
    }

    $token=apiRandomToken(32);
    $tokenHash=hash('sha256',$token);
    $requestIp=substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)?:null;

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
        ->execute([$user['id']]);
    $stmt=$pdo->prepare("
        INSERT INTO password_reset_tokens(user_id,token_hash,requested_ip,expires_at)
        VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 60 MINUTE))
    ");
    $stmt->execute([$user['id'],$tokenHash,$requestIp]);
    $pdo->commit();

    $separator=str_contains($resetBase,'?')?'&':'?';
    $resetLink=$resetBase.$separator.http_build_query(['token'=>$token],'','&',PHP_QUERY_RFC3986);
    $name=trim(($user['prenom']??'').' '.($user['nom']??'').' '.($user['postnom']??''));

    if(!MailService::envoyerReinitialisationMotDePasse($user['email'],$name,$resetLink)){
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE token_hash=? AND used_at IS NULL')
            ->execute([$tokenHash]);
    }

    apiResponse(true,$genericMessage,[],202);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('API forgot password: '.$e->getMessage());
    apiResponse(false,"Une erreur interne empêche la demande de réinitialisation.",[],500);
}

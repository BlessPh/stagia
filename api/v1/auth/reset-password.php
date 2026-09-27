<?php

require_once __DIR__.'/../bootstrap.php';

requireApiMethod('POST');
$input=apiInput();
$token=trim((string)($input['token']??''));
$password=(string)($input['password']??'');
$confirmation=(string)($input['password_confirmation']??$input['confirmation']??'');

if($token==='' || !ctype_xdigit($token) || strlen($token)!==64){
    apiResponse(false,'Le lien de réinitialisation est invalide ou expiré.',[],422);
}
if($password!==$confirmation){
    apiResponse(false,'La confirmation du mot de passe ne correspond pas.',[],422);
}
if(!apiPasswordIsValid($password)){
    apiResponse(false,'Le mot de passe doit contenir au moins 8 caractères, une majuscule, une minuscule, un chiffre et un caractère spécial.',[],422);
}

try{
    $pdo->beginTransaction();
    $stmt=$pdo->prepare("
        SELECT prt.id AS reset_id,prt.user_id,u.actif,u.statut_compte
        FROM password_reset_tokens prt
        INNER JOIN users u ON u.id=prt.user_id
        WHERE prt.token_hash=? AND prt.used_at IS NULL AND prt.expires_at>NOW()
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([hash('sha256',$token)]);
    $reset=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$reset || !(int)$reset['actif'] || ($reset['statut_compte']??'ACTIF')!=='ACTIF'){
        $pdo->rollBack();
        apiResponse(false,'Le lien de réinitialisation est invalide ou expiré.',[],422);
    }

    $pdo->prepare('UPDATE users SET password=?,must_change_password=0 WHERE id=?')
        ->execute([password_hash($password,PASSWORD_DEFAULT),$reset['user_id']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')
        ->execute([$reset['user_id']]);
    $pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([$reset['user_id']]);
    $pdo->commit();

    apiResponse(true,'Mot de passe réinitialisé. Vous pouvez maintenant vous connecter.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('API reset password: '.$e->getMessage());
    apiResponse(false,"Une erreur interne empêche la réinitialisation du mot de passe.",[],500);
}

<?php

require_once __DIR__.'/../bootstrap.php';

requireApiMethod('POST');
$input=apiInput();
$refreshToken=trim((string)($input['refresh_token']??''));

if($refreshToken===''){
    apiResponse(false,'Refresh token obligatoire.',[],422);
}

try{
    $pdo->beginTransaction();
    $stmt=$pdo->prepare("
        SELECT t.id AS token_id,t.user_id,u.actif,u.statut_compte
        FROM api_tokens t
        INNER JOIN users u ON u.id=t.user_id
        WHERE t.refresh_token_hash=? AND t.refresh_expires_at>NOW()
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([hash('sha256',$refreshToken)]);
    $session=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$session){
        $pdo->rollBack();
        apiResponse(false,'Refresh token invalide ou expiré.',[],401);
    }
    if(!(int)$session['actif'] || ($session['statut_compte']??'ACTIF')!=='ACTIF'){
        $pdo->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$session['token_id']]);
        $pdo->commit();
        apiResponse(false,"Ce compte n'est plus autorisé à se connecter.",[],401);
    }

    $access=loadUserAccessContext($pdo,(int)$session['user_id']);
    if(empty($access['ok'])){
        $pdo->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$session['token_id']]);
        $pdo->commit();
        apiResponse(false,"Ce compte ne possède plus d'accès actif.",[],403);
    }

    if(!in_array('STAGIAIRE',$access['role_codes']??[],true)){
        $pdo->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$session['token_id']]);
        $pdo->commit();
        apiResponse(false,'Accès mobile réservé aux étudiants ayant le rôle STAGIAIRE.',[],403);
    }

    $newAccess=apiRandomToken(32);
    $newRefresh=apiRandomToken(48);
    $stmt=$pdo->prepare("
        UPDATE api_tokens
        SET token_hash=?,refresh_token_hash=?,expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND),
            refresh_expires_at=DATE_ADD(NOW(),INTERVAL ? SECOND),last_used_at=NOW()
        WHERE id=?
    ");
    $stmt->execute([
        hash('sha256',$newAccess),
        hash('sha256',$newRefresh),
        API_ACCESS_TOKEN_TTL,
        API_REFRESH_TOKEN_TTL,
        $session['token_id']
    ]);
    $pdo->commit();

    apiResponse(true,'Token renouvelé.',apiTokenPayload($newAccess,$newRefresh));
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('API refresh token: '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche le renouvellement du token.',[],500);
}

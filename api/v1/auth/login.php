<?php

require_once __DIR__.'/../bootstrap.php';

requireApiMethod('POST');
$input=apiInput();
$login=trim((string)($input['identifiant']??$input['login']??''));
$password=(string)($input['password']??'');
$deviceName=trim((string)($input['device_name']??'mobile'))?:'mobile';

if($login==='' || $password===''){
    apiResponse(false,'Identifiant et mot de passe obligatoires.',[],422);
}

try{
    $result=apiFindLoginUser($pdo,$login);
    if($result['ambiguous']){
        apiResponse(false,'Plusieurs comptes correspondent à ce nom. Utilisez votre identifiant, votre e-mail ou votre code STAGIA.',[],422);
    }

    $user=$result['user'];
    if(!$user || empty($user['password']) || !password_verify($password,$user['password'])){
        apiResponse(false,'Identifiant ou mot de passe incorrect.',[],401);
    }
    if(!(int)$user['actif'] || ($user['statut_compte']??'ACTIF')==='SUSPENDU'){
        apiResponse(false,'Votre compte est désactivé.',[],403);
    }
    if(($user['statut_compte']??'ACTIF')==='A_ACTIVER'){
        apiResponse(false,"Votre compte n'est pas encore activé. Utilisez le lien d'activation reçu.",[],403);
    }

    $access=loadUserAccessContext($pdo,(int)$user['id']);
    if(empty($access['ok'])){
        $message=$access['reason']==='NO_ACTIVE_ASSIGNMENT'
            ?'Votre compte ne possède actuellement aucune affectation de rôle active.'
            :'Aucun rôle valide n’est attribué à votre compte.';
        apiResponse(false,$message,[],403);
    }

    $identity=apiUserData($pdo,(int)$user['id']);
    if(!$identity){
        apiResponse(false,'Utilisateur introuvable.',[],404);
    }

    $pdo->beginTransaction();
    $tokens=createApiSession($pdo,(int)$user['id'],$deviceName);
    $pdo->prepare('UPDATE users SET derniere_connexion=NOW() WHERE id=?')->execute([$user['id']]);
    $pdo->commit();

    apiResponse(true,'Connexion réussie.',array_merge($tokens,$identity));
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('API login: '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche la connexion.',[],500);
}

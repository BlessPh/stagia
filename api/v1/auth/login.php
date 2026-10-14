<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/student-account-deletion.php';
require_once __DIR__.'/../../../includes/login-throttle.php';

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
    $user=$result['user'];
    $throttleKeys=loginThrottleKeys($user,$login);
    $remainingSeconds=loginThrottleRemainingSeconds($pdo,$throttleKeys);
    if($remainingSeconds>0){
        header('Retry-After: '.$remainingSeconds);
        apiResponse(false,loginThrottleMessage($remainingSeconds),[],429);
    }
    if($result['ambiguous']){
        $remainingSeconds=loginThrottleRegisterFailure($pdo,$throttleKeys);
        if($remainingSeconds>0){
            header('Retry-After: '.$remainingSeconds);
            apiResponse(false,loginThrottleMessage($remainingSeconds),[],429);
        }
        apiResponse(false,'Plusieurs comptes correspondent à ce nom. Utilisez votre identifiant, votre e-mail ou votre code STAGIA.',[],422);
    }

    $user=$result['user'];
    if(!$user || empty($user['password']) || !password_verify($password,$user['password'])){
        $remainingSeconds=loginThrottleRegisterFailure($pdo,$throttleKeys);
        if($remainingSeconds>0){
            header('Retry-After: '.$remainingSeconds);
            apiResponse(false,loginThrottleMessage($remainingSeconds),[],429);
        }
        apiResponse(false,'Identifiant ou mot de passe incorrect.',[],401);
    }
    loginThrottleClear($pdo,$throttleKeys);
    /* Une connexion valide pendant le delai de grace annule la suppression programmee. */
    $lifecycle=studentAccountCancelDeletionOnLogin($pdo,(int)$user['id']);
    if(!empty($lifecycle['expired'])){
        apiResponse(false,'Le delai de grace de 30 jours est expire et ce compte a ete supprime.',[],410);
    }
    if(!empty($lifecycle['reactivated'])){
        $user['actif']=1;
        $user['statut_compte']='ACTIF';
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

    if(!in_array('STAGIAIRE',$access['role_codes']??[],true)){
        apiResponse(false,'Accès mobile réservé aux étudiants ayant le rôle STAGIAIRE.',[],403);
    }

    $identity=apiUserData($pdo,(int)$user['id']);
    if(!$identity){
        apiResponse(false,'Utilisateur introuvable.',[],404);
    }

    $pdo->beginTransaction();
    $tokens=createApiSession($pdo,(int)$user['id'],$deviceName);
    $pdo->prepare('UPDATE users SET derniere_connexion=NOW() WHERE id=?')->execute([$user['id']]);
    $pdo->commit();

    apiResponse(true,!empty($lifecycle['reactivated'])?'Compte reactive et connexion reussie.':'Connexion réussie.',array_merge($tokens,$identity,[
        'account_reactivated'=>!empty($lifecycle['reactivated'])
    ]));
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('API login: '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche la connexion.',[],500);
}

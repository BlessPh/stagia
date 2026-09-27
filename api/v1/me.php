<?php

require_once __DIR__.'/bootstrap.php';

requireApiMethod('GET');

try{
    $authenticated=requireApiUser($pdo);
    $identity=apiUserData($pdo,(int)$authenticated['user_id']);
    if(!$identity){
        apiResponse(false,'Utilisateur introuvable.',[],404);
    }
    apiResponse(true,'Utilisateur authentifié.',$identity);
}catch(Throwable $e){
    error_log('API me: '.$e->getMessage());
    apiResponse(false,"Une erreur interne empêche le chargement de l'utilisateur.",[],500);
}

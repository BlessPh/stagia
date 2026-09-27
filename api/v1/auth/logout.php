<?php

require_once __DIR__.'/../bootstrap.php';

requireApiMethod('POST');

try{
    $user=requireApiUser($pdo);
    $pdo->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$user['token_id']]);
    apiResponse(true,'Déconnexion réussie.');
}catch(Throwable $e){
    error_log('API logout: '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche la déconnexion.',[],500);
}

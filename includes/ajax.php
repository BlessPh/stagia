<?php
/** Utilitaires communs aux endpoints AJAX : session, réponse JSON, rôle et CSRF. */

if(session_status()===PHP_SESSION_NONE) session_start();

/** Produit une réponse JSON uniforme et termine immédiatement l'exécution. */
function jsonResponse(bool $success,string $message='',array $data=[],int $status=200){
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success'=>$success,
        'message'=>$message,
        'data'=>$data
    ],JSON_UNESCAPED_UNICODE);

    exit;
}

/** Exige une session utilisateur avant tout traitement AJAX protégé. */
function requireAjaxAuth(){
    if(empty($_SESSION['user_id']))
        jsonResponse(false,'Votre session a expiré.',[],401);
}

/** Exige une session et un rôle autorisé pour l'opération AJAX demandée. */
function requireAjaxRole(array $roles){
    requireAjaxAuth();

    if(!in_array($_SESSION['role_code']??'',$roles,true))
        jsonResponse(false,'Vous n’êtes pas autorisé à effectuer cette opération.',[],403);
}

/** Compare le jeton POST avec celui de la session pour bloquer les requêtes intersites. */
function verifyAjaxCsrf(){
    $token=$_POST['csrf']??'';

    if(!$token || !hash_equals($_SESSION['csrf']??'',$token))
        jsonResponse(false,'Jeton de sécurité invalide. Rechargez la page.',[],419);
}

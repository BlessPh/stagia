<?php
/**
 * Endpoint AJAX d'affectation individuelle d'un stagiaire à un service d'accueil.
 * La logique métier détaillée est centralisée dans le service d'affectation partagé.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

/* Dépendances communes et service qui applique les règles d'affectation. */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-assignment-service.php';

/* Toute écriture est protégée contre les soumissions intersites. */
$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* L'établissement et l'utilisateur sont repris de la session, jamais du formulaire. */
    $hostId=currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);

    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);
    if(!$userId)jsonResponse(false,'Session utilisateur invalide.',[],401);

    /*
     * L'autorisation fine est volontairement centralisée dans
     * stageAssignmentSaveOne(): rôle actif en base + établissement +
     * coordination du Chef + service enfant + paiement + période.
     * On évite ici requireAjaxRole(), qui dépend du rôle courant en session
     * et provoquait le refus générique malgré une affectation CHEF_SERVICE active.
     */
    /* Le service vérifie rôle actif, unité, coordination, paiement et période de stage. */
    $result=stageAssignmentSaveOne($pdo,$hostId,$userId,$_POST);
    jsonResponse(true,$result['message']??'Affectation enregistrée.',$result);
}catch(Throwable $e){
    error_log('[HOST ASSIGNMENT SAVE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],422);
}

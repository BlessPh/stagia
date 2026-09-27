<?php
/** Endpoint AJAX d'activation ou désactivation d'un identifiant de pointage. */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

/* Connexion, réponse JSON et règles RBAC partagées. */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Seul l'accueil gestionnaire peut modifier l'état d'un identifiant. */
requireAjaxRole(['ADMIN_ACCUEIL']);
if(function_exists('contextPermission')&&!contextPermission('attendance.device.manage'))
    jsonResponse(false,'Permission insuffisante.',[],403);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* L'état entrant est normalisé en booléen avant l'écriture SQL. */
    $hostId=(int)currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $actif=(int)($_POST['actif']??0)?1:0;

    /* La jointure avec l'appareil empêche toute modification hors établissement courant. */
    $s=$pdo->prepare("UPDATE attendance_device_credentials dc
        INNER JOIN attendance_devices d ON d.id=dc.device_id
        SET dc.actif=?
        WHERE dc.id=? AND d.host_etablissement_id=?");
    $s->execute([$actif,$id,$hostId]);
    jsonResponse(true,$actif?'Identifiant activé.':'Identifiant désactivé.');
}catch(Throwable $e){
    /* Erreur AJAX maîtrisée pour une requête invalide ou une écriture impossible. */
    jsonResponse(false,$e->getMessage(),[],422);
}

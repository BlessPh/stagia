<?php
/** Endpoint AJAX d'activation ou désactivation d'un appareil de pointage. */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

/* Connexion, réponse JSON et contrôle d'autorisation commun. */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Seul le rôle d'accueil habilité peut modifier l'état opérationnel d'un appareil. */
requireAjaxRole(['ADMIN_ACCUEIL']);
if(function_exists('contextPermission')&&!contextPermission('attendance.device.manage'))
    jsonResponse(false,'Permission insuffisante.',[],403);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* Validation de l'appareil ciblé et de la valeur binaire active ou inactive. */
    $hostId=(int)currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $actif=(int)($_POST['actif']??0)?1:0;
    if(!$hostId||!$id)jsonResponse(false,'Appareil invalide.',[],422);

    /* L'établissement courant est inclus à l'écriture afin de préserver le cloisonnement. */
    $s=$pdo->prepare("UPDATE attendance_devices SET actif=? WHERE id=? AND host_etablissement_id=?");
    $s->execute([$actif,$id,$hostId]);
    if(!$s->rowCount()){
        $c=$pdo->prepare("SELECT 1 FROM attendance_devices WHERE id=? AND host_etablissement_id=?");
        $c->execute([$id,$hostId]);
        if(!$c->fetchColumn())jsonResponse(false,'Appareil introuvable.',[],404);
    }
    jsonResponse(true,$actif?'Appareil activé.':'Appareil désactivé.');
}catch(Throwable $e){
    /* Réponse contrôlée en cas d'échec de validation ou de persistance. */
    jsonResponse(false,$e->getMessage(),[],422);
}

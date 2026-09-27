<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);verifyAjaxCsrf();
if(function_exists('contextPermission')&&!contextPermission('user.disable'))jsonResponse(false,'Permission insuffisante.',[],403);

$actorRole=$_SESSION['role_code']??'';$super=$actorRole==='SUPER_ADMIN';
$id=(int)($_POST['id']??0);$eid=$super?0:(int)currentEtablissementId($pdo);
if(!$super&&!$eid)jsonResponse(false,'Aucun établissement associé à votre compte.',[],403);
if(!$id)jsonResponse(false,'Utilisateur invalide.',[],422);
if($id===(int)($_SESSION['user_id']??0))jsonResponse(false,'Vous ne pouvez pas suspendre votre propre compte.',[],409);

$s=$pdo->prepare("SELECT id,statut_compte,actif FROM users WHERE id=? LIMIT 1");$s->execute([$id]);$u=$s->fetch(PDO::FETCH_ASSOC);
if(!$u)jsonResponse(false,'Utilisateur introuvable.',[],404);

if(!$super){
    $s=$pdo->prepare("SELECT 1 FROM etablissement_users WHERE user_id=? AND etablissement_id=? LIMIT 1");
    $s->execute([$id,$eid]);if(!$s->fetchColumn())jsonResponse(false,'Utilisateur hors de votre établissement.',[],403);

    $s=$pdo->prepare("SELECT 1 FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=? AND ra.actif=1 AND r.code IN('SUPER_ADMIN','MINISTERE','ORDRE_MEDECINS','STAGIAIRE') LIMIT 1");
    $s->execute([$id]);if($s->fetchColumn())jsonResponse(false,'Ce compte ne peut pas être géré depuis cet établissement.',[],403);
}

if($u['statut_compte']==='A_ACTIVER')
    jsonResponse(false,'Ce compte doit d’abord être activé ou recevoir une nouvelle invitation.',[],409);

if($u['statut_compte']==='ACTIF'){
    if($super){
        $s=$pdo->prepare("SELECT 1 FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.user_id=? AND ra.actif=1 AND r.code='SUPER_ADMIN' LIMIT 1");
        $s->execute([$id]);
        if($s->fetchColumn()){
            $count=$pdo->query("SELECT COUNT(DISTINCT ra.user_id) FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
                JOIN users u ON u.id=ra.user_id WHERE ra.actif=1 AND r.code='SUPER_ADMIN' AND u.statut_compte='ACTIF' AND u.actif=1")->fetchColumn();
            if((int)$count<=1)jsonResponse(false,'Impossible de suspendre le dernier Super Admin actif.',[],409);
        }
    }
    $pdo->prepare("UPDATE users SET actif=0,statut_compte='SUSPENDU' WHERE id=?")->execute([$id]);
    jsonResponse(true,'Utilisateur suspendu.');
}

if($u['statut_compte']==='SUSPENDU'){
    $pdo->prepare("UPDATE users SET actif=1,statut_compte='ACTIF' WHERE id=?")->execute([$id]);
    jsonResponse(true,'Utilisateur réactivé.');
}
jsonResponse(false,'Statut de compte non pris en charge.',[],409);

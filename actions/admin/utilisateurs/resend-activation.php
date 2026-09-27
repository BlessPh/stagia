<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../services/MailService.php';

requireAjaxRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);verifyAjaxCsrf();
if(function_exists('contextPermission')&&!contextPermission('user.update'))jsonResponse(false,'Permission insuffisante.',[],403);

$actorRole=$_SESSION['role_code']??'';$super=$actorRole==='SUPER_ADMIN';
$id=(int)($_POST['id']??0);$eid=$super?0:(int)currentEtablissementId($pdo);
if(!$super&&!$eid)jsonResponse(false,'Aucun établissement associé à votre compte.',[],403);
if(!$id)jsonResponse(false,'Utilisateur invalide.',[],422);

$s=$pdo->prepare("SELECT id,nom,postnom,prenom,email,statut_compte FROM users WHERE id=? LIMIT 1");
$s->execute([$id]);$u=$s->fetch(PDO::FETCH_ASSOC);
if(!$u)jsonResponse(false,'Utilisateur introuvable.',[],404);

if(!$super){
    $s=$pdo->prepare("SELECT 1 FROM etablissement_users WHERE user_id=? AND etablissement_id=? LIMIT 1");
    $s->execute([$id,$eid]);if(!$s->fetchColumn())jsonResponse(false,'Utilisateur hors de votre établissement.',[],403);

    $s=$pdo->prepare("SELECT 1 FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=? AND ra.actif=1 AND r.code IN('SUPER_ADMIN','MINISTERE','ORDRE_MEDECINS','STAGIAIRE') LIMIT 1");
    $s->execute([$id]);if($s->fetchColumn())jsonResponse(false,'Ce compte ne peut pas être géré depuis cet établissement.',[],403);
}

if($u['statut_compte']!=='A_ACTIVER')jsonResponse(false,'Ce compte n’est pas en attente d’activation.',[],409);
if(!filter_var($u['email'],FILTER_VALIDATE_EMAIL))jsonResponse(false,'L’utilisateur ne possède pas une adresse e-mail valide.',[],422);

$token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$expiration=date('Y-m-d H:i:s',time()+48*3600);
$pdo->prepare("UPDATE users SET activation_token_hash=?,activation_expire_at=? WHERE id=?")->execute([$hash,$expiration,$id]);

$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
$url=$scheme.'://'.$_SERVER['HTTP_HOST'].BASE_URL.'/activate.php?token='.urlencode($token);
$nom=trim(($u['prenom']??'').' '.$u['nom'].' '.($u['postnom']??''));
$mail=MailService::envoyerActivation($u['email'],$nom,$url);

if(!$mail){
    error_log('[USER RESEND SMTP] '.MailService::getLastError());
    jsonResponse(false,'Invitation régénérée, mais l’e-mail n’a pas pu être envoyé.',[],502);
}
jsonResponse(true,'Invitation d’activation renvoyée.');

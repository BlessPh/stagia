<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);verifyAjaxCsrf();
if(function_exists('contextPermission')&&!contextPermission('user.update'))jsonResponse(false,'Permission insuffisante.',[],403);

$actorRole=$_SESSION['role_code']??'';$super=$actorRole==='SUPER_ADMIN';
$id=(int)($_POST['id']??0);$nom=trim($_POST['nom']??'');$postnom=trim($_POST['postnom']??'');
$prenom=trim($_POST['prenom']??'');$email=strtolower(trim($_POST['email']??''));
$telephone=trim($_POST['telephone']??'');$fonction=trim($_POST['fonction']??'');
$eid=$super?((int)($_POST['etablissement_id']??0)?:0):(int)currentEtablissementId($pdo);

if(!$super&&!$eid)jsonResponse(false,'Aucun établissement associé à votre compte.',[],403);
if(!$id||$nom===''||!filter_var($email,FILTER_VALIDATE_EMAIL))
    jsonResponse(false,'Nom et e-mail valide sont obligatoires.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT id FROM users WHERE id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);
    if(!$s->fetchColumn())throw new RuntimeException('Utilisateur introuvable.');

    if(!$super){
        $s=$pdo->prepare("SELECT 1 FROM etablissement_users WHERE user_id=? AND etablissement_id=? LIMIT 1");
        $s->execute([$id,$eid]);if(!$s->fetchColumn())throw new RuntimeException('Utilisateur hors de votre établissement.');

        $s=$pdo->prepare("SELECT 1 FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
            WHERE ra.user_id=? AND ra.actif=1 AND r.code IN('SUPER_ADMIN','MINISTERE','ORDRE_MEDECINS','STAGIAIRE') LIMIT 1");
        $s->execute([$id]);if($s->fetchColumn())throw new RuntimeException('Ce compte ne peut pas être modifié depuis cet établissement.');
    }

    $s=$pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email))=LOWER(TRIM(?)) AND id<>? LIMIT 1");
    $s->execute([$email,$id]);if($s->fetchColumn())throw new RuntimeException('Cette adresse e-mail est déjà utilisée.');

    $pdo->prepare("UPDATE users SET nom=?,postnom=?,prenom=?,email=?,telephone=? WHERE id=?")
        ->execute([$nom,$postnom?:null,$prenom?:null,$email,$telephone?:null,$id]);

    if(!$super){
        $pdo->prepare("UPDATE etablissement_users SET fonction=? WHERE user_id=? AND etablissement_id=?")
            ->execute([$fonction?:null,$id,$eid]);
    }elseif($eid){
        $pdo->prepare("UPDATE etablissement_users SET fonction=? WHERE user_id=? AND etablissement_id=?")
            ->execute([$fonction?:null,$id,$eid]);
    }else{
        $pdo->prepare("UPDATE etablissement_users SET fonction=? WHERE user_id=?")
            ->execute([$fonction?:null,$id]);
    }

    $pdo->commit();jsonResponse(true,'Utilisateur mis à jour.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

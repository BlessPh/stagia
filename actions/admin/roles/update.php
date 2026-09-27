<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$contexte=exigerAdministrationRolesAjax($pdo);verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
$nom=trim($_POST['nom']??'');
$description=trim($_POST['description']??'');

if(!$id||$nom==='')jsonResponse(false,'Rôle et nom obligatoires.',[],422);
if(mb_strlen($nom)>100)jsonResponse(false,'Le nom du rôle est trop long.',[],422);

$s=$pdo->prepare("SELECT id,code,systeme,etablissement_id FROM roles WHERE id=? LIMIT 1");
$s->execute([$id]);$role=$s->fetch(PDO::FETCH_ASSOC);
if(!$role)jsonResponse(false,'Rôle introuvable.',[],404);
if(!roleModifiableDansContexte($role,$contexte))jsonResponse(false,'Ce rôle appartient au catalogue système ou à un autre établissement.',[],403);

$s=$pdo->prepare("SELECT 1 FROM roles WHERE LOWER(TRIM(nom))=LOWER(TRIM(?)) AND id<>? AND ".($contexte['super']?"systeme=1":"systeme=0 AND etablissement_id=?")." LIMIT 1");
$s->execute($contexte['super']?[$nom,$id]:[$nom,$id,$contexte['etablissement_id']]);
if($s->fetchColumn())jsonResponse(false,'Un autre rôle porte déjà ce nom.',[],409);

$pdo->prepare("UPDATE roles SET nom=?,description=? WHERE id=?")
    ->execute([$nom,$description?:null,$id]);

jsonResponse(true,'Rôle mis à jour. Le code '.$role['code'].' reste inchangé.');

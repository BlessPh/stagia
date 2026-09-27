<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$contexte=exigerAdministrationRolesAjax($pdo);verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
if(!$id)jsonResponse(false,'Rôle invalide.',[],422);

$s=$pdo->prepare("SELECT id,code,nom,actif,systeme,etablissement_id FROM roles WHERE id=? LIMIT 1");
$s->execute([$id]);$role=$s->fetch(PDO::FETCH_ASSOC);
if(!$role)jsonResponse(false,'Rôle introuvable.',[],404);

if(!roleModifiableDansContexte($role,$contexte))jsonResponse(false,'Ce rôle ne peut pas être modifié dans votre périmètre.',[],403);
if((int)$role['systeme']===1)jsonResponse(false,'Les rôles système STAGIA ne peuvent pas être désactivés.',[],409);

if((int)$role['actif']===1){
    $s=$pdo->prepare("SELECT COUNT(*) FROM role_assignments WHERE role_id=? AND actif=1");
    $s->execute([$id]);$uses=(int)$s->fetchColumn();
    if($uses>0)
        jsonResponse(false,"Ce rôle possède $uses affectation(s) active(s). Révoquez-les avant de le désactiver.",[],409);

    $pdo->prepare("UPDATE roles SET actif=0 WHERE id=?")->execute([$id]);
    jsonResponse(true,'Rôle désactivé.');
}

$pdo->prepare("UPDATE roles SET actif=1 WHERE id=?")->execute([$id]);
jsonResponse(true,'Rôle réactivé.');

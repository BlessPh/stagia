<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$contexte=exigerAdministrationRolesAjax($pdo);

$id=(int)($_GET['id']??0);
if(!$id)jsonResponse(false,'Rôle invalide.',[],422);

$s=$pdo->prepare("SELECT id,code,nom,description,actif,systeme,etablissement_id FROM roles WHERE id=? LIMIT 1");
$s->execute([$id]);$role=$s->fetch(PDO::FETCH_ASSOC);
if(!$role)jsonResponse(false,'Rôle introuvable.',[],404);
if(!roleVisibleDansContexte($role,$contexte))jsonResponse(false,'Rôle hors de votre établissement.',[],403);
$modifiable=roleModifiableDansContexte($role,$contexte);

$sql="
    SELECT id,code,nom,module,description
    FROM permissions
    WHERE actif=1
";
$params=[];
if(!$contexte['super']){
    $sql.=" AND id IN(SELECT rp.permission_id FROM role_assignments ra JOIN role_permissions rp ON rp.role_id=ra.role_id WHERE ra.user_id=? AND ra.actif=1)";
    $params[]=currentUserId();
}
$sql.=" ORDER BY module,nom";
$s=$pdo->prepare($sql);$s->execute($params);$permissions=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id=?");
$s->execute([$id]);$selected=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));

if($role['code']==='SUPER_ADMIN')
    $selected=array_map(fn($p)=>(int)$p['id'],$permissions);

$role['modifiable']=$modifiable?1:0;
jsonResponse(true,'',['role'=>$role,'permissions'=>$permissions,'selected'=>$selected]);

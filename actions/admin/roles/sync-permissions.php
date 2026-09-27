<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$contexte=exigerAdministrationRolesAjax($pdo);verifyAjaxCsrf();

$roleId=(int)($_POST['role_id']??0);
$ids=$_POST['permission_ids']??[];
if(!is_array($ids))$ids=[];
$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));

if(!$roleId)jsonResponse(false,'Rôle invalide.',[],422);

$s=$pdo->prepare("SELECT id,code,nom,systeme,etablissement_id FROM roles WHERE id=? LIMIT 1");
$s->execute([$roleId]);$role=$s->fetch(PDO::FETCH_ASSOC);
if(!$role)jsonResponse(false,'Rôle introuvable.',[],404);
if(!roleModifiableDansContexte($role,$contexte))jsonResponse(false,'Ce rôle ne peut pas recevoir de permissions dans votre périmètre.',[],403);
if($role['code']==='SUPER_ADMIN')
    jsonResponse(false,'Le Super Admin possède automatiquement toutes les permissions STAGIA.',[],409);

try{
    $pdo->beginTransaction();

    if($ids){
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $sql="SELECT id FROM permissions WHERE actif=1 AND id IN($ph)";$params=$ids;
        if(!$contexte['super']){$sql.=" AND id IN(SELECT rp.permission_id FROM role_assignments ra JOIN role_permissions rp ON rp.role_id=ra.role_id WHERE ra.user_id=? AND ra.actif=1)";$params[]=currentUserId();}
        $s=$pdo->prepare($sql);
        $s->execute($params);
        $valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        sort($valid);$check=$ids;sort($check);
        if($valid!==$check)throw new RuntimeException('Une permission sélectionnée est invalide ou inactive.');
    }

    $pdo->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$roleId]);
    if($ids){
        $insert=$pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)");
        foreach($ids as $permissionId)$insert->execute([$roleId,$permissionId]);
    }

    $pdo->commit();
    jsonResponse(true,'Permissions du rôle enregistrées.',['count'=>count($ids)]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[ADMIN ROLE PERMISSIONS] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();

$permissionId=(int)($_POST['permission_id']??0);
$ids=$_POST['role_ids']??[];
if(!is_array($ids))$ids=[];
$ids=array_values(array_unique(array_filter(array_map('intval',$ids))));

if(!$permissionId)jsonResponse(false,'Permission invalide.',[],422);

$s=$pdo->prepare("SELECT id,code,actif FROM permissions WHERE id=? LIMIT 1");
$s->execute([$permissionId]);$permission=$s->fetch(PDO::FETCH_ASSOC);
if(!$permission)jsonResponse(false,'Permission introuvable.',[],404);
if(!(int)$permission['actif'])jsonResponse(false,'Réactivez cette permission avant de l’attribuer à des rôles.',[],409);

try{
    $pdo->beginTransaction();

    if($ids){
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare("SELECT id FROM roles WHERE actif=1 AND code<>'SUPER_ADMIN' AND id IN($ph)");
        $s->execute($ids);$valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        sort($valid);$check=$ids;sort($check);
        if($valid!==$check)throw new RuntimeException('Un rôle sélectionné est invalide ou inactif.');
    }

    $pdo->prepare("DELETE rp FROM role_permissions rp
        JOIN roles r ON r.id=rp.role_id
        WHERE rp.permission_id=? AND r.code<>'SUPER_ADMIN'")->execute([$permissionId]);

    if($ids){
        $s=$pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)");
        foreach($ids as $roleId)$s->execute([$roleId,$permissionId]);
    }

    $pdo->commit();
    jsonResponse(true,'Rôles autorisés mis à jour.',['count'=>count($ids)]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
if(!$id)jsonResponse(false,'Permission invalide.',[],422);

$s=$pdo->prepare("SELECT id,code,nom,actif FROM permissions WHERE id=? LIMIT 1");
$s->execute([$id]);$p=$s->fetch(PDO::FETCH_ASSOC);
if(!$p)jsonResponse(false,'Permission introuvable.',[],404);

if((int)$p['actif']===1){
    $s=$pdo->prepare("SELECT COUNT(DISTINCT rp.role_id)
        FROM role_permissions rp JOIN roles r ON r.id=rp.role_id
        WHERE rp.permission_id=? AND r.code<>'SUPER_ADMIN' AND r.actif=1");
    $s->execute([$id]);$uses=(int)$s->fetchColumn();

    if($uses>0)
        jsonResponse(false,"Cette permission est encore attribuée à $uses rôle(s) actif(s). Retirez d’abord ces associations.",[],409);

    $pdo->prepare("UPDATE permissions SET actif=0 WHERE id=?")->execute([$id]);
    jsonResponse(true,'Permission désactivée.');
}

$pdo->prepare("UPDATE permissions SET actif=1 WHERE id=?")->execute([$id]);
jsonResponse(true,'Permission réactivée.');

<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$id=(int)($_GET['id']??0);
if(!$id)jsonResponse(false,'Permission invalide.',[],422);

$s=$pdo->prepare("SELECT id,code,nom,module,description,actif FROM permissions WHERE id=? LIMIT 1");
$s->execute([$id]);$permission=$s->fetch(PDO::FETCH_ASSOC);
if(!$permission)jsonResponse(false,'Permission introuvable.',[],404);

$roles=$pdo->query("SELECT id,code,nom,actif,systeme FROM roles WHERE code<>'SUPER_ADMIN' ORDER BY actif DESC,systeme DESC,nom")->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT role_id FROM role_permissions rp
    JOIN roles r ON r.id=rp.role_id
    WHERE rp.permission_id=? AND r.code<>'SUPER_ADMIN'");
$s->execute([$id]);$selected=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));

jsonResponse(true,'',['permission'=>$permission,'roles'=>$roles,'selected'=>$selected]);

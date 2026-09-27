<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$q=trim($_GET['q']??'');
$module=trim($_GET['module']??'');
$status=trim($_GET['status']??'');

$where=['1=1'];$params=[];

if($q!==''){
    $where[]="(p.code LIKE ? OR p.nom LIKE ? OR p.description LIKE ? OR p.module LIKE ?)";
    $like="%$q%";array_push($params,$like,$like,$like,$like);
}
if($module!==''){
    $where[]='p.module=?';$params[]=$module;
}
if($status==='ACTIVE')$where[]='p.actif=1';
elseif($status==='INACTIVE')$where[]='p.actif=0';

$sql="SELECT p.id,p.code,p.nom,p.module,p.description,p.systeme,p.actif,p.created_at,p.updated_at,
      COUNT(DISTINCT CASE WHEN r.code<>'SUPER_ADMIN' THEN rp.role_id END) nb_roles,
      GROUP_CONCAT(DISTINCT CASE WHEN r.code<>'SUPER_ADMIN' THEN r.nom END ORDER BY r.nom SEPARATOR ', ') roles
      FROM permissions p
      LEFT JOIN role_permissions rp ON rp.permission_id=p.id
      LEFT JOIN roles r ON r.id=rp.role_id
      WHERE ".implode(' AND ',$where)."
      GROUP BY p.id
      ORDER BY p.module,p.code";

try{
    $s=$pdo->prepare($sql);$s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    $modules=$pdo->query("SELECT DISTINCT module FROM permissions WHERE module<>'' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
    $stats=$pdo->query("SELECT COUNT(*) total,SUM(actif=1) actives,SUM(actif=0) inactives,COUNT(DISTINCT module) modules FROM permissions")->fetch(PDO::FETCH_ASSOC);
    jsonResponse(true,'',['items'=>$items,'modules'=>$modules,'stats'=>array_map('intval',$stats)]);
}catch(Throwable $e){
    error_log('[ADMIN PERMISSIONS LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

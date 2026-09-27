<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/host-structure.php';

requirePermission($pdo,'host.view');
if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

$eid=(int)($_SESSION['etablissement_id']??0);
$type=strtoupper(trim((string)($_GET['type']??'')));
$search=trim((string)($_GET['search']??''));
$actif=(string)($_GET['actif']??'');
if(!$eid)jsonResponse(false,'Établissement introuvable.',[],403);
if($type!==''&&!in_array($type,['DEPARTEMENT','SERVICE','UNITE','SERVICE_UNITE'],true))jsonResponse(false,'Type invalide.',[],422);

$cfg=hostStructureConfig($pdo,$eid);
try{
    $where=['u.host_etablissement_id=?'];$params=[$eid];
    if($type==='SERVICE_UNITE')$where[]="u.type IN('SERVICE','UNITE')";
    elseif($type!==''){$where[]='u.type=?';$params[]=$type;}
    if($search!==''){$where[]='(u.nom LIKE ? OR u.code LIKE ? OR p.nom LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like);}
    if($actif==='0'||$actif==='1'){$where[]='u.actif=?';$params[]=(int)$actif;}

    $s=$pdo->prepare("SELECT u.id,u.parent_id,u.code,u.nom,u.type,u.description,u.capacite,u.actif,DATE_FORMAT(u.created_at,'%d/%m/%Y') date_creation,p.nom parent_nom,p.type parent_type,(SELECT COUNT(*) FROM host_units c WHERE c.parent_id=u.id AND c.host_etablissement_id=u.host_etablissement_id AND c.actif=1) children_count,(SELECT COUNT(*) FROM stage_assignments a WHERE a.host_unit_id=u.id AND a.host_etablissement_id=u.host_etablissement_id AND a.statut IN('PLANIFIEE','ACTIVE')) active_assignments FROM host_units u LEFT JOIN host_units p ON p.id=u.parent_id AND p.host_etablissement_id=u.host_etablissement_id WHERE ".implode(' AND ',$where)." ORDER BY FIELD(u.type,'DEPARTEMENT','SERVICE','UNITE'),COALESCE(p.nom,''),u.nom");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("SELECT id,code,nom,type,parent_id,actif FROM host_units WHERE host_etablissement_id=? AND actif=1 ORDER BY FIELD(type,'DEPARTEMENT','SERVICE','UNITE'),nom");
    $s->execute([$eid]);$parents=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("SELECT COUNT(*) total,SUM(type='DEPARTEMENT') departments,SUM(type='SERVICE') services,SUM(type='UNITE') units,SUM(actif=1) active_count FROM host_units WHERE host_etablissement_id=?");
    $s->execute([$eid]);$k=$s->fetch(PDO::FETCH_ASSOC)?:[];

    jsonResponse(true,'',['items'=>$items,'parents'=>$parents,'config'=>$cfg,'kpi'=>['total'=>(int)($k['total']??0),'departments'=>(int)($k['departments']??0),'services'=>(int)($k['services']??0),'units'=>(int)($k['units']??0),'active'=>(int)($k['active_count']??0)],'permissions'=>['manage'=>hasPermission($pdo,'host.manage')]]);
}catch(Throwable $e){error_log('[HOST STRUCTURE LIST] '.$e->getMessage());jsonResponse(false,'Impossible de charger la structure d’accueil.',[],500);}

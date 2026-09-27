<?php
declare(strict_types=1);
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';
requireAjaxAuth();

if(!hasRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL'])&&!estResponsableMinisteriel($pdo))
    jsonResponse(false,'Accès refusé.',[],403);

$ids=etablissementsVisiblesAdministration($pdo);
if(!$ids)jsonResponse(true,'',['items'=>[],'organisations'=>[]]);
$ph=implode(',',array_fill(0,count($ids),'?'));
$q=trim($_GET['q']??'');
$etablissementId=(int)($_GET['etablissement_id']??0);
if($etablissementId&&!in_array($etablissementId,$ids,true))jsonResponse(false,'Organisation hors de votre périmètre.',[],403);

$where=["ra.etablissement_id IN($ph)",'ra.actif=1','r.actif=1',"r.code<>'STAGIAIRE'",'(ra.starts_at IS NULL OR ra.starts_at<=NOW())','(ra.ends_at IS NULL OR ra.ends_at>=NOW())'];
$params=$ids;
if($etablissementId){$where[]='ra.etablissement_id=?';$params[]=$etablissementId;}
if($q!==''){$like="%$q%";$where[]='(u.nom LIKE ? OR u.postnom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR r.nom LIKE ? OR e.nom LIKE ?)';array_push($params,$like,$like,$like,$like,$like,$like);}

$s=$pdo->prepare("SELECT DISTINCT u.id,u.nom,u.postnom,u.prenom,u.email,u.telephone,
        e.id etablissement_id,e.nom etablissement_nom,e.type_etablissement,e.province,
        GROUP_CONCAT(DISTINCT r.nom ORDER BY ra.principal DESC,r.nom SEPARATOR ', ') roles
    FROM role_assignments ra
    JOIN users u ON u.id=ra.user_id AND u.actif=1 AND u.statut_compte='ACTIF'
    JOIN roles r ON r.id=ra.role_id
    JOIN etablissements e ON e.id=ra.etablissement_id
    WHERE ".implode(' AND ',$where)."
    GROUP BY u.id,e.id
    ORDER BY e.nom,u.nom,u.postnom,u.prenom");
$s->execute($params);
$items=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT id,nom,type_etablissement,province FROM etablissements WHERE id IN($ph) ORDER BY nom");
$s->execute($ids);
jsonResponse(true,'',['items'=>$items,'organisations'=>$s->fetchAll(PDO::FETCH_ASSOC)]);

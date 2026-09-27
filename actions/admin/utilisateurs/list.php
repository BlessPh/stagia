<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);

try{
    $role=$_SESSION['role_code']??'';
    $super=$role==='SUPER_ADMIN';
    $eid=$super?(int)($_GET['etablissement_id']??0):(int)currentEtablissementId($pdo);
    if(!$super&&!$eid) jsonResponse(false,'Aucun établissement associé à votre compte.',[],403);

    $q=trim($_GET['q']??'');$status=trim($_GET['status']??'');$roleId=(int)($_GET['role_id']??0);
    $where=['1=1'];$params=[];

    if($eid){$where[]="EXISTS(SELECT 1 FROM etablissement_users eu WHERE eu.user_id=u.id AND eu.etablissement_id=?)";$params[]=$eid;}
    if($q!==''){$where[]="(u.nom LIKE ? OR u.postnom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR u.identifiant LIKE ?)";$like="%$q%";array_push($params,$like,$like,$like,$like,$like);}
    if(in_array($status,['ACTIF','A_ACTIVER','SUSPENDU'],true)){$where[]='u.statut_compte=?';$params[]=$status;}
    if($roleId){$where[]="EXISTS(SELECT 1 FROM role_assignments x WHERE x.user_id=u.id AND x.role_id=? AND x.actif=1".($eid?" AND x.etablissement_id=".(int)$eid:"").")";$params[]=$roleId;}

    $rs=$eid?" AND ra.etablissement_id=".(int)$eid:'';
    $fn=$eid
        ?"(SELECT eu.fonction FROM etablissement_users eu WHERE eu.user_id=u.id AND eu.etablissement_id=".(int)$eid." ORDER BY eu.principal DESC,eu.id LIMIT 1)"
        :"(SELECT eu.fonction FROM etablissement_users eu WHERE eu.user_id=u.id ORDER BY eu.principal DESC,eu.id LIMIT 1)";
    $etabs=$eid
        ?"(SELECT e.nom FROM etablissements e WHERE e.id=".(int)$eid." LIMIT 1)"
        :"(SELECT GROUP_CONCAT(DISTINCT e.nom ORDER BY e.nom SEPARATOR ', ') FROM etablissement_users eu JOIN etablissements e ON e.id=eu.etablissement_id WHERE eu.user_id=u.id)";

    $sql="SELECT u.id,u.nom,u.postnom,u.prenom,u.email,u.identifiant,u.telephone,u.actif,u.statut_compte,u.derniere_connexion,u.created_at,
        $fn fonction,
        (SELECT GROUP_CONCAT(DISTINCT r.nom ORDER BY ra.principal DESC,r.nom SEPARATOR ', ') FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.user_id=u.id AND ra.actif=1$rs AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())) roles,
        $etabs etablissements,
        (SELECT r.code FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.user_id=u.id AND ra.actif=1$rs ORDER BY ra.principal DESC,ra.id LIMIT 1) principal_role_code
        FROM users u WHERE ".implode(' AND ',$where)." ORDER BY u.created_at DESC,u.id DESC";

    $stmt=$pdo->prepare($sql);$stmt->execute($params);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    if($super){
        $roles=$pdo->query("SELECT id,code,nom FROM roles WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
        $etablissements=$pdo->query("SELECT id,code,nom,type_etablissement FROM etablissements WHERE statut IN('VALIDE','ACTIF') ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    }else{
        $codes=$role==='ADMIN_ACCUEIL'?['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','POINTEUR','GESTIONNAIRE_FINANCIER_HOSPITALIER']:['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'];
        $ph=implode(',',array_fill(0,count($codes),'?'));$stmt=$pdo->prepare("SELECT id,code,nom FROM roles WHERE actif=1 AND ((systeme=1 AND code IN($ph)) OR (systeme=0 AND etablissement_id=?)) ORDER BY systeme DESC,nom");$stmt->execute([...$codes,$eid]);$roles=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt=$pdo->prepare("SELECT id,code,nom,type_etablissement FROM etablissements WHERE id=? LIMIT 1");$stmt->execute([$eid]);$etablissements=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $statsSql="SELECT COUNT(*) total,COALESCE(SUM(u.statut_compte='ACTIF'),0) actifs,COALESCE(SUM(u.statut_compte='A_ACTIVER'),0) a_activer,COALESCE(SUM(u.statut_compte='SUSPENDU'),0) suspendus FROM users u";
    $statsParams=[];
    if($eid){$statsSql.=" WHERE EXISTS(SELECT 1 FROM etablissement_users eu WHERE eu.user_id=u.id AND eu.etablissement_id=?)";$statsParams[]=$eid;}
    $stmt=$pdo->prepare($statsSql);$stmt->execute($statsParams);$stats=$stmt->fetch(PDO::FETCH_ASSOC);

    jsonResponse(true,'',['items'=>$items,'roles'=>$roles,'etablissements'=>$etablissements,'stats'=>[
        'total'=>(int)($stats['total']??0),'actifs'=>(int)($stats['actifs']??0),
        'a_activer'=>(int)($stats['a_activer']??0),'suspendus'=>(int)($stats['suspendus']??0)
    ]]);
}catch(Throwable $e){
    error_log('[USERS LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

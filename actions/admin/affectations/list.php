<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$adminContexte=exigerAdministrationRolesAjax($pdo);
if(function_exists('contextPermission')&&!contextPermission('user.role.assign'))jsonResponse(false,'Permission insuffisante.',[],403);

$actor=$_SESSION['role_code']??'';$super=$adminContexte['super'];$eid=$super?0:(int)$adminContexte['etablissement_id'];
$allowed=$actor==='ADMIN_ACCUEIL'?['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','POINTEUR','CHEF_SERVICE','EVALUATEUR_CLINIQUE','GESTIONNAIRE_FINANCIER_HOSPITALIER']:['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'];
if(!$super&&!$eid)jsonResponse(false,'Aucun établissement associé à votre compte.',[],403);

try{
    $q=trim($_GET['q']??'');$roleId=(int)($_GET['role_id']??0);$scope=trim($_GET['scope_type']??'');$status=trim($_GET['status']??'');
    $where=['1=1'];$params=[];

    if(!$super){
        $where[]='ra.etablissement_id=?';$params[]=$eid;
        if($actor==='ADMIN_ACCUEIL'){
            $where[]="(((r.code IN('ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','GESTIONNAIRE_FINANCIER_HOSPITALIER') OR (r.systeme=0 AND r.etablissement_id=".(int)$eid.")) AND ra.scope_type='ORGANIZATION') OR (r.code='POINTEUR' AND (ra.scope_type='ORGANIZATION' OR (ra.scope_type='UNIT' AND ra.scope_entity='HOST_UNIT'))) OR (r.code IN('CHEF_SERVICE','EVALUATEUR_CLINIQUE') AND ra.scope_type='UNIT' AND ra.scope_entity='HOST_UNIT'))";
        }else $where[]="ra.scope_type='ORGANIZATION' AND (r.code IN('ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE') OR (r.systeme=0 AND r.etablissement_id=".(int)$eid."))";
    }
    if($q!==''){$where[]="(u.nom LIKE ? OR u.postnom LIKE ? OR u.prenom LIKE ? OR u.email LIKE ? OR u.identifiant LIKE ? OR r.nom LIKE ?)";$like="%$q%";array_push($params,$like,$like,$like,$like,$like,$like);}
    if($roleId){$where[]='ra.role_id=?';$params[]=$roleId;}
    if(in_array($scope,['PLATFORM','ORGANIZATION','UNIT','CAMPAIGN','INTERNSHIP','SELF'],true)&&($super||$actor==='ADMIN_ACCUEIL')){$where[]='ra.scope_type=?';$params[]=$scope;}
    if($status==='ACTIVE')$where[]="ra.actif=1 AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())";
    elseif($status==='PLANNED')$where[]="ra.actif=1 AND ra.starts_at>NOW()";
    elseif($status==='EXPIRED')$where[]="ra.actif=1 AND ra.ends_at IS NOT NULL AND ra.ends_at<NOW()";
    elseif($status==='REVOKED')$where[]='ra.actif=0';

    $sql="SELECT ra.id,ra.user_id,ra.role_id,ra.scope_type,ra.scope_entity,ra.scope_id,ra.etablissement_id,ra.principal,ra.actif,ra.starts_at,ra.ends_at,ra.revoked_at,ra.revocation_reason,ra.created_at,
        u.nom,u.postnom,u.prenom,u.email,u.identifiant,r.code role_code,r.nom role_nom,e.nom etablissement_nom
        FROM role_assignments ra JOIN users u ON u.id=ra.user_id JOIN roles r ON r.id=ra.role_id LEFT JOIN etablissements e ON e.id=ra.etablissement_id
        WHERE ".implode(' AND ',$where)." ORDER BY ra.actif DESC,ra.principal DESC,ra.created_at DESC,ra.id DESC";
    $s=$pdo->prepare($sql);$s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$a){
        $sid=(int)($a['scope_id']??0);$entity=$a['scope_entity']??'';$a['context_label']='';
        if($a['scope_type']==='PLATFORM')$a['context_label']='Toute la plateforme';
        elseif($a['scope_type']==='SELF')$a['context_label']='Données personnelles';
        elseif($a['scope_type']==='ORGANIZATION')$a['context_label']=$a['etablissement_nom']?:('Établissement #'.$sid);
        elseif($a['scope_type']==='UNIT'&&$entity==='HOST_UNIT'&&$sid){
            $x=$pdo->prepare("SELECT nom FROM host_units WHERE id=? AND host_etablissement_id=? LIMIT 1");$x->execute([$sid,$a['etablissement_id']]);
            $a['context_label']=$x->fetchColumn()?:('Service / unité #'.$sid);
        }else $a['context_label']=($entity?:$a['scope_type']).($sid?' #'.$sid:'');

        if(!(int)$a['actif'])$a['effective_status']='REVOKED';
        elseif($a['starts_at']&&strtotime($a['starts_at'])>time())$a['effective_status']='PLANNED';
        elseif($a['ends_at']&&strtotime($a['ends_at'])<time())$a['effective_status']='EXPIRED';
        else $a['effective_status']='ACTIVE';
    }unset($a);

    if($super){
        $roles=$pdo->query("SELECT id,code,nom FROM roles WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
        $users=$pdo->query("SELECT u.id,u.nom,u.postnom,u.prenom,u.email,u.identifiant,
            EXISTS(
                SELECT 1 FROM role_assignments ura
                JOIN roles ur ON ur.id=ura.role_id AND ur.actif=1
                WHERE ura.user_id=u.id AND ura.actif=1
                  AND (ura.starts_at IS NULL OR ura.starts_at<=NOW())
                  AND (ura.ends_at IS NULL OR ura.ends_at>=NOW())
            ) has_active_role
            FROM users u
            WHERE u.statut_compte<>'SUSPENDU'
            ORDER BY has_active_role,u.nom,u.postnom,u.prenom")->fetchAll(PDO::FETCH_ASSOC);
        $etabs=$pdo->query("SELECT id,code,nom,type_etablissement FROM etablissements WHERE statut IN('VALIDE','ACTIF') ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
        $stats=$pdo->query("SELECT COUNT(*) total,SUM(actif=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW())) actives,SUM(actif=1 AND starts_at>NOW()) planned,SUM(actif=0) revoked FROM role_assignments")->fetch(PDO::FETCH_ASSOC);
    }else{
        $ph=implode(',',array_fill(0,count($allowed),'?'));
        $s=$pdo->prepare("SELECT id,code,nom FROM roles WHERE actif=1 AND ((systeme=1 AND code IN($ph)) OR (systeme=0 AND etablissement_id=?)) ORDER BY systeme DESC,nom");$s->execute([...$allowed,$eid]);$roles=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$pdo->prepare("SELECT DISTINCT u.id,u.nom,u.postnom,u.prenom,u.email,u.identifiant,
            EXISTS(
                SELECT 1 FROM role_assignments ura
                JOIN roles ur ON ur.id=ura.role_id AND ur.actif=1
                WHERE ura.user_id=u.id AND ura.actif=1
                  AND (ura.starts_at IS NULL OR ura.starts_at<=NOW())
                  AND (ura.ends_at IS NULL OR ura.ends_at>=NOW())
            ) has_active_role
            FROM users u
            JOIN etablissement_users eu ON eu.user_id=u.id
            WHERE eu.etablissement_id=? AND u.statut_compte<>'SUSPENDU'
            ORDER BY has_active_role,u.nom,u.postnom,u.prenom");$s->execute([$eid]);$users=$s->fetchAll(PDO::FETCH_ASSOC);
        $s=$pdo->prepare("SELECT id,code,nom,type_etablissement FROM etablissements WHERE id=? LIMIT 1");$s->execute([$eid]);$etabs=$s->fetchAll(PDO::FETCH_ASSOC);
        $statScope=$actor==='ADMIN_ACCUEIL'
            ?"(((r.code IN('ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','GESTIONNAIRE_FINANCIER_HOSPITALIER') OR (r.systeme=0 AND r.etablissement_id=".(int)$eid.")) AND ra.scope_type='ORGANIZATION') OR (r.code='POINTEUR' AND (ra.scope_type='ORGANIZATION' OR (ra.scope_type='UNIT' AND ra.scope_entity='HOST_UNIT'))) OR (r.code IN('CHEF_SERVICE','EVALUATEUR_CLINIQUE') AND ra.scope_type='UNIT' AND ra.scope_entity='HOST_UNIT'))"
            :"ra.scope_type='ORGANIZATION' AND (r.code IN('ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE') OR (r.systeme=0 AND r.etablissement_id=".(int)$eid."))";
        $s=$pdo->prepare("SELECT COUNT(*) total,SUM(ra.actif=1 AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())) actives,SUM(ra.actif=1 AND ra.starts_at>NOW()) planned,SUM(ra.actif=0) revoked FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.etablissement_id=? AND $statScope");$s->execute([$eid]);$stats=$s->fetch(PDO::FETCH_ASSOC);
    }

    $unassignedUsers=array_values(array_filter(
        $users,
        static fn(array $user):bool=>(int)($user['has_active_role']??0)===0
    ));
    $stats=array_map('intval',$stats);
    $stats['sans_role']=count($unassignedUsers);

    jsonResponse(true,'',['items'=>$items,'roles'=>$roles,'users'=>$users,'unassigned_users'=>$unassignedUsers,'etablissements'=>$etabs,'stats'=>$stats]);
}catch(Throwable $e){
    error_log('[ROLE ASSIGNMENTS LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

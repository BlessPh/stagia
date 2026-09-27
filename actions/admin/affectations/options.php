<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$adminContexte=exigerAdministrationRolesAjax($pdo);
if(function_exists('contextPermission')&&!contextPermission('user.role.assign'))jsonResponse(false,'Permission insuffisante.',[],403);

$actor=$_SESSION['role_code']??'';$super=$adminContexte['super'];
$scope=trim($_GET['scope_type']??'');$entity=trim($_GET['scope_entity']??'');
$eid=$super?(int)($_GET['etablissement_id']??0):(int)$adminContexte['etablissement_id'];$items=[];

try{
    if(!$super){
        if($actor!=='ADMIN_ACCUEIL'||!$eid||$scope!=='UNIT'||$entity!=='HOST_UNIT')jsonResponse(true,'',['items'=>[]]);
        $s=$pdo->prepare("SELECT id,nom label FROM host_units WHERE host_etablissement_id=? AND actif=1 ORDER BY nom");$s->execute([$eid]);
        jsonResponse(true,'',['items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if($scope==='UNIT'&&$eid){
        $map=['ACADEMIC_UNIT'=>['facultes','etablissement_id'],'DEPARTMENT'=>['departements','etablissement_id'],'PROGRAM'=>['filieres','etablissement_id'],'HOST_UNIT'=>['host_units','host_etablissement_id']];
        if(isset($map[$entity])){[$t,$c]=$map[$entity];$s=$pdo->prepare("SELECT id,nom label FROM $t WHERE $c=? AND actif=1 ORDER BY nom");$s->execute([$eid]);$items=$s->fetchAll(PDO::FETCH_ASSOC);}
    }elseif($scope==='CAMPAIGN'){
        $sql="SELECT id,titre label FROM stage_campaigns";$p=[];if($eid){$sql.=" WHERE owner_etablissement_id=?";$p[]=$eid;}$sql.=" ORDER BY id DESC";$s=$pdo->prepare($sql);$s->execute($p);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    }elseif($scope==='INTERNSHIP'){
        $sql="SELECT sa.id,CONCAT(COALESCE(hu.nom,'Unité'),' · ',sa.uuid) label FROM stage_assignments sa LEFT JOIN host_units hu ON hu.id=sa.host_unit_id";$p=[];if($eid){$sql.=" WHERE sa.host_etablissement_id=?";$p[]=$eid;}$sql.=" ORDER BY sa.id DESC";$s=$pdo->prepare($sql);$s->execute($p);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    }
    jsonResponse(true,'',['items'=>$items]);
}catch(Throwable $e){error_log('[ADMIN AFFECTATIONS OPTIONS] '.$e->getMessage());jsonResponse(false,'Impossible de charger les options.',[],500);}

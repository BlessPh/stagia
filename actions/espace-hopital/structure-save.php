<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/host-structure.php';

requirePermission($pdo,'host.manage');
verifyAjaxCsrf();
if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

$eid=(int)($_SESSION['etablissement_id']??0);$id=(int)($_POST['id']??0);
$type=strtoupper(trim((string)($_POST['type']??'')));$nom=trim((string)($_POST['nom']??''));
$description=trim((string)($_POST['description']??''));$capRaw=trim((string)($_POST['capacite']??''));
$hasParent=(int)($_POST['has_parent']??0)===1;$parentId=(int)($_POST['parent_id']??0);
if(!$eid||!in_array($type,['DEPARTEMENT','SERVICE','UNITE'],true))jsonResponse(false,'Type de structure invalide.',[],422);
if($nom==='')jsonResponse(false,'Le nom est obligatoire.',[],422);

$cfg=hostStructureConfig($pdo,$eid);
if(!hostStructureEnabled($cfg,$type))jsonResponse(false,'Ce niveau a été retiré de la structure active.',[],422);
if($type==='DEPARTEMENT'){$hasParent=false;$parentId=0;}
$allowedParents=hostStructureAllowedParentTypes($type,$cfg);
if($hasParent&&!$parentId)jsonResponse(false,'Choisissez le parent.',[],422);
if($hasParent&&!$allowedParents)jsonResponse(false,'Aucun niveau parent actif n’est disponible.',[],422);
if(!$hasParent)$parentId=0;

$capacite=null;
if($type!=='DEPARTEMENT'&&$capRaw!==''){$capacite=(int)$capRaw;if($capacite<0)jsonResponse(false,'La capacité ne peut pas être négative.',[],422);}

try{
    $pdo->beginTransaction();
    if($id){
        $s=$pdo->prepare("SELECT * FROM host_units WHERE id=? AND host_etablissement_id=? LIMIT 1 FOR UPDATE");
        $s->execute([$id,$eid]);$current=$s->fetch(PDO::FETCH_ASSOC);
        if(!$current)throw new RuntimeException('Structure introuvable.');
        $type=strtoupper((string)$current['type']);
        $allowedParents=hostStructureAllowedParentTypes($type,$cfg);
    }

    if($hasParent){
        $ph=implode(',',array_fill(0,count($allowedParents),'?'));
        $params=array_merge([$parentId,$eid],$allowedParents);
        $s=$pdo->prepare("SELECT id,type FROM host_units WHERE id=? AND host_etablissement_id=? AND actif=1 AND type IN($ph) LIMIT 1 FOR UPDATE");
        $s->execute($params);$parent=$s->fetch(PDO::FETCH_ASSOC);
        if(!$parent)throw new RuntimeException('Parent invalide ou inactif.');
        if($id&&$parentId===$id)throw new RuntimeException('Une structure ne peut pas être son propre parent.');
        if($id){$s=$pdo->prepare("SELECT COUNT(*) FROM host_units WHERE id=? AND parent_id=? AND host_etablissement_id=?");$s->execute([$parentId,$id,$eid]);if((int)$s->fetchColumn()>0)throw new RuntimeException('Ce rattachement créerait une boucle.');}
    }

    if($id){
        $s=$pdo->prepare("UPDATE host_units SET parent_id=?,nom=?,description=?,capacite=?,updated_at=NOW() WHERE id=? AND host_etablissement_id=?");
        $s->execute([$parentId?:null,$nom,$description!==''?$description:null,$capacite,$id,$eid]);
        $pdo->commit();jsonResponse(true,'Structure modifiée.',['id'=>$id]);
    }

    $prefix=match($type){'DEPARTEMENT'=>'DEP','SERVICE'=>'SRV','UNITE'=>'UNI',default=>'STR'};
    do{$code=$prefix.'-LOC-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$s=$pdo->prepare("SELECT COUNT(*) FROM host_units WHERE host_etablissement_id=? AND code=?");$s->execute([$eid,$code]);}while((int)$s->fetchColumn()>0);
    $s=$pdo->prepare("INSERT INTO host_units(host_etablissement_id,parent_id,code,nom,type,description,capacite,actif,created_at,updated_at) VALUES(?,?,?,?,?,?,?,1,NOW(),NOW())");
    $s->execute([$eid,$parentId?:null,$code,$nom,$type,$description!==''?$description:null,$capacite]);
    $newId=(int)$pdo->lastInsertId();$pdo->commit();jsonResponse(true,'Structure ajoutée.',['id'=>$newId,'code'=>$code]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

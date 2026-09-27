<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$libelle=trim($_POST['libelle']??''); $code=strtoupper(trim($_POST['code']??''));
$description=trim($_POST['description']??''); $categorie=$_POST['categorie']??'AUTRE';
$categories=['FORMATION','ACCUEIL','MIXTE','AUTRE'];
$bool=fn($k)=>(int)(($_POST[$k]??'0')==='1');

if($libelle==='') jsonResponse(false,'Le libellé est obligatoire.',[],422);
if($code===''){
    $raw=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$libelle)?:$libelle;
    $code=strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/','_',$raw),'_'));
}
$code=substr($code,0,50);
if(!preg_match('/^[A-Z0-9_]+$/',$code)) jsonResponse(false,'Code invalide : utilisez A-Z, 0-9 et _.',[],422);
if(!in_array($categorie,$categories,true)) jsonResponse(false,'Catégorie invalide.',[],422);

try{
    $s=$pdo->prepare("SELECT id FROM establishment_types WHERE code=? OR libelle=? LIMIT 1");
    $s->execute([$code,$libelle]); if($s->fetchColumn()) jsonResponse(false,'Ce type existe déjà.',[],409);

    $pdo->prepare("INSERT INTO establishment_types
        (code,libelle,description,categorie,academic_enabled,host_enabled,adhesion_enabled,allow_parent,ordre,systeme,actif,created_by_user_id)
        VALUES(?,?,?,?,?,?,?,?,?,0,1,?)")
        ->execute([$code,$libelle,$description?:null,$categorie,$bool('academic_enabled'),$bool('host_enabled'),
            $bool('adhesion_enabled'),$bool('allow_parent'),(int)($_POST['ordre']??0),$_SESSION['user_id']??null]);

    jsonResponse(true,"Type $code ajouté.",['id'=>(int)$pdo->lastInsertId(),'code'=>$code]);
}catch(Throwable $e){ jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500); }

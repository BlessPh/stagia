<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$libelle=trim($_POST['libelle']??''); $code=strtoupper(trim($_POST['code']??''));
$prefixe=strtoupper(trim($_POST['prefixe_code']??'')); $description=trim($_POST['description']??'');
$ordre=max(0,(int)($_POST['ordre']??0));

if($libelle==='') jsonResponse(false,'Le libellé est obligatoire.',[],422);

if($code===''){
    $raw=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$libelle)?:$libelle;
    $code=strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/','_',$raw),'_'));
}
$code=substr($code,0,50);
if(!preg_match('/^[A-Z0-9_]+$/',$code)) jsonResponse(false,'Code invalide : utilisez A-Z, 0-9 et _.',[],422);

if($prefixe==='') $prefixe=substr(str_replace('_','',$code),0,3);
$prefixe=substr(preg_replace('/[^A-Z0-9]/','',$prefixe),0,8);
if(strlen($prefixe)<2) jsonResponse(false,'Le préfixe doit contenir au moins 2 caractères.',[],422);

try{
    $s=$pdo->prepare("SELECT id FROM academic_unit_types WHERE code=? OR libelle=? LIMIT 1");
    $s->execute([$code,$libelle]);
    if($s->fetchColumn()) jsonResponse(false,"Ce type d'unité existe déjà.",[],409);

    $pdo->prepare("INSERT INTO academic_unit_types
        (code,libelle,description,prefixe_code,ordre,systeme,actif,created_by_user_id)
        VALUES(?,?,?,?,?,0,1,?)")
        ->execute([$code,$libelle,$description?:null,$prefixe,$ordre,$_SESSION['user_id']??null]);

    jsonResponse(true,"Type d'unité $code ajouté.",['id'=>(int)$pdo->lastInsertId(),'code'=>$code]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

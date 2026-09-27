<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$id=(int)($_POST['id']??0); $libelle=trim($_POST['libelle']??'');
$prefixe=strtoupper(trim($_POST['prefixe_code']??'')); $description=trim($_POST['description']??'');
$ordre=max(0,(int)($_POST['ordre']??0));

if(!$id||$libelle==='') jsonResponse(false,'Données invalides.',[],422);
$prefixe=substr(preg_replace('/[^A-Z0-9]/','',$prefixe),0,8);
if(strlen($prefixe)<2) jsonResponse(false,'Le préfixe doit contenir au moins 2 caractères.',[],422);

try{
    $s=$pdo->prepare("SELECT code FROM academic_unit_types WHERE id=? LIMIT 1"); $s->execute([$id]); $code=$s->fetchColumn();
    if(!$code) jsonResponse(false,"Type d'unité introuvable.",[],404);

    $s=$pdo->prepare("SELECT id FROM academic_unit_types WHERE libelle=? AND id<>? LIMIT 1"); $s->execute([$libelle,$id]);
    if($s->fetchColumn()) jsonResponse(false,'Ce libellé est déjà utilisé.',[],409);

    $pdo->prepare("UPDATE academic_unit_types SET libelle=?,description=?,prefixe_code=?,ordre=? WHERE id=?")
        ->execute([$libelle,$description?:null,$prefixe,$ordre,$id]);

    jsonResponse(true,"Type d'unité $code mis à jour.");
}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

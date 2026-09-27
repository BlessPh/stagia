<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$id=(int)($_POST['id']??0); $libelle=trim($_POST['libelle']??''); $description=trim($_POST['description']??'');
$categorie=$_POST['categorie']??'AUTRE'; $categories=['FORMATION','ACCUEIL','MIXTE','AUTRE'];
$bool=fn($k)=>(int)(($_POST[$k]??'0')==='1');

if(!$id||$libelle==='') jsonResponse(false,'Données invalides.',[],422);
if(!in_array($categorie,$categories,true)) jsonResponse(false,'Catégorie invalide.',[],422);

try{
    $s=$pdo->prepare("SELECT code FROM establishment_types WHERE id=? LIMIT 1"); $s->execute([$id]); $code=$s->fetchColumn();
    if(!$code) jsonResponse(false,'Type introuvable.',[],404);
    $s=$pdo->prepare("SELECT id FROM establishment_types WHERE libelle=? AND id<>? LIMIT 1"); $s->execute([$libelle,$id]);
    if($s->fetchColumn()) jsonResponse(false,'Ce libellé est déjà utilisé.',[],409);

    $pdo->prepare("UPDATE establishment_types SET libelle=?,description=?,categorie=?,academic_enabled=?,host_enabled=?,
        adhesion_enabled=?,allow_parent=?,ordre=? WHERE id=?")
        ->execute([$libelle,$description?:null,$categorie,$bool('academic_enabled'),$bool('host_enabled'),
            $bool('adhesion_enabled'),$bool('allow_parent'),(int)($_POST['ordre']??0),$id]);

    jsonResponse(true,"Type $code mis à jour.");
}catch(Throwable $e){ jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500); }

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-structure.php';
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);verifyAjaxCsrf();
try{
    $eid=currentEtablissementId($pdo);$nom=trim((string)($_POST['nom']??''));$type=strtoupper(trim((string)($_POST['type_unite']??'')));
    $parentId=(int)($_POST['parent_id']??0)?:null;$motif=trim((string)($_POST['motif_ajout']??''));$cfg=$eid?academicSettings($pdo,$eid):[];
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);
    if(!$cfg || !(int)$cfg['unite_academique_active'])jsonResponse(false,"Le module Unités académiques n'est pas activé.",[],403);
    if($nom===''||$type==='')jsonResponse(false,'Le type et le nom sont obligatoires.',[],422);
    if($motif==='')jsonResponse(false,"Expliquez pourquoi cette unité manque au référentiel STAGIA.",[],422);
    if(!academicUnitTypeAllowed($pdo,$eid,$type))jsonResponse(false,"Ce type d'unité n'est pas autorisé.",[],422);
    if($parentId){$s=$pdo->prepare("SELECT id FROM facultes WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");$s->execute([$parentId,$eid]);if(!$s->fetchColumn())jsonResponse(false,'Unité parente invalide ou non validée.',[],422);}
    $s=$pdo->prepare("SELECT id FROM facultes WHERE etablissement_id=? AND LOWER(TRIM(nom))=LOWER(TRIM(?)) AND validation_statut<>'REFUSE' LIMIT 1");$s->execute([$eid,$nom]);if($s->fetchColumn())jsonResponse(false,'Cette unité existe déjà.',[],409);
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO facultes(etablissement_id,type_unite,parent_id,source_template_unit_id,ajoute_localement,validation_statut,code,nom,motif_ajout,created_by_user_id,actif) VALUES(?,?,?,NULL,1,'EN_ATTENTE',NULL,?,?,?,0)")
        ->execute([$eid,$type,$parentId,$nom,$motif,(int)($_SESSION['user_id']??0)]);
    $id=(int)$pdo->lastInsertId();$code=academicUnitCode($pdo,$id,$type);$pdo->prepare("UPDATE facultes SET code=? WHERE id=?")->execute([$code,$id]);$pdo->commit();
    jsonResponse(true,"Unité $code proposée localement. Elle reste inactive jusqu'à validation du Super Admin.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e instanceof RuntimeException?$e->getMessage():'Erreur serveur : '.$e->getMessage(),[],500);}

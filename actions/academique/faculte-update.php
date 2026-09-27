<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-structure.php';
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);verifyAjaxCsrf();
try{
    $eid=currentEtablissementId($pdo);$id=(int)($_POST['id']??0);$nom=trim((string)($_POST['nom']??''));$type=strtoupper(trim((string)($_POST['type_unite']??'')));$parentId=(int)($_POST['parent_id']??0)?:null;$motif=trim((string)($_POST['motif_ajout']??''));
    if(!$eid||!$id||$nom===''||$type==='')jsonResponse(false,'Données obligatoires manquantes.',[],422);
    $s=$pdo->prepare("SELECT ajoute_localement,validation_statut FROM facultes WHERE id=? AND etablissement_id=? LIMIT 1");$s->execute([$id,$eid]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)jsonResponse(false,'Unité introuvable.',[],404);if(!(int)$row['ajoute_localement'])jsonResponse(false,'Une unité nationale ne peut pas être modifiée localement.',[],403);
    if(!in_array($row['validation_statut'],['EN_ATTENTE','VALIDE_LOCAL'],true))jsonResponse(false,"Cette unité n'est plus modifiable localement.",[],409);
    if($parentId){if($parentId===$id)jsonResponse(false,'Une unité ne peut pas être sa propre parente.',[],422);$s=$pdo->prepare("SELECT id FROM facultes WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");$s->execute([$parentId,$eid]);if(!$s->fetchColumn())jsonResponse(false,'Unité parente invalide.',[],422);}
    $pdo->prepare("UPDATE facultes SET type_unite=?,parent_id=?,nom=?,motif_ajout=?,validation_statut='EN_ATTENTE',actif=0,reviewed_by_user_id=NULL,reviewed_at=NULL,review_comment=NULL WHERE id=? AND etablissement_id=?")
        ->execute([$type,$parentId,$nom,$motif?:null,$id,$eid]);
    jsonResponse(true,'Ajout local mis à jour et renvoyé en validation.');
}catch(Throwable $e){jsonResponse(false,$e instanceof RuntimeException?$e->getMessage():'Erreur serveur : '.$e->getMessage(),[],500);}

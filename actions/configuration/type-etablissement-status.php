<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();
$id=(int)($_POST['id']??0);

$s=$pdo->prepare("SELECT code,actif FROM establishment_types WHERE id=? LIMIT 1"); $s->execute([$id]); $t=$s->fetch(PDO::FETCH_ASSOC);
if(!$t) jsonResponse(false,'Type introuvable.',[],404);

$actif=(int)$t['actif']?0:1;
if(!$actif){
    $s=$pdo->prepare("SELECT COUNT(*) FROM etablissements WHERE type_etablissement=? AND statut IN('EN_ATTENTE','VALIDE','ACTIF')");
    $s->execute([$t['code']]);
    if((int)$s->fetchColumn()>0) jsonResponse(false,"Impossible de désactiver ce type : des établissements actifs l'utilisent.",[],409);
}
$pdo->prepare("UPDATE establishment_types SET actif=? WHERE id=?")->execute([$actif,$id]);
jsonResponse(true,$actif?'Type activé.':'Type désactivé.');

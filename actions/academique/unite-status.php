<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0); $id=(int)($_POST['id']??0);
if(!$eid||!$id) jsonResponse(false,'Données invalides.',[],422);

$s=$pdo->prepare("SELECT actif FROM facultes WHERE id=? AND etablissement_id=? LIMIT 1");
$s->execute([$id,$eid]); $u=$s->fetch(PDO::FETCH_ASSOC);
if(!$u) jsonResponse(false,'Unité introuvable.',[],404);

$actif=(int)$u['actif']?0:1;
if(!$actif){
    $s=$pdo->prepare("SELECT
        (SELECT COUNT(*) FROM facultes WHERE parent_id=? AND etablissement_id=? AND actif=1)+
        (SELECT COUNT(*) FROM departements WHERE faculte_id=? AND etablissement_id=? AND actif=1)+
        (SELECT COUNT(*) FROM filieres WHERE faculte_id=? AND etablissement_id=? AND actif=1)");
    $s->execute([$id,$eid,$id,$eid,$id,$eid]);
    if((int)$s->fetchColumn()>0)
        jsonResponse(false,'Impossible de désactiver cette unité : elle contient encore des éléments actifs.',[],409);
}

$pdo->prepare("UPDATE facultes SET actif=? WHERE id=? AND etablissement_id=?")->execute([$actif,$id,$eid]);
jsonResponse(true,$actif?'Unité activée.':'Unité désactivée.');

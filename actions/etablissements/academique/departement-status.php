<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$id=(int)($_POST['id']??0);

if(!$eid||!$id) jsonResponse(false,'Données invalides.',[],422);

$s=$pdo->prepare("SELECT actif FROM departements WHERE id=? AND etablissement_id=? LIMIT 1");
$s->execute([$id,$eid]); $d=$s->fetch(PDO::FETCH_ASSOC);

if(!$d) jsonResponse(false,'Département introuvable.',[],404);

$actif=(int)$d['actif']?0:1;

if(!$actif){
    $s=$pdo->prepare("SELECT COUNT(*) FROM filieres
        WHERE departement_id=? AND etablissement_id=? AND actif=1");
    $s->execute([$id,$eid]);

    if((int)$s->fetchColumn()>0)
        jsonResponse(false,'Impossible de désactiver ce département : des filières actives y sont rattachées.',[],409);
}

$pdo->prepare("UPDATE departements SET actif=? WHERE id=? AND etablissement_id=?")
    ->execute([$actif,$id,$eid]);

jsonResponse(true,$actif?'Département activé.':'Département désactivé.');

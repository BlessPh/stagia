<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'host.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$capacite=(int)($_POST['capacite']??0)?:null;

if(!$eid||!$id)jsonResponse(false,'Structure invalide.',[],422);
if($capacite!==null && $capacite<1)
    jsonResponse(false,'La capacité doit être supérieure à zéro.',[],422);

$s=$pdo->prepare("
    SELECT validation_statut
    FROM host_units
    WHERE id=? AND host_etablissement_id=?
    LIMIT 1
");
$s->execute([$id,$eid]);
$status=$s->fetchColumn();

if(!$status)jsonResponse(false,'Structure introuvable.',[],404);
if(!in_array($status,['NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL'],true))
    jsonResponse(false,'La capacité ne peut être ajustée qu’après validation de la structure.',[],409);

$pdo->prepare("
    UPDATE host_units
    SET capacite=?
    WHERE id=? AND host_etablissement_id=?
")->execute([$capacite,$id,$eid]);

jsonResponse(true,'Capacité locale mise à jour.');

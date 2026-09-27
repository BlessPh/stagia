<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

verifyAjaxCsrf();

$etablissementId=currentEtablissementId($pdo);
$id=(int)($_POST['id']??0);

$stmt=$pdo->prepare("
    SELECT actif
    FROM departements
    WHERE id=? AND etablissement_id=?
");
$stmt->execute([$id,$etablissementId]);

$d=$stmt->fetch();

if(!$d)
    jsonResponse(false,'Département introuvable.',[],404);

$actif=$d['actif']?0:1;

$pdo->prepare("
    UPDATE departements
    SET actif=?
    WHERE id=? AND etablissement_id=?
")->execute([$actif,$id,$etablissementId]);

jsonResponse(
    true,
    $actif
        ? 'Département activé.'
        : 'Département désactivé.'
);
<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

$etablissementId=currentEtablissementId($pdo);
$id=(int)($_POST['id']??0);
$actif=(int)($_POST['actif']??-1);

if(!$id || !in_array($actif,[0,1],true))
    jsonResponse(false,'Données invalides.',[],422);

$stmt=$pdo->prepare("
    UPDATE matieres
    SET actif=?
    WHERE id=? AND etablissement_id=?
");

$stmt->execute([$actif,$id,$etablissementId]);

jsonResponse(true,$actif
    ?'Matière activée.'
    :'Matière désactivée.'
);
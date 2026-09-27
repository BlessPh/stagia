<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$description=trim((string)($_POST['description']??''));

if(!$eid||!$id)jsonResponse(false,'Promotion invalide.',[],422);

$s=$pdo->prepare("
    SELECT id
    FROM promotions
    WHERE id=? AND etablissement_id=?
    LIMIT 1
");
$s->execute([$id,$eid]);

if(!$s->fetchColumn())jsonResponse(false,'Promotion introuvable.',[],404);

$pdo->prepare("
    UPDATE promotions
    SET description=?
    WHERE id=? AND etablissement_id=?
")->execute([$description?:null,$id,$eid]);

jsonResponse(true,'Description de la promotion mise à jour.');

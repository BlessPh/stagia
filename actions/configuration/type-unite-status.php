<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
$s=$pdo->prepare("SELECT code,actif FROM academic_unit_types WHERE id=? LIMIT 1"); $s->execute([$id]); $t=$s->fetch(PDO::FETCH_ASSOC);
if(!$t) jsonResponse(false,"Type d'unité introuvable.",[],404);

$actif=(int)$t['actif']?0:1;
$pdo->prepare("UPDATE academic_unit_types SET actif=? WHERE id=?")->execute([$actif,$id]);

jsonResponse(
    true,
    $actif
        ?"Type d'unité activé."
        :"Type d'unité désactivé pour les nouvelles configurations. Les données existantes sont conservées."
);

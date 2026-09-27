<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$type=strtoupper(trim((string)($_GET['type_code']??'')));

$types=$pdo->query("
    SELECT code,libelle
    FROM establishment_types
    WHERE host_enabled=1
      AND actif=1
    ORDER BY ordre,libelle
")->fetchAll(PDO::FETCH_ASSOC);

$items=[];

if($type!==''){
    $s=$pdo->prepare("
        SELECT
            h.*,
            p.nom parent_nom
        FROM host_unit_templates h
        LEFT JOIN host_unit_templates p
          ON p.id=h.parent_id
        WHERE h.establishment_type_code=?
          AND h.actif=1
        ORDER BY h.ordre,h.nom
    ");
    $s->execute([$type]);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);
}

jsonResponse(true,'',['types'=>$types,'items'=>$items]);

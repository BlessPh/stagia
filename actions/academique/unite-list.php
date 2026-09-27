<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$eid=(int)($_GET['etablissement_id']??0);
if(!$eid) jsonResponse(false,'Établissement invalide.',[],422);

$s=$pdo->prepare("SELECT e.id,e.nom,e.code,e.type_etablissement,s.configuration_statut,s.unite_parentale_autorisee
    FROM etablissements e
    JOIN etablissement_academic_settings s ON s.etablissement_id=e.id
    WHERE e.id=? LIMIT 1");
$s->execute([$eid]); $etab=$s->fetch(PDO::FETCH_ASSOC);
if(!$etab) jsonResponse(false,'Configuration académique introuvable.',[],404);

$s=$pdo->prepare("SELECT type_unite,COALESCE(NULLIF(libelle,''),type_unite) libelle,ordre
    FROM etablissement_academic_unit_types
    WHERE etablissement_id=? AND actif=1 ORDER BY ordre,libelle");
$s->execute([$eid]); $types=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT f.id,f.code,f.nom,f.type_unite,f.parent_id,f.actif,p.nom parent_nom,
    (SELECT COUNT(*) FROM departements d WHERE d.faculte_id=f.id AND d.etablissement_id=f.etablissement_id) nb_departements,
    (SELECT COUNT(*) FROM facultes c WHERE c.parent_id=f.id AND c.etablissement_id=f.etablissement_id) nb_enfants
    FROM facultes f
    LEFT JOIN facultes p ON p.id=f.parent_id
    WHERE f.etablissement_id=?
    ORDER BY f.type_unite,f.nom");
$s->execute([$eid]); $items=$s->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true,'',[
    'etablissement'=>$etab,
    'types'=>$types,
    'items'=>$items,
    'stats'=>[
        'total'=>count($items),
        'actifs'=>count(array_filter($items,fn($x)=>(int)$x['actif']===1))
    ]
]);

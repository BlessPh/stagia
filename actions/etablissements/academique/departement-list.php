<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$eid=(int)($_GET['etablissement_id']??0);
if(!$eid) jsonResponse(false,'Établissement invalide.',[],422);

$s=$pdo->prepare("SELECT departement_active,departement_obligatoire,unite_academique_active
    FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1");
$s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);
if(!$cfg) jsonResponse(false,'Configuration académique introuvable.',[],404);

if(!(int)$cfg['departement_active'])
    jsonResponse(true,'',['enabled'=>false,'items'=>[],'units'=>[],'stats'=>['total'=>0,'actifs'=>0,'filieres'=>0]]);

$s=$pdo->prepare("SELECT id,code,nom,type_unite,actif
    FROM facultes WHERE etablissement_id=? ORDER BY nom");
$s->execute([$eid]); $units=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT d.id,d.code,d.nom,d.faculte_id,d.actif,f.nom unite_nom,f.type_unite,
    (SELECT COUNT(*) FROM filieres x WHERE x.departement_id=d.id AND x.etablissement_id=d.etablissement_id) nb_filieres
    FROM departements d
    LEFT JOIN facultes f ON f.id=d.faculte_id AND f.etablissement_id=d.etablissement_id
    WHERE d.etablissement_id=?
    ORDER BY d.nom");
$s->execute([$eid]); $items=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT COUNT(*) FROM filieres WHERE etablissement_id=? AND departement_id IS NOT NULL");
$s->execute([$eid]); $filieres=(int)$s->fetchColumn();

jsonResponse(true,'',[
    'enabled'=>true,
    'required'=>(bool)$cfg['departement_obligatoire'],
    'unit_enabled'=>(bool)$cfg['unite_academique_active'],
    'items'=>$items,
    'units'=>$units,
    'stats'=>[
        'total'=>count($items),
        'actifs'=>count(array_filter($items,fn($x)=>(int)$x['actif']===1)),
        'filieres'=>$filieres
    ]
]);

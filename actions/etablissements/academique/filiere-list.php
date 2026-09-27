<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$eid=(int)($_GET['etablissement_id']??0);
if(!$eid) jsonResponse(false,'Établissement invalide.',[],422);

$s=$pdo->prepare("SELECT filiere_active,filiere_obligatoire,departement_active,unite_academique_active,
    filiere_directe_etablissement_autorisee,filiere_directe_unite_autorisee
    FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1");
$s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);
if(!$cfg) jsonResponse(false,'Configuration académique introuvable.',[],404);

if(!(int)$cfg['filiere_active'])
    jsonResponse(true,'',['enabled'=>false,'items'=>[],'units'=>[],'departments'=>[],'stats'=>['total'=>0,'actifs'=>0,'options'=>0,'promotions'=>0]]);

$s=$pdo->prepare("SELECT id,code,nom,type_unite,actif FROM facultes WHERE etablissement_id=? ORDER BY nom");
$s->execute([$eid]); $units=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT d.id,d.code,d.nom,d.faculte_id,d.actif,f.nom unite_nom
    FROM departements d LEFT JOIN facultes f ON f.id=d.faculte_id
    WHERE d.etablissement_id=? ORDER BY d.nom");
$s->execute([$eid]); $departments=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT f.id,f.code,f.nom,f.duree_annees,f.description,f.faculte_id,f.departement_id,f.actif,
    d.nom departement_nom,u.nom unite_nom,u.type_unite,
    CASE WHEN f.departement_id IS NOT NULL THEN 'DEPARTEMENT'
         WHEN f.faculte_id IS NOT NULL THEN 'UNITE'
         ELSE 'ETABLISSEMENT' END rattachement_type,
    (SELECT COUNT(*) FROM options_specialites o WHERE o.filiere_id=f.id AND o.etablissement_id=f.etablissement_id) nb_options,
    (SELECT COUNT(*) FROM promotions p WHERE p.filiere_id=f.id AND p.etablissement_id=f.etablissement_id) nb_promotions
    FROM filieres f
    LEFT JOIN departements d ON d.id=f.departement_id AND d.etablissement_id=f.etablissement_id
    LEFT JOIN facultes u ON u.id=f.faculte_id AND u.etablissement_id=f.etablissement_id
    WHERE f.etablissement_id=? ORDER BY f.nom");
$s->execute([$eid]); $items=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT COUNT(*) FROM options_specialites WHERE etablissement_id=?");$s->execute([$eid]);$options=(int)$s->fetchColumn();
$s=$pdo->prepare("SELECT COUNT(*) FROM promotions WHERE etablissement_id=?");$s->execute([$eid]);$promotions=(int)$s->fetchColumn();

jsonResponse(true,'',[
    'enabled'=>true,
    'required'=>(bool)$cfg['filiere_obligatoire'],
    'allow_establishment'=>(bool)$cfg['filiere_directe_etablissement_autorisee'],
    'allow_unit'=>(bool)$cfg['filiere_directe_unite_autorisee'],
    'allow_department'=>(bool)$cfg['departement_active'],
    'items'=>$items,'units'=>$units,'departments'=>$departments,
    'stats'=>[
        'total'=>count($items),
        'actifs'=>count(array_filter($items,fn($x)=>(int)$x['actif']===1)),
        'options'=>$options,'promotions'=>$promotions
    ]
]);

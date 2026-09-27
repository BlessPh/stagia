<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$eid=(int)($_GET['etablissement_id']??0);
if(!$eid) jsonResponse(false,'Établissement invalide.',[],422);

$s=$pdo->prepare("SELECT promotion_active,promotion_obligatoire,option_specialite_active,option_specialite_obligatoire
    FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1");
$s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);
if(!$cfg) jsonResponse(false,'Configuration académique introuvable.',[],404);

if(!(int)$cfg['promotion_active'])
    jsonResponse(true,'',['enabled'=>false,'items'=>[],'filieres'=>[],'options'=>[],'stats'=>['total'=>0,'actifs'=>0,'stage_configs'=>0,'etudiants'=>0]]);

$s=$pdo->prepare("SELECT id,code,nom,actif FROM filieres WHERE etablissement_id=? ORDER BY nom");
$s->execute([$eid]); $filieres=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT id,filiere_id,code,nom,actif FROM options_specialites WHERE etablissement_id=? ORDER BY nom");
$s->execute([$eid]); $options=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT p.id,p.code,p.nom,p.niveau,p.description,p.filiere_id,p.option_specialite_id,p.actif,
    f.nom filiere_nom,o.nom option_nom,
    (SELECT COUNT(*) FROM promotion_stage_configs c WHERE c.promotion_id=p.id AND c.etablissement_id=p.etablissement_id AND c.actif=1) nb_stage_configs,
    (SELECT COUNT(*) FROM student_academic_enrollments ae WHERE ae.promotion_id=p.id AND ae.statut='EN_COURS') nb_etudiants_en_cours
    FROM promotions p
    JOIN filieres f ON f.id=p.filiere_id AND f.etablissement_id=p.etablissement_id
    LEFT JOIN options_specialites o ON o.id=p.option_specialite_id AND o.etablissement_id=p.etablissement_id
    WHERE p.etablissement_id=?
    ORDER BY f.nom,o.nom,p.niveau,p.nom");
$s->execute([$eid]); $items=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT COUNT(*) FROM promotion_stage_configs WHERE etablissement_id=? AND actif=1");
$s->execute([$eid]); $stageConfigs=(int)$s->fetchColumn();

$s=$pdo->prepare("SELECT COUNT(*) FROM student_academic_enrollments ae
    JOIN promotions p ON p.id=ae.promotion_id
    WHERE p.etablissement_id=? AND ae.statut='EN_COURS'");
$s->execute([$eid]); $etudiants=(int)$s->fetchColumn();

jsonResponse(true,'',[
    'enabled'=>true,
    'required'=>(bool)$cfg['promotion_obligatoire'],
    'option_enabled'=>(bool)$cfg['option_specialite_active'],
    'option_required'=>(bool)$cfg['option_specialite_obligatoire'],
    'items'=>$items,
    'filieres'=>$filieres,
    'options'=>$options,
    'stats'=>[
        'total'=>count($items),
        'actifs'=>count(array_filter($items,fn($x)=>(int)$x['actif']===1)),
        'stage_configs'=>$stageConfigs,
        'etudiants'=>$etudiants
    ]
]);

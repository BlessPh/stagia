<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$type=strtoupper(trim($_POST['type_etablissement']??''));$nom=trim($_POST['nom']??'');$description=trim($_POST['description']??'');
$bool=fn($k)=>(int)(($_POST[$k]??'0')==='1');
$unitTypes=array_values(array_unique(array_filter(array_map(fn($x)=>strtoupper(trim((string)$x)),(array)($_POST['unit_types']??[])))));

$s=$pdo->prepare("SELECT 1 FROM establishment_types WHERE code=? AND academic_enabled=1 AND actif=1 LIMIT 1");$s->execute([$type]);
if(!$s->fetchColumn())jsonResponse(false,"Ce type d'établissement n'est pas académique ou n'est pas actif.",[],422);
if($nom==='')jsonResponse(false,'Le nom du modèle est obligatoire.',[],422);

if($unitTypes){
    $marks=implode(',',array_fill(0,count($unitTypes),'?'));
    $s=$pdo->prepare("SELECT code FROM academic_unit_types WHERE actif=1 AND code IN($marks)");$s->execute($unitTypes);
    $allowed=$s->fetchAll(PDO::FETCH_COLUMN);
    if(count($allowed)!==count($unitTypes))jsonResponse(false,"Un type d'unité sélectionné est invalide ou inactif.",[],422);
}

$d=[
    'ua'=>$bool('unite_academique_active'),'uar'=>$bool('unite_academique_obligatoire'),'uap'=>$bool('unite_parentale_autorisee'),
    'da'=>$bool('departement_active'),'dr'=>$bool('departement_obligatoire'),'fa'=>$bool('filiere_active'),'fr'=>$bool('filiere_obligatoire'),
    'fde'=>$bool('filiere_directe_etablissement_autorisee'),'fdu'=>$bool('filiere_directe_unite_autorisee'),
    'oa'=>$bool('option_specialite_active'),'or'=>$bool('option_specialite_obligatoire'),'pa'=>$bool('promotion_active'),
    'pr'=>$bool('promotion_obligatoire'),'actif'=>$bool('actif'),'def'=>$bool('is_default')
];
if($d['uar']&&!$d['ua']||$d['dr']&&!$d['da']||$d['fr']&&!$d['fa']||$d['or']&&!$d['oa']||$d['pr']&&!$d['pa'])
    jsonResponse(false,'Un élément obligatoire doit également être activé.',[],422);
if($d['ua']&&!$unitTypes)jsonResponse(false,"Sélectionnez au moins un type d'unité académique.",[],422);
if($d['def']&&!$d['actif'])jsonResponse(false,'Le modèle par défaut doit être actif.',[],422);

try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT COUNT(*) FROM academic_structure_templates WHERE type_etablissement=? AND is_default=1 AND actif=1");$s->execute([$type]);
    if(!(int)$s->fetchColumn()&&$d['actif'])$d['def']=1;

    $base='TPL-'.$type;$code=$base;$n=2;$s=$pdo->prepare("SELECT 1 FROM academic_structure_templates WHERE code=? LIMIT 1");
    while(true){$s->execute([$code]);if(!$s->fetchColumn())break;$code=$base.'-'.$n++;}
    if($d['def'])$pdo->prepare("UPDATE academic_structure_templates SET is_default=0 WHERE type_etablissement=?")->execute([$type]);

    $pdo->prepare("INSERT INTO academic_structure_templates(code,type_etablissement,nom,description,
        unite_academique_active,unite_academique_obligatoire,unite_parentale_autorisee,departement_active,departement_obligatoire,
        filiere_active,filiere_obligatoire,filiere_directe_etablissement_autorisee,filiere_directe_unite_autorisee,
        option_specialite_active,option_specialite_obligatoire,promotion_active,promotion_obligatoire,configuration,version_no,is_default,actif,created_by_user_id)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,JSON_OBJECT('principe','ORGANISATION_REELLE'),1,?,?,?)")
        ->execute([$code,$type,$nom,$description?:null,$d['ua'],$d['uar'],$d['uap'],$d['da'],$d['dr'],$d['fa'],$d['fr'],$d['fde'],$d['fdu'],$d['oa'],$d['or'],$d['pa'],$d['pr'],$d['def'],$d['actif'],$_SESSION['user_id']??null]);

    $id=(int)$pdo->lastInsertId();
    $ins=$pdo->prepare("INSERT INTO academic_structure_template_unit_types(template_id,type_unite,libelle,ordre,actif)
        SELECT ?,code,libelle,?,1 FROM academic_unit_types WHERE code=? LIMIT 1");
    foreach($unitTypes as $k=>$u)$ins->execute([$id,($k+1)*10,$u]);

    $pdo->commit();jsonResponse(true,"Modèle $code ajouté.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();

$id=(int)($_POST['id']??0);$nom=trim($_POST['nom']??'');$description=trim($_POST['description']??'');
$bool=fn(string $k)=>(int)(($_POST[$k]??'0')==='1');
$unitTypes=array_values(array_unique(array_filter(array_map(fn($x)=>strtoupper(trim((string)$x)),(array)($_POST['unit_types']??[])))));
if(!$id||$nom==='')jsonResponse(false,'Modèle ou nom invalide.',[],422);

if($unitTypes){
    $marks=implode(',',array_fill(0,count($unitTypes),'?'));
    $s=$pdo->prepare("SELECT code FROM academic_unit_types WHERE code IN($marks)");$s->execute($unitTypes);
    if(count($s->fetchAll(PDO::FETCH_COLUMN))!==count($unitTypes))jsonResponse(false,"Un type d'unité sélectionné est invalide.",[],422);
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
    $s=$pdo->prepare("SELECT type_etablissement,is_default FROM academic_structure_templates WHERE id=? LIMIT 1 FOR UPDATE");$s->execute([$id]);$current=$s->fetch(PDO::FETCH_ASSOC);
    if(!$current)throw new RuntimeException('Modèle introuvable.');
    $type=$current['type_etablissement'];

    if(!$d['def']&&(int)$current['is_default']===1){
        $s=$pdo->prepare("SELECT COUNT(*) FROM academic_structure_templates WHERE type_etablissement=? AND id<>? AND is_default=1 AND actif=1");$s->execute([$type,$id]);
        if(!(int)$s->fetchColumn())throw new RuntimeException("Définissez d'abord un autre modèle actif par défaut pour ce type.");
    }
    if(!$d['actif']&&(int)$current['is_default']===1)throw new RuntimeException("Un modèle par défaut ne peut pas être désactivé.");
    if($d['def'])$pdo->prepare("UPDATE academic_structure_templates SET is_default=0 WHERE type_etablissement=? AND id<>?")->execute([$type,$id]);

    $pdo->prepare("UPDATE academic_structure_templates SET nom=?,description=?,
        unite_academique_active=?,unite_academique_obligatoire=?,unite_parentale_autorisee=?,departement_active=?,departement_obligatoire=?,
        filiere_active=?,filiere_obligatoire=?,filiere_directe_etablissement_autorisee=?,filiere_directe_unite_autorisee=?,
        option_specialite_active=?,option_specialite_obligatoire=?,promotion_active=?,promotion_obligatoire=?,is_default=?,actif=?,version_no=version_no+1 WHERE id=?")
        ->execute([$nom,$description?:null,$d['ua'],$d['uar'],$d['uap'],$d['da'],$d['dr'],$d['fa'],$d['fr'],$d['fde'],$d['fdu'],$d['oa'],$d['or'],$d['pa'],$d['pr'],$d['def'],$d['actif'],$id]);

    $pdo->prepare("UPDATE academic_structure_template_unit_types SET actif=0 WHERE template_id=?")->execute([$id]);
    $up=$pdo->prepare("INSERT INTO academic_structure_template_unit_types(template_id,type_unite,libelle,ordre,actif)
        SELECT ?,code,libelle,?,1 FROM academic_unit_types WHERE code=? LIMIT 1
        ON DUPLICATE KEY UPDATE libelle=VALUES(libelle),ordre=VALUES(ordre),actif=1");
    foreach($unitTypes as $k=>$u)$up->execute([$id,($k+1)*10,$u]);

    $pdo->commit();jsonResponse(true,"Modèle mis à jour. La nouvelle version s'appliquera aux prochaines validations.");
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e instanceof RuntimeException?$e->getMessage():'Erreur serveur : '.$e->getMessage(),[],500);
}

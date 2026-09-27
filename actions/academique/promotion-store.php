<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$cfg=$_SESSION['academic_settings']??[];
$filiereId=(int)($_POST['filiere_id']??0);
$optionId=(int)($_POST['option_specialite_id']??0)?:null;
$anneeId=(int)($_POST['annee_academique_id']??0);
$levelId=(int)($_POST['academic_level_id']??0);
$description=trim((string)($_POST['description']??''));

if(!$eid)jsonResponse(false,'Aucun établissement actif.',[],403);
if(empty($cfg['promotion_active']))jsonResponse(false,'Les promotions sont désactivées pour cet établissement.',[],403);
if(!$filiereId||!$anneeId||!$levelId)jsonResponse(false,'Programme, année académique et niveau sont obligatoires.',[],422);

try{
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT id,code,nom,curriculum_reference_id,preparatory_level_enabled FROM filieres WHERE id=? AND etablissement_id=? AND actif=1 AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL') LIMIT 1 FOR UPDATE");
    $s->execute([$filiereId,$eid]);$f=$s->fetch(PDO::FETCH_ASSOC);
    if(!$f)throw new RuntimeException('Filière / programme invalide ou non validé.');
    if(!(int)($f['curriculum_reference_id']??0))throw new RuntimeException("Cette filière n'a pas de référentiel de cursus.");

    $s=$pdo->prepare("SELECT id,libelle FROM annees_academiques WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");$s->execute([$anneeId,$eid]);$annee=$s->fetch(PDO::FETCH_ASSOC);
    if(!$annee)throw new RuntimeException('Année académique invalide ou inactive.');

    $s=$pdo->prepare("SELECT l.id,l.code,l.libelle,l.preparatoire,l.owner_etablissement_id,c.code cycle_code,c.libelle cycle_libelle FROM academic_levels l JOIN academic_cycles c ON c.id=l.academic_cycle_id AND c.actif=1 WHERE l.id=? AND l.actif=1 AND c.curriculum_reference_id=? AND (l.owner_etablissement_id IS NULL OR l.owner_etablissement_id=?) LIMIT 1");
    $s->execute([$levelId,(int)$f['curriculum_reference_id'],$eid]);$level=$s->fetch(PDO::FETCH_ASSOC);
    if(!$level)throw new RuntimeException('Ce niveau ne correspond pas au cursus de la filière ou appartient à un autre établissement.');
    if((int)$level['preparatoire']===1 && !(int)$f['preparatory_level_enabled'])throw new RuntimeException('Le niveau préparatoire n’est pas activé pour cette filière.');

    if((int)($cfg['option_specialite_obligatoire']??0) && !$optionId)throw new RuntimeException('Une option / spécialité est obligatoire pour ce parcours.');
    $option=null;
    if($optionId){
        if(empty($cfg['option_specialite_active']))throw new RuntimeException('Les options / spécialités sont désactivées.');
        $s=$pdo->prepare("SELECT id,nom FROM options_specialites WHERE id=? AND etablissement_id=? AND filiere_id=? AND actif=1 AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL') LIMIT 1");$s->execute([$optionId,$eid,$filiereId]);$option=$s->fetch(PDO::FETCH_ASSOC);
        if(!$option)throw new RuntimeException('Option / spécialité invalide ou non validée.');
    }

    $s=$pdo->prepare("SELECT id FROM promotions WHERE etablissement_id=? AND filiere_id=? AND option_specialite_id <=> ? AND annee_academique_id=? AND academic_level_id=? LIMIT 1");$s->execute([$eid,$filiereId,$optionId,$anneeId,$levelId]);if($s->fetchColumn())throw new RuntimeException('Cette promotion existe déjà pour ce programme, cette année et ce niveau.');

    $parts=[$f['nom']];if($option)$parts[]=$option['nom'];$parts[]=$annee['libelle'];$parts[]=$level['code'];$nom=implode(' · ',$parts);
    $pdo->prepare("INSERT INTO promotions(etablissement_id,filiere_id,option_specialite_id,annee_academique_id,academic_level_id,code,nom,niveau,description,actif) VALUES(?,?,?,?,?,NULL,?,?,?,1)")->execute([$eid,$filiereId,$optionId,$anneeId,$levelId,$nom,$level['code'],$description?:null]);
    $id=(int)$pdo->lastInsertId();$base='PRO-'.str_pad((string)$id,4,'0',STR_PAD_LEFT);$code=$base;$n=2;$check=$pdo->prepare("SELECT 1 FROM promotions WHERE code=? AND id<>? LIMIT 1");
    while(true){$check->execute([$code,$id]);if(!$check->fetchColumn())break;$code=$base.'-'.$n++;}
    $pdo->prepare("UPDATE promotions SET code=? WHERE id=? AND etablissement_id=?")->execute([$code,$id,$eid]);$pdo->commit();
    jsonResponse(true,"Promotion $code créée et activée.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

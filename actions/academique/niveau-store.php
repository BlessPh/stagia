<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$filiereId=(int)($_POST['filiere_id']??0);
$cycleId=(int)($_POST['academic_cycle_id']??0);
$code=mb_strtoupper(trim((string)($_POST['code']??'')),'UTF-8');
$libelle=trim((string)($_POST['libelle']??''));
$ordre=max(0,(int)($_POST['ordre']??0));
$preparatoire=((string)($_POST['preparatoire']??'0')==='1')?1:0;

if(!$eid)jsonResponse(false,'Aucun établissement actif.',[],403);
if(!$filiereId||!$cycleId||$code===''||$libelle==='')jsonResponse(false,'Filière, cycle, code et libellé sont obligatoires.',[],422);
if(mb_strlen($code)>30)jsonResponse(false,'Le code du niveau est trop long : 30 caractères maximum.',[],422);
if(mb_strlen($libelle)>150)jsonResponse(false,'Le libellé du niveau est trop long : 150 caractères maximum.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT id,nom,curriculum_reference_id,preparatory_level_enabled FROM filieres WHERE id=? AND etablissement_id=? AND actif=1 AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL') LIMIT 1 FOR UPDATE");
    $s->execute([$filiereId,$eid]);
    $f=$s->fetch(PDO::FETCH_ASSOC);
    if(!$f)throw new RuntimeException('Filière / programme invalide ou inactive.');
    if(!(int)($f['curriculum_reference_id']??0))throw new RuntimeException("Cette filière n'a pas de référentiel de cursus.");

    $s=$pdo->prepare("SELECT id,code,libelle FROM academic_cycles WHERE id=? AND curriculum_reference_id=? AND actif=1 LIMIT 1");
    $s->execute([$cycleId,(int)$f['curriculum_reference_id']]);
    $cycle=$s->fetch(PDO::FETCH_ASSOC);
    if(!$cycle)throw new RuntimeException('Cycle académique invalide pour le cursus sélectionné.');

    if($preparatoire&&!(int)$f['preparatory_level_enabled'])throw new RuntimeException('Le niveau préparatoire n’est pas activé pour cette filière / ce programme.');

    $s=$pdo->prepare("SELECT id FROM academic_levels WHERE academic_cycle_id=? AND UPPER(code)=UPPER(?) AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?) LIMIT 1");
    $s->execute([$cycleId,$code,$eid]);
    if($s->fetchColumn())throw new RuntimeException('Un niveau portant ce code existe déjà dans ce cycle.');

    if($ordre<=0){
        $s=$pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM academic_levels WHERE academic_cycle_id=? AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?)");
        $s->execute([$cycleId,$eid]);
        $ordre=max(1,(int)$s->fetchColumn());
    }

    $s=$pdo->prepare("INSERT INTO academic_levels(academic_cycle_id,code,libelle,ordre,preparatoire,owner_etablissement_id,actif) VALUES(?,?,?,?,?,?,1)");
    $s->execute([$cycleId,$code,$libelle,$ordre,$preparatoire,$eid]);
    $id=(int)$pdo->lastInsertId();

    $pdo->commit();
    jsonResponse(true,"Niveau $code ajouté.",['id'=>$id]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

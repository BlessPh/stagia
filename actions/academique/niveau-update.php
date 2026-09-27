<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$filiereId=(int)($_POST['filiere_id']??0);
$cycleId=(int)($_POST['academic_cycle_id']??0);
$code=mb_strtoupper(trim((string)($_POST['code']??'')),'UTF-8');
$libelle=trim((string)($_POST['libelle']??''));
$ordre=max(0,(int)($_POST['ordre']??0));
$preparatoire=((string)($_POST['preparatoire']??'0')==='1')?1:0;

if(!$eid)jsonResponse(false,'Aucun établissement actif.',[],403);
if(!$id||!$filiereId||!$cycleId||$code===''||$libelle==='')jsonResponse(false,'Données du niveau incomplètes.',[],422);
if(mb_strlen($code)>30)jsonResponse(false,'Le code du niveau est trop long : 30 caractères maximum.',[],422);
if(mb_strlen($libelle)>150)jsonResponse(false,'Le libellé du niveau est trop long : 150 caractères maximum.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT id,nom,curriculum_reference_id,preparatory_level_enabled FROM filieres WHERE id=? AND etablissement_id=? AND actif=1 AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL') LIMIT 1 FOR UPDATE");
    $s->execute([$filiereId,$eid]);
    $f=$s->fetch(PDO::FETCH_ASSOC);
    if(!$f)throw new RuntimeException('Filière / programme invalide ou inactive.');

    $s=$pdo->prepare("SELECT l.id,l.academic_cycle_id,l.code,(SELECT COUNT(*) FROM promotions p WHERE p.etablissement_id=? AND p.academic_level_id=l.id) usage_count FROM academic_levels l WHERE l.id=? AND l.owner_etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$eid,$id,$eid]);
    $level=$s->fetch(PDO::FETCH_ASSOC);
    if(!$level)throw new RuntimeException('Ce niveau est national ou appartient à un autre établissement : il ne peut pas être modifié ici.');

    $s=$pdo->prepare("SELECT id FROM academic_cycles WHERE id=? AND curriculum_reference_id=? AND actif=1 LIMIT 1");
    $s->execute([$cycleId,(int)$f['curriculum_reference_id']]);
    if(!$s->fetchColumn())throw new RuntimeException('Cycle académique invalide pour le cursus sélectionné.');

    if((int)$level['usage_count']>0&&(int)$level['academic_cycle_id']!==$cycleId)throw new RuntimeException('Ce niveau est déjà utilisé par une promotion. Son cycle ne peut plus être changé.');
    if($preparatoire&&!(int)$f['preparatory_level_enabled'])throw new RuntimeException('Le niveau préparatoire n’est pas activé pour cette filière / ce programme.');

    $s=$pdo->prepare("SELECT id FROM academic_levels WHERE academic_cycle_id=? AND UPPER(code)=UPPER(?) AND id<>? AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?) LIMIT 1");
    $s->execute([$cycleId,$code,$id,$eid]);
    if($s->fetchColumn())throw new RuntimeException('Un autre niveau portant ce code existe déjà dans ce cycle.');

    if($ordre<=0){
        $s=$pdo->prepare("SELECT COALESCE(MAX(ordre),0)+1 FROM academic_levels WHERE academic_cycle_id=? AND id<>? AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?)");
        $s->execute([$cycleId,$id,$eid]);
        $ordre=max(1,(int)$s->fetchColumn());
    }

    $s=$pdo->prepare("UPDATE academic_levels SET academic_cycle_id=?,code=?,libelle=?,ordre=?,preparatoire=? WHERE id=? AND owner_etablissement_id=?");
    $s->execute([$cycleId,$code,$libelle,$ordre,$preparatoire,$id,$eid]);

    $pdo->commit();
    jsonResponse(true,"Niveau $code modifié.",['id'=>$id]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

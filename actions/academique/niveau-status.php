<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$actif=(string)($_POST['actif']??'');

if(!$eid)jsonResponse(false,'Aucun établissement actif.',[],403);
if(!$id||($actif!=='0'&&$actif!=='1'))jsonResponse(false,'Données invalides.',[],422);

try{
    $s=$pdo->prepare("SELECT id,code FROM academic_levels WHERE id=? AND owner_etablissement_id=? LIMIT 1");
    $s->execute([$id,$eid]);
    $level=$s->fetch(PDO::FETCH_ASSOC);
    if(!$level)jsonResponse(false,'Seuls les niveaux créés par votre établissement peuvent être activés ou désactivés ici.',[],403);

    $pdo->prepare("UPDATE academic_levels SET actif=? WHERE id=? AND owner_etablissement_id=?")->execute([(int)$actif,$id,$eid]);
    jsonResponse(true,(int)$actif===1?'Niveau activé.':'Niveau désactivé.');
}catch(Throwable $e){
    jsonResponse(false,$e->getMessage(),[],422);
}

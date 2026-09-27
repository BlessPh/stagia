<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$nom=trim($_POST['nom']??'');
$faculteId=(int)($_POST['faculte_id']??0)?:null;

if(!$eid||!$id||$nom==='') jsonResponse(false,'Données invalides.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT departement_active FROM etablissement_academic_settings
        WHERE etablissement_id=? LIMIT 1");
    $s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);

    if(!$cfg) throw new RuntimeException('Configuration académique introuvable.');
    if(!(int)$cfg['departement_active']) throw new RuntimeException("Les départements ne sont pas activés pour cet établissement.");

    $s=$pdo->prepare("SELECT id FROM departements WHERE id=? AND etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$id,$eid]);
    if(!$s->fetchColumn()) throw new RuntimeException('Département introuvable.');

    if($faculteId){
        $s=$pdo->prepare("SELECT id FROM facultes WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
        $s->execute([$faculteId,$eid]);
        if(!$s->fetchColumn()) throw new RuntimeException('Unité académique invalide ou inactive.');
    }

    $s=$pdo->prepare("SELECT id FROM departements
        WHERE etablissement_id=? AND LOWER(TRIM(nom))=LOWER(TRIM(?)) AND id<>? LIMIT 1");
    $s->execute([$eid,$nom,$id]);
    if($s->fetchColumn()) throw new RuntimeException('Un département portant ce nom existe déjà.');

    $pdo->prepare("UPDATE departements SET faculte_id=?,nom=? WHERE id=? AND etablissement_id=?")
        ->execute([$faculteId,$nom,$id,$eid]);

    $pdo->prepare("UPDATE etablissement_academic_settings SET updated_by_user_id=? WHERE etablissement_id=?")
        ->execute([$_SESSION['user_id']??null,$eid]);

    $pdo->commit();
    jsonResponse(true,'Département mis à jour.');
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

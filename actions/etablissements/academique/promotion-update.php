<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$filiereId=(int)($_POST['filiere_id']??0);
$optionId=(int)($_POST['option_specialite_id']??0)?:null;
$nom=trim($_POST['nom']??'');
$niveau=trim($_POST['niveau']??'');
$description=trim($_POST['description']??'');

if(!$eid||!$id||!$filiereId||$nom===''||$niveau==='') jsonResponse(false,'Données invalides.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT promotion_active,option_specialite_active,option_specialite_obligatoire
        FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1");
    $s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);

    if(!$cfg) throw new RuntimeException('Configuration académique introuvable.');
    if(!(int)$cfg['promotion_active']) throw new RuntimeException('Les promotions ne sont pas activées.');

    $s=$pdo->prepare("SELECT id FROM promotions WHERE id=? AND etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$id,$eid]);
    if(!$s->fetchColumn()) throw new RuntimeException('Promotion introuvable.');

    $s=$pdo->prepare("SELECT id FROM filieres WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
    $s->execute([$filiereId,$eid]);
    if(!$s->fetchColumn()) throw new RuntimeException('Filière invalide ou inactive.');

    if((int)$cfg['option_specialite_obligatoire']&&!$optionId)
        throw new RuntimeException('Une option / spécialité est obligatoire pour cette promotion.');

    if($optionId){
        if(!(int)$cfg['option_specialite_active'])
            throw new RuntimeException('Les options / spécialités ne sont pas activées.');

        $s=$pdo->prepare("SELECT id FROM options_specialites
            WHERE id=? AND etablissement_id=? AND filiere_id=? AND actif=1 LIMIT 1");
        $s->execute([$optionId,$eid,$filiereId]);

        if(!$s->fetchColumn())
            throw new RuntimeException('Option / spécialité invalide ou non rattachée à cette filière.');
    }

    $s=$pdo->prepare("SELECT id FROM promotions
        WHERE etablissement_id=? AND filiere_id=? AND option_specialite_id <=> ?
          AND LOWER(TRIM(niveau))=LOWER(TRIM(?)) AND id<>? LIMIT 1");
    $s->execute([$eid,$filiereId,$optionId,$niveau,$id]);

    if($s->fetchColumn())
        throw new RuntimeException('Une autre promotion de ce niveau existe déjà pour ce parcours.');

    $pdo->prepare("UPDATE promotions
        SET filiere_id=?,option_specialite_id=?,nom=?,niveau=?,description=?
        WHERE id=? AND etablissement_id=?")
        ->execute([$filiereId,$optionId,$nom,$niveau,$description?:null,$id,$eid]);

    $pdo->prepare("UPDATE etablissement_academic_settings SET updated_by_user_id=? WHERE etablissement_id=?")
        ->execute([$_SESSION['user_id']??null,$eid]);

    $pdo->commit();
    jsonResponse(true,'Promotion mise à jour.');
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

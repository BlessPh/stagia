<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$filiereId=(int)($_POST['filiere_id']??0);
$optionId=(int)($_POST['option_specialite_id']??0)?:null;
$nom=trim($_POST['nom']??'');
$niveau=trim($_POST['niveau']??'');
$description=trim($_POST['description']??'');

if(!$eid||!$filiereId||$nom===''||$niveau==='') jsonResponse(false,'Filière, niveau et nom obligatoires.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT promotion_active,option_specialite_active,option_specialite_obligatoire
        FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);

    if(!$cfg) throw new RuntimeException('Configuration académique introuvable.');
    if(!(int)$cfg['promotion_active']) throw new RuntimeException("Les promotions ne sont pas activées pour cet établissement.");

    $s=$pdo->prepare("SELECT id FROM filieres WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
    $s->execute([$filiereId,$eid]);
    if(!$s->fetchColumn()) throw new RuntimeException('Filière invalide ou inactive.');

    if((int)$cfg['option_specialite_obligatoire']&&!$optionId)
        throw new RuntimeException('Une option / spécialité est obligatoire pour cette promotion.');

    if($optionId){
        if(!(int)$cfg['option_specialite_active'])
            throw new RuntimeException('Les options / spécialités ne sont pas activées pour cet établissement.');

        $s=$pdo->prepare("SELECT id FROM options_specialites
            WHERE id=? AND etablissement_id=? AND filiere_id=? AND actif=1 LIMIT 1");
        $s->execute([$optionId,$eid,$filiereId]);

        if(!$s->fetchColumn())
            throw new RuntimeException('Option / spécialité invalide ou non rattachée à cette filière.');
    }

    $s=$pdo->prepare("SELECT id FROM promotions
        WHERE etablissement_id=? AND filiere_id=? AND option_specialite_id <=> ?
          AND LOWER(TRIM(niveau))=LOWER(TRIM(?)) LIMIT 1");
    $s->execute([$eid,$filiereId,$optionId,$niveau]);

    if($s->fetchColumn())
        throw new RuntimeException('Une promotion de ce niveau existe déjà pour ce parcours.');

    $pdo->prepare("INSERT INTO promotions(
        etablissement_id,filiere_id,option_specialite_id,code,nom,niveau,description,actif
    ) VALUES(?,?,?,NULL,?,?,?,1)")
      ->execute([$eid,$filiereId,$optionId,$nom,$niveau,$description?:null]);

    $id=(int)$pdo->lastInsertId();
    $base='PRO-'.str_pad((string)$id,4,'0',STR_PAD_LEFT);
    $code=$base; $n=2;

    $check=$pdo->prepare("SELECT 1 FROM promotions WHERE code=? AND id<>? LIMIT 1");
    while(true){
        $check->execute([$code,$id]);
        if(!$check->fetchColumn()) break;
        $code=$base.'-'.$n++;
    }

    $pdo->prepare("UPDATE promotions SET code=? WHERE id=? AND etablissement_id=?")
        ->execute([$code,$id,$eid]);

    $pdo->prepare("UPDATE etablissement_academic_settings
        SET configuration_statut=IF(configuration_statut='A_CONFIGURER','EN_COURS',configuration_statut),
            updated_by_user_id=?
        WHERE etablissement_id=?")
        ->execute([$_SESSION['user_id']??null,$eid]);

    $pdo->commit();
    jsonResponse(true,"Promotion $code ajoutée.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

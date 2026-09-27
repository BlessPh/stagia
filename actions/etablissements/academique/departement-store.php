<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$nom=trim($_POST['nom']??'');
$faculteId=(int)($_POST['faculte_id']??0)?:null;

if(!$eid||$nom==='') jsonResponse(false,'Le nom du département est obligatoire.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT departement_active,unite_academique_active
        FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);

    if(!$cfg) throw new RuntimeException('Configuration académique introuvable.');
    if(!(int)$cfg['departement_active']) throw new RuntimeException("Les départements ne sont pas activés pour cet établissement.");

    if($faculteId){
        $s=$pdo->prepare("SELECT id FROM facultes WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
        $s->execute([$faculteId,$eid]);
        if(!$s->fetchColumn()) throw new RuntimeException('Unité académique invalide ou inactive.');
    }

    $s=$pdo->prepare("SELECT id FROM departements
        WHERE etablissement_id=? AND LOWER(TRIM(nom))=LOWER(TRIM(?)) LIMIT 1");
    $s->execute([$eid,$nom]);
    if($s->fetchColumn()) throw new RuntimeException('Un département portant ce nom existe déjà.');

    $pdo->prepare("INSERT INTO departements(etablissement_id,faculte_id,code,nom,actif)
        VALUES(?,?,NULL,?,1)")->execute([$eid,$faculteId,$nom]);

    $id=(int)$pdo->lastInsertId();
    $base='DEP-'.str_pad((string)$id,4,'0',STR_PAD_LEFT);
    $code=$base; $n=2;
    $check=$pdo->prepare("SELECT 1 FROM departements WHERE code=? AND id<>? LIMIT 1");
    while(true){
        $check->execute([$code,$id]);
        if(!$check->fetchColumn()) break;
        $code=$base.'-'.$n++;
    }

    $pdo->prepare("UPDATE departements SET code=? WHERE id=? AND etablissement_id=?")
        ->execute([$code,$id,$eid]);

    $pdo->prepare("UPDATE etablissement_academic_settings
        SET configuration_statut=IF(configuration_statut='A_CONFIGURER','EN_COURS',configuration_statut),
            updated_by_user_id=?
        WHERE etablissement_id=?")
        ->execute([$_SESSION['user_id']??null,$eid]);

    $pdo->commit();
    jsonResponse(true,"Département $code ajouté.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

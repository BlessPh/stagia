<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$nom=trim($_POST['nom']??'');
$mode=strtoupper(trim($_POST['rattachement_type']??''));
$parentId=(int)($_POST['parent_id']??0)?:null;
$duree=(int)($_POST['duree_annees']??0)?:null;
$description=trim($_POST['description']??'');

if(!$eid||$nom===''||!in_array($mode,['DEPARTEMENT','UNITE','ETABLISSEMENT'],true))
    jsonResponse(false,'Nom ou rattachement invalide.',[],422);
if($duree!==null&&($duree<1||$duree>20)) jsonResponse(false,'La durée doit être comprise entre 1 et 20 ans.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT filiere_active,departement_active,unite_academique_active,
        filiere_directe_etablissement_autorisee,filiere_directe_unite_autorisee
        FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$eid]); $cfg=$s->fetch(PDO::FETCH_ASSOC);
    if(!$cfg) throw new RuntimeException('Configuration académique introuvable.');
    if(!(int)$cfg['filiere_active']) throw new RuntimeException("Les filières ne sont pas activées pour cet établissement.");

    $departementId=null; $faculteId=null;

    if($mode==='DEPARTEMENT'){
        if(!(int)$cfg['departement_active']) throw new RuntimeException('Le rattachement à un département n’est pas autorisé.');
        if(!$parentId) throw new RuntimeException('Sélectionnez un département.');
        $s=$pdo->prepare("SELECT id,faculte_id FROM departements WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
        $s->execute([$parentId,$eid]); $d=$s->fetch(PDO::FETCH_ASSOC);
        if(!$d) throw new RuntimeException('Département invalide ou inactif.');
        $departementId=(int)$d['id']; $faculteId=(int)($d['faculte_id']??0)?:null;
    }elseif($mode==='UNITE'){
        if(!(int)$cfg['unite_academique_active']||!(int)$cfg['filiere_directe_unite_autorisee'])
            throw new RuntimeException("Le rattachement direct à une unité académique n'est pas autorisé.");
        if(!$parentId) throw new RuntimeException('Sélectionnez une unité académique.');
        $s=$pdo->prepare("SELECT id FROM facultes WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
        $s->execute([$parentId,$eid]);
        if(!$s->fetchColumn()) throw new RuntimeException('Unité académique invalide ou inactive.');
        $faculteId=$parentId;
    }else{
        if(!(int)$cfg['filiere_directe_etablissement_autorisee'])
            throw new RuntimeException("Le rattachement direct à l'établissement n'est pas autorisé.");
    }

    $s=$pdo->prepare("SELECT id FROM filieres
        WHERE etablissement_id=? AND LOWER(TRIM(nom))=LOWER(TRIM(?))
          AND departement_id <=> ? AND faculte_id <=> ? LIMIT 1");
    $s->execute([$eid,$nom,$departementId,$faculteId]);
    if($s->fetchColumn()) throw new RuntimeException('Une filière portant ce nom existe déjà dans ce rattachement.');

    $pdo->prepare("INSERT INTO filieres(etablissement_id,faculte_id,departement_id,code,nom,duree_annees,description,actif)
        VALUES(?,?,?,NULL,?,?,?,1)")
        ->execute([$eid,$faculteId,$departementId,$nom,$duree,$description?:null]);

    $id=(int)$pdo->lastInsertId(); $base='FIL-'.str_pad((string)$id,4,'0',STR_PAD_LEFT); $code=$base; $n=2;
    $check=$pdo->prepare("SELECT 1 FROM filieres WHERE code=? AND id<>? LIMIT 1");
    while(true){$check->execute([$code,$id]);if(!$check->fetchColumn())break;$code=$base.'-'.$n++;}

    $pdo->prepare("UPDATE filieres SET code=? WHERE id=? AND etablissement_id=?")->execute([$code,$id,$eid]);
    $pdo->prepare("UPDATE etablissement_academic_settings
        SET configuration_statut=IF(configuration_statut='A_CONFIGURER','EN_COURS',configuration_statut),updated_by_user_id=?
        WHERE etablissement_id=?")->execute([$_SESSION['user_id']??null,$eid]);

    $pdo->commit();
    jsonResponse(true,"Filière $code ajoutée.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

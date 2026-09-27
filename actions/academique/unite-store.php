<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0);
$type=strtoupper(trim($_POST['type_unite']??''));
$nom=trim($_POST['nom']??'');
$parentId=(int)($_POST['parent_id']??0)?:null;

if(!$eid||$type===''||$nom==='') jsonResponse(false,'Type et nom obligatoires.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT unite_parentale_autorisee FROM etablissement_academic_settings
        WHERE etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$eid]); $settings=$s->fetch(PDO::FETCH_ASSOC);
    if(!$settings) throw new RuntimeException('Configuration académique introuvable.');

    $s=$pdo->prepare("SELECT 1 FROM etablissement_academic_unit_types
        WHERE etablissement_id=? AND type_unite=? AND actif=1 LIMIT 1");
    $s->execute([$eid,$type]);
    if(!$s->fetchColumn()) throw new RuntimeException("Ce type d'unité n'est pas autorisé pour cet établissement.");

    if($parentId){
        if(!(int)$settings['unite_parentale_autorisee'])
            throw new RuntimeException('Les sous-unités ne sont pas autorisées pour ce modèle.');
        $s=$pdo->prepare("SELECT id FROM facultes WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
        $s->execute([$parentId,$eid]);
        if(!$s->fetchColumn()) throw new RuntimeException('Unité parente invalide.');
    }

    $s=$pdo->prepare("SELECT id FROM facultes WHERE etablissement_id=? AND LOWER(TRIM(nom))=LOWER(TRIM(?)) LIMIT 1");
    $s->execute([$eid,$nom]);
    if($s->fetchColumn()) throw new RuntimeException('Une unité portant ce nom existe déjà.');

    $pdo->prepare("INSERT INTO facultes(etablissement_id,type_unite,parent_id,code,nom,actif)
        VALUES(?,?,?,NULL,?,1)")->execute([$eid,$type,$parentId,$nom]);
    $id=(int)$pdo->lastInsertId();

    $s=$pdo->prepare("SELECT prefixe_code FROM academic_unit_types WHERE code=? LIMIT 1");
    $s->execute([$type]); $prefix=strtoupper((string)$s->fetchColumn());
    if($prefix==='') $prefix=substr(preg_replace('/[^A-Z0-9]/','',$type)?:'UNA',0,6);

    $base=$prefix.'-'.str_pad((string)$id,4,'0',STR_PAD_LEFT); $code=$base; $n=2;
    $check=$pdo->prepare("SELECT 1 FROM facultes WHERE code=? AND id<>? LIMIT 1");
    while(true){$check->execute([$code,$id]);if(!$check->fetchColumn())break;$code=$base.'-'.$n++;}

    $pdo->prepare("UPDATE facultes SET code=? WHERE id=? AND etablissement_id=?")->execute([$code,$id,$eid]);
    $pdo->prepare("UPDATE etablissement_academic_settings
        SET configuration_statut=IF(configuration_statut='A_CONFIGURER','EN_COURS',configuration_statut),
            updated_by_user_id=?
        WHERE etablissement_id=?")
        ->execute([$_SESSION['user_id']??null,$eid]);

    $pdo->commit();
    jsonResponse(true,"Unité $code ajoutée.",['id'=>$id,'code'=>$code]);
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']); verifyAjaxCsrf();

$eid=(int)($_POST['etablissement_id']??0); $id=(int)($_POST['id']??0);
$type=strtoupper(trim($_POST['type_unite']??'')); $nom=trim($_POST['nom']??'');
$parentId=(int)($_POST['parent_id']??0)?:null;

if(!$eid||!$id||$type===''||$nom==='') jsonResponse(false,'Données invalides.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT unite_parentale_autorisee FROM etablissement_academic_settings WHERE etablissement_id=? LIMIT 1");
    $s->execute([$eid]); $settings=$s->fetch(PDO::FETCH_ASSOC);
    if(!$settings) throw new RuntimeException('Configuration académique introuvable.');

    $s=$pdo->prepare("SELECT 1 FROM facultes WHERE id=? AND etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$id,$eid]); if(!$s->fetchColumn()) throw new RuntimeException('Unité introuvable.');

    $s=$pdo->prepare("SELECT 1 FROM etablissement_academic_unit_types WHERE etablissement_id=? AND type_unite=? AND actif=1 LIMIT 1");
    $s->execute([$eid,$type]); if(!$s->fetchColumn()) throw new RuntimeException("Ce type d'unité n'est pas autorisé.");

    if($parentId){
        if($parentId===$id) throw new RuntimeException('Une unité ne peut pas être sa propre parente.');
        if(!(int)$settings['unite_parentale_autorisee']) throw new RuntimeException('Les sous-unités ne sont pas autorisées.');

        $s=$pdo->prepare("SELECT id,parent_id FROM facultes WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
        $cursor=$parentId; $seen=[];
        while($cursor){
            if($cursor===$id) throw new RuntimeException('Cette sélection créerait une boucle hiérarchique.');
            if(isset($seen[$cursor])) throw new RuntimeException('Hiérarchie académique invalide.');
            $seen[$cursor]=1; $s->execute([$cursor,$eid]); $row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row) throw new RuntimeException('Unité parente invalide.');
            $cursor=(int)($row['parent_id']??0);
        }
    }

    $s=$pdo->prepare("SELECT id FROM facultes WHERE etablissement_id=? AND LOWER(TRIM(nom))=LOWER(TRIM(?)) AND id<>? LIMIT 1");
    $s->execute([$eid,$nom,$id]); if($s->fetchColumn()) throw new RuntimeException('Une unité portant ce nom existe déjà.');

    $pdo->prepare("UPDATE facultes SET type_unite=?,parent_id=?,nom=? WHERE id=? AND etablissement_id=?")
        ->execute([$type,$parentId,$nom,$id,$eid]);
    $pdo->prepare("UPDATE etablissement_academic_settings SET updated_by_user_id=? WHERE etablissement_id=?")
        ->execute([$_SESSION['user_id']??null,$eid]);

    $pdo->commit(); jsonResponse(true,'Unité académique mise à jour.');
}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

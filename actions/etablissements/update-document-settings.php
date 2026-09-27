<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/document-branding.php';

requireRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);

function docBack(string $type,string $message):never{
    $_SESSION['document_settings_flash']=['type'=>$type,'message'=>$message];
    header('Location: '.BASE_URL.'/views/etablissements/parametres-documents.php');
    exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST')docBack('danger','Méthode invalide.');
$csrf=(string)($_SESSION['csrf']??'');
if($csrf===''||!hash_equals($csrf,(string)($_POST['csrf']??''))){http_response_code(403);exit('Requête invalide.');}

$eid=(int)currentEtablissementId($pdo);
if(!$eid)docBack('danger','Aucun établissement associé.');

$fields=['document_secretariat','document_faculte','document_departement','document_signataire_nom','document_signataire_fonction','document_slogan','document_footer'];
$data=[];
foreach($fields as $f)$data[$f]=trim((string)($_POST[$f]??''));

function saveDocAsset(string $field,int $eid):?string{
    if(empty($_FILES[$field])||($_FILES[$field]['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
    $file=$_FILES[$field];
    if($file['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Erreur upload '.$field.'.');
    if(($file['size']??0)<=0)throw new RuntimeException('Fichier vide.');
    if(($file['size']??0)>2*1024*1024)throw new RuntimeException('Le fichier ne doit pas dépasser 2 Mo.');
    if(!is_uploaded_file($file['tmp_name']))throw new RuntimeException('Fichier invalide.');
    $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if(!isset($allowed[$mime]))throw new RuntimeException('Format non autorisé. Utilisez PNG, JPG/JPEG ou WEBP.');
    $dir=__DIR__.'/../../uploads/etablissements/documents/'.$eid;
    if(!is_dir($dir)&&!mkdir($dir,0775,true))throw new RuntimeException('Impossible de créer le dossier documents.');
    $name=$field.'_'.bin2hex(random_bytes(12)).'.'.$allowed[$mime];
    $dest=$dir.'/'.$name;
    if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('Impossible d’enregistrer le fichier.');
    return 'uploads/etablissements/documents/'.$eid.'/'.$name;
}

try{
    if(!stagiaDocColumnExists($pdo,'etablissements','document_secretariat'))
        throw new RuntimeException('Colonnes documents manquantes. Ouvrez d’abord tools/apply-document-settings-schema.php');

    $signature=saveDocAsset('document_signature',$eid);
    $cachet=saveDocAsset('document_cachet',$eid);

    $set=[];$params=[];
    foreach($fields as $f){$set[]="$f=?";$params[]=$data[$f]!==''?$data[$f]:null;}
    if($signature!==null){$set[]='document_signature=?';$params[]=$signature;}
    if($cachet!==null){$set[]='document_cachet=?';$params[]=$cachet;}
    $params[]=$eid;
    $pdo->prepare('UPDATE etablissements SET '.implode(',',$set).' WHERE id=?')->execute($params);
    docBack('success','Paramètres documents enregistrés.');
}catch(Throwable $e){
    docBack('danger',$e->getMessage());
}

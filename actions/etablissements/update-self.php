<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);

if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Location: '.BASE_URL.'/views/espace-etablissement/etablissement.php');
    exit;
}

$sessionCsrf=(string)($_SESSION['csrf']??'');
$postCsrf=(string)($_POST['csrf']??'');

if($sessionCsrf===''||$postCsrf===''||!hash_equals($sessionCsrf,$postCsrf)){
    http_response_code(403);
    exit('Requête invalide.');
}

$etablissementId=(int)currentEtablissementId($pdo);
if(!$etablissementId){
    http_response_code(403);
    exit('Aucun établissement associé.');
}

function redirectEstablishment(string $message,string $type='success'):never{
    $_SESSION['etablissement_flash']=['message'=>$message,'type'=>$type];
    header('Location: '.BASE_URL.'/views/espace-etablissement/etablissement.php');
    exit;
}

$email=strtolower(trim((string)($_POST['email']??'')));
$telephone=trim((string)($_POST['telephone']??''));
$province=trim((string)($_POST['province']??''));
$ville=trim((string)($_POST['ville']??''));
$adresse=trim((string)($_POST['adresse']??''));

if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))
    redirectEstablishment("L'adresse e-mail institutionnelle est invalide.",'danger');

$stmt=$pdo->prepare("SELECT logo FROM etablissements WHERE id=? LIMIT 1");
$stmt->execute([$etablissementId]);
$oldLogo=$stmt->fetchColumn();

if($oldLogo===false)redirectEstablishment('Établissement introuvable.','danger');

$logo=$_FILES['logo']??null;
$newLogoPath=null;
$newLogoFullPath=null;

try{
    if($logo&&($logo['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
        if($logo['error']!==UPLOAD_ERR_OK)
            throw new RuntimeException("Une erreur est survenue lors de l'envoi du logo.");

        if(($logo['size']??0)<=0)
            throw new RuntimeException('Le logo est vide.');

        if(($logo['size']??0)>2*1024*1024)
            throw new RuntimeException('Le logo ne doit pas dépasser 2 Mo.');

        if(!is_uploaded_file($logo['tmp_name']))
            throw new RuntimeException('Le logo envoyé est invalide.');

        $allowed=[
            'image/png'=>'png',
            'image/jpeg'=>'jpg',
            'image/webp'=>'webp'
        ];

        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($logo['tmp_name']);
        if(!isset($allowed[$mime]))
            throw new RuntimeException('Format du logo non autorisé. Utilisez PNG, JPG, JPEG ou WEBP.');

        $dir=__DIR__.'/../../uploads/etablissements/logos';
        if(!is_dir($dir)&&!mkdir($dir,0775,true))
            throw new RuntimeException('Impossible de préparer le dossier des logos.');

        $name=$etablissementId.'_'.bin2hex(random_bytes(16)).'.'.$allowed[$mime];
        $newLogoFullPath=$dir.'/'.$name;

        if(!move_uploaded_file($logo['tmp_name'],$newLogoFullPath))
            throw new RuntimeException("Impossible d'enregistrer le logo.");

        $newLogoPath='uploads/etablissements/logos/'.$name;
    }

    $pdo->beginTransaction();

    if($newLogoPath!==null){
        $stmt=$pdo->prepare("
            UPDATE etablissements
            SET email=?,telephone=?,province=?,ville=?,adresse=?,logo=?
            WHERE id=?
        ");
        $stmt->execute([
            $email?:null,$telephone?:null,$province?:null,$ville?:null,$adresse?:null,
            $newLogoPath,$etablissementId
        ]);
    }else{
        $stmt=$pdo->prepare("
            UPDATE etablissements
            SET email=?,telephone=?,province=?,ville=?,adresse=?
            WHERE id=?
        ");
        $stmt->execute([
            $email?:null,$telephone?:null,$province?:null,$ville?:null,$adresse?:null,
            $etablissementId
        ]);
    }

    $pdo->commit();

    if($newLogoPath!==null&&$oldLogo){
        $oldLogo=(string)$oldLogo;
        if(str_starts_with($oldLogo,'uploads/etablissements/logos/')){
            $oldFull=__DIR__.'/../../'.ltrim($oldLogo,'/');
            if(is_file($oldFull))@unlink($oldFull);
        }
    }

    /*
     * Le contexte est rechargé au prochain accès via auth.php.
     * On met toutefois à jour les valeurs simples visibles dans la session.
     */
    $_SESSION['etablissement_nom']=$_SESSION['etablissement_nom']??'';

    redirectEstablishment('Informations de l’établissement mises à jour.');

}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    if($newLogoFullPath&&is_file($newLogoFullPath))@unlink($newLogoFullPath);

    error_log('[ETABLISSEMENT SELF UPDATE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    redirectEstablishment(
        $e instanceof RuntimeException?$e->getMessage():"Impossible de mettre à jour l'établissement.",
        'danger'
    );
}

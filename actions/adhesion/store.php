<?php
if(session_status()!==PHP_SESSION_ACTIVE) session_start();

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';

/* =========================================================
   OUTILS
========================================================= */
function back(array $errors,array $data=[]):never{
    $_SESSION['adhesion_errors']=$errors;
    $_SESSION['adhesion_old']=$data;
    header('Location: '.BASE_URL.'/adhesion.php');
    exit;
}

function value(string $key):string{
    return trim((string)($_POST[$key]??''));
}

/* =========================================================
   REQUÊTE + CSRF
========================================================= */
if($_SERVER['REQUEST_METHOD']!=='POST'){
    header('Location: '.BASE_URL.'/adhesion.php');
    exit;
}

$sessionCsrf=(string)($_SESSION['csrf']??'');
$postCsrf=(string)($_POST['csrf']??'');

if($sessionCsrf===''||$postCsrf===''||!hash_equals($sessionCsrf,$postCsrf)){
    http_response_code(403);
    exit('Requête invalide.');
}

/* =========================================================
   DONNÉES
========================================================= */
$fields=[
    'nom_etablissement','type_etablissement','numero_agrement',
    'email_etablissement','telephone_etablissement','province',
    'ville','adresse','responsable_nom','responsable_postnom',
    'responsable_prenom','responsable_fonction',
    'responsable_email','responsable_telephone'
];

$data=[];
foreach($fields as $field) $data[$field]=value($field);

$data['nom_etablissement']=preg_replace('/\s+/u',' ',$data['nom_etablissement'])??$data['nom_etablissement'];
$data['type_etablissement']=strtoupper($data['type_etablissement']);
$data['responsable_email']=strtolower($data['responsable_email']);
$data['email_etablissement']=strtolower($data['email_etablissement']);

/* =========================================================
   VALIDATION
========================================================= */
$errors=[];

if($data['nom_etablissement']==='')
    $errors[]="Le nom de l'établissement est obligatoire.";

if($data['responsable_nom']==='')
    $errors[]="Le nom du responsable est obligatoire.";

if($data['responsable_telephone']==='')
    $errors[]="Le téléphone du responsable est obligatoire.";

if(!filter_var($data['responsable_email'],FILTER_VALIDATE_EMAIL))
    $errors[]="L'adresse e-mail du responsable est invalide.";

if($data['email_etablissement']!==''&&!filter_var($data['email_etablissement'],FILTER_VALIDATE_EMAIL))
    $errors[]="L'adresse e-mail institutionnelle est invalide.";

/* =========================================================
   TYPE D'ÉTABLISSEMENT DYNAMIQUE
========================================================= */
if($data['type_etablissement']===''){
    $errors[]="Le type d'établissement est obligatoire.";
}else{
    $stmt=$pdo->prepare("
        SELECT code
        FROM establishment_types
        WHERE code=? AND actif=1 AND adhesion_enabled=1
        LIMIT 1
    ");
    $stmt->execute([$data['type_etablissement']]);

    if(!$stmt->fetchColumn())
        $errors[]="Ce type d'établissement n'est pas disponible pour une demande d'adhésion.";
}

/* =========================================================
   DOUBLONS ÉTABLISSEMENT
========================================================= */
if($data['nom_etablissement']!==''){
    /* Une demande rejetée ne bloque pas une nouvelle demande */
    $stmt=$pdo->prepare("
        SELECT reference
        FROM demandes_adhesion
        WHERE LOWER(TRIM(nom_etablissement))=LOWER(TRIM(?))
          AND statut<>'REJETEE'
        LIMIT 1
    ");
    $stmt->execute([$data['nom_etablissement']]);
    $ref=$stmt->fetchColumn();

    if($ref)
        $errors[]="Une demande active existe déjà pour cet établissement ($ref).";

    $stmt=$pdo->prepare("
        SELECT id
        FROM etablissements
        WHERE LOWER(TRIM(nom))=LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$data['nom_etablissement']]);

    if($stmt->fetchColumn())
        $errors[]="Cet établissement possède déjà un espace STAGIA-RDC.";
}

/* =========================================================
   NUMÉRO D'AGRÉMENT
========================================================= */
if($data['numero_agrement']!==''){
    $stmt=$pdo->prepare("
        SELECT id
        FROM etablissements
        WHERE numero_agrement=?
        LIMIT 1
    ");
    $stmt->execute([$data['numero_agrement']]);

    if($stmt->fetchColumn())
        $errors[]="Ce numéro d'agrément est déjà utilisé.";

    $stmt=$pdo->prepare("
        SELECT reference
        FROM demandes_adhesion
        WHERE numero_agrement=? AND statut<>'REJETEE'
        LIMIT 1
    ");
    $stmt->execute([$data['numero_agrement']]);
    $ref=$stmt->fetchColumn();

    if($ref)
        $errors[]="Ce numéro d'agrément est déjà associé à la demande $ref.";
}

/* =========================================================
   E-MAIL RESPONSABLE
========================================================= */
if(filter_var($data['responsable_email'],FILTER_VALIDATE_EMAIL)){
    $stmt=$pdo->prepare("
        SELECT reference
        FROM demandes_adhesion
        WHERE LOWER(TRIM(responsable_email))=LOWER(TRIM(?))
          AND statut<>'REJETEE'
        LIMIT 1
    ");
    $stmt->execute([$data['responsable_email']]);
    $ref=$stmt->fetchColumn();

    if($ref)
        $errors[]="Cette adresse e-mail est déjà associée à la demande $ref.";

    $stmt=$pdo->prepare("
        SELECT id
        FROM users
        WHERE LOWER(TRIM(email))=LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$data['responsable_email']]);

    if($stmt->fetchColumn())
        $errors[]="Cette adresse e-mail est déjà utilisée par un compte STAGIA-RDC.";
}

/* =========================================================
   LOGO — FACULTATIF
========================================================= */
$logo=$_FILES['logo']??null;
$logoAllowed=[
    'image/png'=>'png',
    'image/jpeg'=>'jpg',
    'image/webp'=>'webp'
];
$logoMime=null;

if($logo&&($logo['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
    if($logo['error']!==UPLOAD_ERR_OK){
        $errors[]="Une erreur est survenue lors de l'envoi du logo.";
    }else{
        if(($logo['size']??0)<=0)
            $errors[]="Le logo est vide.";

        if(($logo['size']??0)>2*1024*1024)
            $errors[]="Le logo ne doit pas dépasser 2 Mo.";

        if(!is_uploaded_file($logo['tmp_name']))
            $errors[]="Le logo envoyé est invalide.";

        if(is_uploaded_file($logo['tmp_name'])){
            $logoMime=(new finfo(FILEINFO_MIME_TYPE))->file($logo['tmp_name']);

            if(!isset($logoAllowed[$logoMime]))
                $errors[]="Format du logo non autorisé. Utilisez PNG, JPG, JPEG ou WEBP.";
        }
    }
}

/* =========================================================
   PIÈCE JUSTIFICATIVE
========================================================= */
$file=$_FILES['piece_justificative']??null;
$allowed=[
    'application/pdf'=>'pdf',
    'image/jpeg'=>'jpg',
    'image/png'=>'png'
];
$mime=null;

if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE){
    $errors[]="La pièce justificative est obligatoire.";
}elseif($file['error']!==UPLOAD_ERR_OK){
    $errors[]="Une erreur est survenue lors de l'envoi de la pièce justificative.";
}else{
    if(($file['size']??0)<=0)
        $errors[]="La pièce justificative est vide.";

    if(($file['size']??0)>5*1024*1024)
        $errors[]="La pièce justificative ne doit pas dépasser 5 Mo.";

    if(!is_uploaded_file($file['tmp_name']))
        $errors[]="Le fichier envoyé est invalide.";

    if(is_uploaded_file($file['tmp_name'])){
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

        if(!isset($allowed[$mime]))
            $errors[]="Format non autorisé. Utilisez PDF, JPG, JPEG ou PNG.";
    }
}

if($errors) back(array_values(array_unique($errors)),$data);

/* =========================================================
   RÉFÉRENCE
========================================================= */
do{
    $reference='ADH-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(4)));

    $stmt=$pdo->prepare("
        SELECT 1
        FROM demandes_adhesion
        WHERE reference=?
        LIMIT 1
    ");
    $stmt->execute([$reference]);

}while($stmt->fetchColumn());

/* =========================================================
   ENREGISTREMENT DE LA PIÈCE JUSTIFICATIVE
========================================================= */
$uploadDir=__DIR__.'/../../uploads/adhesions/';

if(!is_dir($uploadDir)&&!mkdir($uploadDir,0755,true))
    back(["Impossible de préparer le dossier des pièces justificatives."],$data);

$fileName=strtolower($reference).'-'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
$destination=$uploadDir.$fileName;
$filePath='uploads/adhesions/'.$fileName;

if(!move_uploaded_file($file['tmp_name'],$destination))
    back(["Impossible d'enregistrer la pièce justificative."],$data);

/* =========================================================
   ENREGISTREMENT DU LOGO
========================================================= */
$logoDestination=null;
$logoPath=null;

if($logo&&($logo['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
    $logoDir=__DIR__.'/../../uploads/adhesions/logos/';

    if(!is_dir($logoDir)&&!mkdir($logoDir,0755,true)){
        if(is_file($destination)) @unlink($destination);
        back(["Impossible de préparer le dossier des logos."],$data);
    }

    $logoName=strtolower($reference).'-logo-'.bin2hex(random_bytes(8)).'.'.$logoAllowed[$logoMime];
    $logoDestination=$logoDir.$logoName;
    $logoPath='uploads/adhesions/logos/'.$logoName;

    if(!move_uploaded_file($logo['tmp_name'],$logoDestination)){
        if(is_file($destination)) @unlink($destination);
        back(["Impossible d'enregistrer le logo de l'établissement."],$data);
    }
}

/* =========================================================
   DEMANDE D'ADHÉSION
========================================================= */
try{
    $pdo->beginTransaction();

    /* Recontrôle avant insertion */
    $stmt=$pdo->prepare("
        SELECT id
        FROM demandes_adhesion
        WHERE LOWER(TRIM(nom_etablissement))=LOWER(TRIM(?))
          AND statut<>'REJETEE'
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$data['nom_etablissement']]);

    if($stmt->fetchColumn())
        throw new RuntimeException("Une demande active existe déjà pour cet établissement.");

    $stmt=$pdo->prepare("
        INSERT INTO demandes_adhesion(
            reference,nom_etablissement,logo,type_etablissement,numero_agrement,
            email_etablissement,telephone_etablissement,province,ville,adresse,
            piece_justificative,responsable_nom,responsable_postnom,
            responsable_prenom,responsable_fonction,responsable_email,
            responsable_telephone,statut
        )
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'SOUMISE')
    ");

    $stmt->execute([
        $reference,
        $data['nom_etablissement'],
        $logoPath,
        $data['type_etablissement'],
        $data['numero_agrement']?:null,
        $data['email_etablissement']?:null,
        $data['telephone_etablissement']?:null,
        $data['province']?:null,
        $data['ville']?:null,
        $data['adresse']?:null,
        $filePath,
        $data['responsable_nom'],
        $data['responsable_postnom']?:null,
        $data['responsable_prenom']?:null,
        $data['responsable_fonction']?:null,
        $data['responsable_email'],
        $data['responsable_telephone']
    ]);

    $pdo->commit();

}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();

    if(is_file($destination)) @unlink($destination);
    if($logoDestination&&is_file($logoDestination)) @unlink($logoDestination);

    error_log(
        '[ADHESION STORE] '.$e->getMessage().
        ' | '.$e->getFile().':'.$e->getLine()
    );

    back(
        $e instanceof RuntimeException
            ? [$e->getMessage()]
            : ["Impossible d'enregistrer la demande."],
        $data
    );
}

/* =========================================================
   SUCCÈS
========================================================= */
unset($_SESSION['adhesion_errors'],$_SESSION['adhesion_old']);

header(
    'Location: '.BASE_URL.
    '/adhesion-success.php?ref='.
    urlencode($reference)
);
exit;

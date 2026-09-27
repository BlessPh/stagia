<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';


/* =========================================================
   SÉCURITÉ
========================================================= */

if(($_SESSION['role_code']??'')!=='SUPER_ADMIN'){
    http_response_code(403);
    exit('Accès refusé.');
}

$id=(int)($_GET['id']??0);

if(!$id){
    http_response_code(400);
    exit('Demande invalide.');
}


/* =========================================================
   DOCUMENT
========================================================= */

$stmt=$pdo->prepare("
    SELECT piece_justificative
    FROM demandes_adhesion
    WHERE id=?
    LIMIT 1
");

$stmt->execute([$id]);

$document=$stmt->fetchColumn();

if(!$document){
    http_response_code(404);
    exit('Pièce justificative introuvable.');
}


/* =========================================================
   CHEMIN SÉCURISÉ
========================================================= */

$uploadDir=realpath(
    __DIR__.'/../../uploads/adhesions'
);

$file=realpath(
    __DIR__.'/../../'.$document
);

if(
    !$uploadDir ||
    !$file ||
    !is_file($file) ||
    strpos($file,$uploadDir.DIRECTORY_SEPARATOR)!==0
){
    http_response_code(404);
    exit('Document introuvable.');
}


/* =========================================================
   TYPE MIME
========================================================= */

$finfo=new finfo(FILEINFO_MIME_TYPE);
$mime=$finfo->file($file);

$allowed=[
    'application/pdf',
    'image/jpeg',
    'image/png'
];

if(!in_array($mime,$allowed,true)){
    http_response_code(403);
    exit('Type de document non autorisé.');
}


/* =========================================================
   AFFICHAGE
========================================================= */

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($file));
header(
    'Content-Disposition: inline; filename="'.
    basename($file).
    '"'
);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

readfile($file);
exit;
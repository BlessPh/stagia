<?php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);

$id=(int)($_GET['id']??0);

if(!$id){
    http_response_code(400);
    exit('Établissement invalide.');
}


/* =========================================================
   RÉCUPÉRER LA PIÈCE
========================================================= */

$stmt=$pdo->prepare("
    SELECT
        id,
        nom,
        piece_justificative
    FROM etablissements
    WHERE id=?
    LIMIT 1
");

$stmt->execute([$id]);

$etablissement=$stmt->fetch(PDO::FETCH_ASSOC);


if(!$etablissement){
    http_response_code(404);
    exit('Établissement introuvable.');
}


if(empty($etablissement['piece_justificative'])){
    http_response_code(404);
    exit('Aucune pièce justificative enregistrée.');
}


/* =========================================================
   CHEMIN DU PROJET
========================================================= */

$projectRoot=realpath(
    __DIR__.'/../..'
);

$relativePath=str_replace(
    ['\\','/'],
    DIRECTORY_SEPARATOR,
    $etablissement['piece_justificative']
);

$file=realpath(
    $projectRoot.
    DIRECTORY_SEPARATOR.
    ltrim(
        $relativePath,
        DIRECTORY_SEPARATOR
    )
);


if(!$file || !is_file($file)){

    http_response_code(404);

    exit(
        'Le fichier physique est introuvable.'
    );
}


/* =========================================================
   SÉCURITÉ : DOSSIER AUTORISÉ
========================================================= */

$uploadDirectory=realpath(
    $projectRoot.
    DIRECTORY_SEPARATOR.
    'uploads'.
    DIRECTORY_SEPARATOR.
    'etablissements'
);


if(!$uploadDirectory){

    http_response_code(500);

    exit(
        'Le dossier uploads/etablissements est introuvable.'
    );
}


if(
    strpos(
        $file,
        $uploadDirectory.
        DIRECTORY_SEPARATOR
    )!==0
){

    http_response_code(403);

    exit(
        'Accès au document refusé.'
    );
}


/* =========================================================
   TYPE MIME
========================================================= */

$finfo=new finfo(
    FILEINFO_MIME_TYPE
);

$mime=$finfo->file($file);


$extensions=[

    'application/pdf'=>'pdf',
    'image/jpeg'=>'jpg',
    'image/png'=>'png'

];


if(!isset($extensions[$mime])){

    http_response_code(403);

    exit(
        'Type de fichier non autorisé.'
    );
}


/* =========================================================
   NOM D'AFFICHAGE
========================================================= */

$safeName=preg_replace(
    '/[^a-zA-Z0-9_-]+/',
    '-',
    $etablissement['nom']
);

$filename=
    'piece-justificative-'.
    trim($safeName,'-').
    '.'.
    $extensions[$mime];


/* =========================================================
   AFFICHER DANS LE NAVIGATEUR
========================================================= */

header(
    'Content-Type: '.$mime
);

header(
    'Content-Length: '.filesize($file)
);

header(
    'Content-Disposition: inline; filename="'.
    $filename.
    '"'
);

header(
    'X-Content-Type-Options: nosniff'
);

header(
    'Cache-Control: private, no-store, max-age=0'
);

readfile($file);

exit;
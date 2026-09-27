<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

$userId=(int)($_SESSION['user_id']??0);

$token=trim($_GET['token']??'');

$download=
    isset($_GET['download']) &&
    $_GET['download']=='1';


if(!$token){
    http_response_code(404);
    exit('Document introuvable.');
}


/* =========================================================
   DOCUMENT + PROPRIÉTAIRE
========================================================= */
$stmt=$pdo->prepare("
    SELECT
        d.*,
        sp.user_id

    FROM student_personal_documents d

    INNER JOIN student_profiles sp
        ON sp.id=d.student_id

    WHERE d.uuid=?
      AND sp.user_id=?

    LIMIT 1
");

$stmt->execute([
    $token,
    $userId
]);

$document=$stmt->fetch(PDO::FETCH_ASSOC);


if(!$document){

    http_response_code(404);
    exit('Document introuvable.');
}


/* =========================================================
   FICHIER
========================================================= */
$projectRoot=
    realpath(__DIR__.'/../..');

$storageRoot=
    realpath(
        $projectRoot.
        '/storage/student-documents'
    );

$filePath=
    realpath(
        $projectRoot.'/'.
        $document['chemin']
    );


if(
    !$storageRoot ||
    !$filePath ||
    strpos($filePath,$storageRoot)!==0 ||
    !is_file($filePath)
){
    http_response_code(404);
    exit('Fichier introuvable.');
}


$filename=
    preg_replace(
        '/[\r\n"]+/',
        '',
        $document['nom_original']
    );


header(
    'Content-Type: '.
    $document['mime_type']
);

header(
    'Content-Length: '.
    filesize($filePath)
);

header(
    'Content-Disposition: '.
    ($download?'attachment':'inline').
    '; filename="'.
    $filename.'"'
);

header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
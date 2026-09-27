<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
$id=(int)($_GET['id']??0);

$stmt=$pdo->prepare("
    SELECT sd.*
    FROM student_documents sd
    JOIN student_enrollments se ON se.id=sd.enrollment_id
    WHERE sd.id=?
      AND se.etablissement_id=?
      AND sd.statut='ACTIF'
    LIMIT 1
");
$stmt->execute([$id,$etablissementId]);
$document=$stmt->fetch();

if(!$document){
    http_response_code(404);
    exit('Document introuvable.');
}

$file=__DIR__.'/../../storage/'.$document['chemin'];

if(!is_file($file)){
    http_response_code(404);
    exit('Fichier introuvable.');
}

header('Content-Type: '.$document['mime_type']);
header('Content-Length: '.filesize($file));
header(
    'Content-Disposition: attachment; filename="'.
    basename($document['nom_fichier']).'"'
);

readfile($file);
exit;
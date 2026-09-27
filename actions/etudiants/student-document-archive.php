<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);

    if(!$etablissementId || !$id)
        jsonResponse(false,'Document invalide.',[],422);

    /* Vérifier que le document appartient bien à cet établissement */
    $stmt=$pdo->prepare("
        SELECT sd.id
        FROM student_documents sd
        JOIN student_enrollments se ON se.id=sd.enrollment_id
        WHERE sd.id=?
          AND se.etablissement_id=?
          AND sd.statut='ACTIF'
        LIMIT 1
    ");
    $stmt->execute([$id,$etablissementId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Document introuvable.',[],404);

    $pdo->prepare("
        UPDATE student_documents
        SET statut='ARCHIVE'
        WHERE id=?
    ")->execute([$id]);

    jsonResponse(true,'Document archivé avec succès.');

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}
<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['STAGIAIRE']);

try{

    /* CSRF */
    $csrf=$_POST['csrf']??'';

    if(
        empty($_SESSION['csrf']) ||
        !$csrf ||
        !hash_equals($_SESSION['csrf'],$csrf)
    ){
        jsonResponse(false,'Jeton de sécurité invalide.',[],419);
    }


    $userId=(int)($_SESSION['user_id']??0);

    $uuid=trim($_POST['uuid']??'');


    if(!$uuid)
        jsonResponse(false,'Document invalide.',[],422);


    /* =====================================================
       DOCUMENT APPARTENANT À L'ÉTUDIANT
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            d.id,
            d.chemin

        FROM student_personal_documents d

        INNER JOIN student_profiles sp
            ON sp.id=d.student_id

        WHERE d.uuid=?
          AND sp.user_id=?

        LIMIT 1
    ");

    $stmt->execute([
        $uuid,
        $userId
    ]);

    $document=$stmt->fetch(PDO::FETCH_ASSOC);


    if(!$document)
        jsonResponse(false,'Document introuvable.',[],404);


    /* =====================================================
       SUPPRIMER BASE
    ====================================================== */
    $stmt=$pdo->prepare("
        DELETE FROM student_personal_documents
        WHERE id=?
    ");

    $stmt->execute([
        $document['id']
    ]);


    /* =====================================================
       SUPPRIMER FICHIER
    ====================================================== */
    $projectRoot=
        realpath(__DIR__.'/../..');

    $file=
        $projectRoot.'/'.
        $document['chemin'];


    if(is_file($file))
        @unlink($file);


    jsonResponse(
        true,
        'Document supprimé avec succès.'
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur suppression : '.$e->getMessage(),
        [],
        500
    );
}
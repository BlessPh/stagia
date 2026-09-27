<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $promotionId=(int)($_POST['promotion_id']??0);
    $nom=trim($_POST['nom']??'');
    $credits=$_POST['credits']!==''?(float)$_POST['credits']:null;
    $coefficient=(float)($_POST['coefficient']??1);
    $noteMax=(float)($_POST['note_max']??20);

    if(!$id || !$promotionId || !$nom)
        jsonResponse(false,'Données obligatoires manquantes.',[],422);

    $stmt=$pdo->prepare("
        SELECT id FROM promotions
        WHERE id=? AND etablissement_id=? AND actif=1
    ");
    $stmt->execute([$promotionId,$etablissementId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Promotion invalide.',[],422);

    $stmt=$pdo->prepare("
        UPDATE matieres
        SET promotion_id=?,nom=?,credits=?,coefficient=?,note_max=?
        WHERE id=? AND etablissement_id=?
    ");

    $stmt->execute([
        $promotionId,$nom,$credits,$coefficient,$noteMax,
        $id,$etablissementId
    ]);

    if(!$stmt->rowCount()){
        $check=$pdo->prepare("SELECT id FROM matieres WHERE id=? AND etablissement_id=?");
        $check->execute([$id,$etablissementId]);
        if(!$check->fetch()) jsonResponse(false,'Matière introuvable.',[],404);
    }

    jsonResponse(true,'Matière modifiée avec succès.');

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}
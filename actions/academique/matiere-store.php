<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);
    $promotionId=(int)($_POST['promotion_id']??0);
    $nom=trim($_POST['nom']??'');
    $credits=$_POST['credits']!==''?(float)$_POST['credits']:null;
    $coefficient=(float)($_POST['coefficient']??1);
    $noteMax=(float)($_POST['note_max']??20);

    if(!$etablissementId || !$promotionId || !$nom)
        jsonResponse(false,'Promotion et nom obligatoires.',[],422);

    if($coefficient<=0 || $noteMax<=0)
        jsonResponse(false,'Coefficient ou note maximale invalide.',[],422);

    /* Promotion de cet établissement */
    $stmt=$pdo->prepare("
        SELECT id FROM promotions
        WHERE id=? AND etablissement_id=? AND actif=1
    ");
    $stmt->execute([$promotionId,$etablissementId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Promotion invalide.',[],422);

    $pdo->beginTransaction();

    $stmt=$pdo->prepare("
        INSERT INTO matieres(
            etablissement_id,promotion_id,nom,
            credits,coefficient,note_max
        )
        VALUES(?,?,?,?,?,?)
    ");

    $stmt->execute([
        $etablissementId,$promotionId,$nom,
        $credits,$coefficient,$noteMax
    ]);

    $id=(int)$pdo->lastInsertId();
    $code='MAT-'.str_pad($id,4,'0',STR_PAD_LEFT);

    $pdo->prepare("
        UPDATE matieres
        SET code=?
        WHERE id=? AND etablissement_id=?
    ")->execute([$code,$id,$etablissementId]);

    $pdo->commit();

    jsonResponse(true,'Matière ajoutée avec succès.',[
        'id'=>$id,
        'code'=>$code
    ]);

}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}
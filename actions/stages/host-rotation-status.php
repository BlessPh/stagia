<?php
/**
 * Endpoint AJAX de clôture anticipée ou d'annulation d'une rotation.
 * Les transitions sont strictement limitées au statut actuel de la rotation.
 */
if(session_status()!==PHP_SESSION_ACTIVE)
    session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Seul l'accueil peut modifier les états administratifs d'une rotation. */
requireAjaxRole(['ADMIN_ACCUEIL']);

$csrf=$_POST['csrf']??'';

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals($_SESSION['csrf'],$csrf)
){
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);
}

try{
    /* La rotation est verrouillée avant de tester l'action demandée. */
    $hostId=currentEtablissementId($pdo);
    $id=(int)($_POST['rotation_id']??0);
    $action=strtoupper(trim($_POST['action']??''));

    if(!$hostId || !$id)
        jsonResponse(false,'Rotation invalide.',[],422);

    $pdo->beginTransaction();

    $stmt=$pdo->prepare("
        SELECT id,statut
        FROM stage_rotations
        WHERE id=?
          AND host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$id,$hostId]);
    $rotation=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$rotation)
        throw new RuntimeException('Rotation introuvable.');


    /* L'annulation n'est possible que pour une rotation encore planifiée. */
    if(
        $action==='ANNULER' &&
        $rotation['statut']==='PLANIFIEE'
    ){
        $stmt=$pdo->prepare("
            UPDATE stage_rotations
            SET statut='ANNULEE',
                cancelled_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$id]);

        $message='Rotation annulée.';

    }elseif(
        $action==='TERMINER' &&
        $rotation['statut']==='ACTIVE'
    ){
        $stmt=$pdo->prepare("
            UPDATE stage_rotations
            SET statut='TERMINEE',
                date_fin=LEAST(date_fin,CURDATE()),
                ended_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$id]);

        $message='Rotation terminée.';

    }else{
        throw new RuntimeException(
            'Cette opération n’est pas autorisée.'
        );
    }


    /* La transition est validée seulement après l'écriture correspondante. */
    $pdo->commit();

    jsonResponse(true,$message);


}catch(Throwable $e){

    if(isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(false,$e->getMessage(),[],422);
}

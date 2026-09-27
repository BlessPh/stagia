<?php
/**
 * Endpoint AJAX de modification des champs éditables d'une convention brouillon.
 * L'établissement de formation reste le seul propriétaire fonctionnel de cette action.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/stage-convention.php';

verifyAjaxCsrf();

try{
    /* Validation de la demande avant le verrouillage de la convention ciblée. */
    requireConventionAjax();
    $id=(int)($_POST['id']??0);
    $titre=trim((string)($_POST['titre']??''));
    $dateEmission=trim((string)($_POST['date_emission']??''));

    if(!$id||$titre==='')throw new RuntimeException('Données invalides.');
    if(mb_strlen($titre)>200)throw new RuntimeException('Titre trop long.');

    /* Le verrou garantit que le statut ne change pas pendant la modification. */
    $pdo->beginTransaction();
    $x=conventionLoad($pdo,$id,true);
    if(!conventionIsUniversityOwner($x))
        throw new RuntimeException("Seul l'établissement de formation peut modifier ce brouillon.");
    if($x['statut']!=='BROUILLON')
        throw new RuntimeException('Seule une convention en brouillon peut être modifiée.');

    /* Seuls le titre et la date d'émission sont modifiables tant que le brouillon existe. */
    $pdo->prepare("
        UPDATE stage_conventions
        SET titre=?,date_emission=?
        WHERE id=?
    ")->execute([$titre,$dateEmission!==''?$dateEmission:null,$id]);

    $pdo->commit();
    jsonResponse(true,'Convention mise à jour.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

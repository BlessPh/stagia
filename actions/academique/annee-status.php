<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=(int)currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $currentYear=(int)date('Y');

    if(!$etablissementId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    if(!$id)
        jsonResponse(false,'Année académique invalide.',[],422);

    $stmt=$pdo->prepare("
        SELECT id,libelle,date_debut,date_fin,actif
        FROM annees_academiques
        WHERE id=? AND etablissement_id=?
        LIMIT 1
    ");
    $stmt->execute([$id,$etablissementId]);
    $annee=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$annee)
        jsonResponse(false,'Année académique introuvable.',[],404);

    $startYear=(int)substr((string)$annee['date_debut'],0,4);

    if($startYear!==$currentYear)
        jsonResponse(false,'Les anciennes années sont disponibles uniquement en consultation.',[],403);

    $actif=(int)$annee['actif']===1?0:1;

    $pdo->prepare("
        UPDATE annees_academiques
        SET actif=?
        WHERE id=? AND etablissement_id=?
    ")->execute([$actif,$id,$etablissementId]);

    jsonResponse(
        true,
        $actif?'Année académique activée.':'Année académique désactivée.'
    );

}catch(Throwable $e){
    error_log('[ANNEE STATUS] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Impossible de modifier le statut de l’année académique.',[],500);
}

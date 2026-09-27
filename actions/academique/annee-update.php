<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=(int)currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $debut=trim((string)($_POST['date_debut']??''));
    $fin=trim((string)($_POST['date_fin']??''));
    $currentYear=(int)date('Y');
    $today=date('Y-m-d');

    if(!$etablissementId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    if(!$id||$debut===''||$fin==='')
        jsonResponse(false,'Les dates de début et de fin sont obligatoires.',[],422);

    $validDate=function(string $date):bool{
        $d=DateTime::createFromFormat('Y-m-d',$date);
        return $d&&$d->format('Y-m-d')===$date;
    };

    if(!$validDate($debut)||!$validDate($fin))
        jsonResponse(false,'Format de date invalide.',[],422);

    $stmt=$pdo->prepare("
        SELECT id,libelle,date_debut,date_fin
        FROM annees_academiques
        WHERE id=? AND etablissement_id=?
        LIMIT 1
    ");
    $stmt->execute([$id,$etablissementId]);
    $annee=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$annee)
        jsonResponse(false,'Année académique introuvable.',[],404);

    $storedStartYear=(int)substr((string)$annee['date_debut'],0,4);
    if($storedStartYear!==$currentYear)
        jsonResponse(false,'Les anciennes années sont disponibles uniquement en consultation.',[],403);

    if($fin<=$debut)
        jsonResponse(false,'La date de fin doit être postérieure à la date de début.',[],422);

    $startYear=(int)substr($debut,0,4);
    $endYear=(int)substr($fin,0,4);

    if($startYear!==$currentYear)
        jsonResponse(false,'L’année académique doit commencer en '.$currentYear.'.',[],422);

    if($endYear!==$currentYear+1)
        jsonResponse(false,'L’année académique doit se terminer en '.($currentYear+1).'.',[],422);

    if($fin<$today)
        jsonResponse(false,'Impossible d’enregistrer une période déjà terminée.',[],422);

    $libelle=$currentYear.'-'.($currentYear+1);

    $stmt=$pdo->prepare("
        SELECT id
        FROM annees_academiques
        WHERE etablissement_id=? AND libelle=? AND id<>?
        LIMIT 1
    ");
    $stmt->execute([$etablissementId,$libelle,$id]);

    if($stmt->fetchColumn())
        jsonResponse(false,'L’année académique '.$libelle.' existe déjà.',[],409);

    $pdo->prepare("
        UPDATE annees_academiques
        SET libelle=?,date_debut=?,date_fin=?
        WHERE id=? AND etablissement_id=?
    ")->execute([$libelle,$debut,$fin,$id,$etablissementId]);

    jsonResponse(true,'Année académique '.$libelle.' mise à jour avec succès.');

}catch(Throwable $e){
    error_log('[ANNEE UPDATE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Impossible de mettre à jour l’année académique.',[],500);
}

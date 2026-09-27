<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=(int)currentEtablissementId($pdo);
    $debut=trim((string)($_POST['date_debut']??''));
    $fin=trim((string)($_POST['date_fin']??''));
    $currentYear=(int)date('Y');
    $today=date('Y-m-d');

    if(!$etablissementId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    if($debut===''||$fin==='')
        jsonResponse(false,'Les dates de début et de fin sont obligatoires.',[],422);

    $validDate=function(string $date):bool{
        $d=DateTime::createFromFormat('Y-m-d',$date);
        return $d&&$d->format('Y-m-d')===$date;
    };

    if(!$validDate($debut)||!$validDate($fin))
        jsonResponse(false,'Format de date invalide.',[],422);

    if($fin<=$debut)
        jsonResponse(false,'La date de fin doit être postérieure à la date de début.',[],422);

    $startYear=(int)substr($debut,0,4);
    $endYear=(int)substr($fin,0,4);

    if($startYear!==$currentYear)
        jsonResponse(false,'L’année académique doit commencer en '.$currentYear.'.',[],422);

    if($endYear!==$currentYear+1)
        jsonResponse(false,'L’année académique doit se terminer en '.($currentYear+1).'.',[],422);

    if($fin<$today)
        jsonResponse(false,'Impossible de créer une année académique déjà terminée.',[],422);

    $libelle=$currentYear.'-'.($currentYear+1);

    $stmt=$pdo->prepare("
        SELECT id
        FROM annees_academiques
        WHERE etablissement_id=? AND libelle=?
        LIMIT 1
    ");
    $stmt->execute([$etablissementId,$libelle]);

    if($stmt->fetchColumn())
        jsonResponse(false,'L’année académique '.$libelle.' existe déjà.',[],409);

    $pdo->prepare("
        INSERT INTO annees_academiques(
            etablissement_id,libelle,date_debut,date_fin,actif
        ) VALUES(?,?,?,?,1)
    ")->execute([$etablissementId,$libelle,$debut,$fin]);

    jsonResponse(
        true,
        $debut>$today
            ?'Année académique '.$libelle.' préparée avec succès.'
            :'Année académique '.$libelle.' enregistrée avec succès.',
        ['id'=>(int)$pdo->lastInsertId(),'libelle'=>$libelle]
    );

}catch(Throwable $e){
    error_log('[ANNEE STORE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Impossible d’enregistrer l’année académique.',[],500);
}

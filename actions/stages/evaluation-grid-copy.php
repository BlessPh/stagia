<?php
/**
 * Endpoint AJAX de lecture des critères d'une grille existante afin de les copier dans une nouvelle configuration.
 * La configuration source doit être active et appartenir à l'établissement de session.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

try{
    /* Le référentiel source est retrouvé à travers une configuration active, dans le périmètre établissement. */
    $eid=(int)currentEtablissementId($pdo);
    $id=(int)($_GET['config_id']??0);
    if(!$eid||!$id)jsonResponse(false,'Configuration invalide.',[],422);

    /* Les critères sont retournés avec tous les paramètres nécessaires à leur duplication. */
    $s=$pdo->prepare("
        SELECT referential_id
        FROM promotion_stage_configs
        WHERE id=? AND etablissement_id=? AND actif=1
        LIMIT 1
    ");
    $s->execute([$id,$eid]);$refId=(int)$s->fetchColumn();
    if(!$refId)jsonResponse(false,'Cette configuration ne possède pas de grille.',[],422);

    $s=$pdo->prepare("
        SELECT c.id,c.code,c.nom,c.categorie,c.description,
               rc.poids,rc.note_max,rc.section,rc.obligatoire,rc.ordre
        FROM stage_referential_competencies rc
        JOIN stage_competencies c ON c.id=rc.competency_id AND c.actif=1
        WHERE rc.referential_id=?
        ORDER BY rc.ordre,c.categorie,c.nom
    ");
    $s->execute([$refId]);
    jsonResponse(true,'Grille copiée.',['criteria'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
}catch(Throwable $e){
    jsonResponse(false,'Impossible de copier la grille : '.$e->getMessage(),[],500);
}

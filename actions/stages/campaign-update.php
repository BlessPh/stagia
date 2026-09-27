<?php
/**
 * Endpoint AJAX de modification d'une campagne encore préparatoire.
 * Les campagnes ouvertes ou terminées sont volontairement protégées de toute modification.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Modification limitée aux responsables de l'établissement propriétaire. */
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    /* Lecture et validation des champs de la campagne et de ses promotions. */
    $etablissementId=currentEtablissementId($pdo);

    $id=(int)($_POST['id']??0);
    $stageTypeId=(int)($_POST['stage_type_id']??0);
    $anneeId=(int)($_POST['annee_academique_id']??0);
    $titre=trim($_POST['titre']??'');
    $description=trim($_POST['description']??'');

    $dateDebut=$_POST['date_debut']??'';
    $dateFin=$_POST['date_fin']??'';

    $ouverture=$_POST['ouverture_candidatures']??'';
    $cloture=$_POST['cloture_candidatures']??'';

    $promotionIds=array_values(array_unique(array_map(
        'intval',
        $_POST['promotion_ids']??[]
    )));

    if(!$id || !$stageTypeId || !$anneeId || !$titre || !$dateDebut || !$dateFin || !$promotionIds)
        jsonResponse(false,'Informations obligatoires manquantes.',[],422);

    if($dateFin<$dateDebut)
        jsonResponse(false,'Période de stage invalide.',[],422);

    if($ouverture && $cloture && $cloture<$ouverture)
        jsonResponse(false,'Période de candidature invalide.',[],422);

    /* Campagne modifiable ? */
    $stmt=$pdo->prepare("
        SELECT statut
        FROM stage_campaigns
        WHERE id=?
          AND owner_etablissement_id=?
          AND type_campagne='UNIVERSITAIRE'
    ");
    $stmt->execute([$id,$etablissementId]);
    $campaign=$stmt->fetch();

    if(!$campaign)
        jsonResponse(false,'Campagne introuvable.',[],404);

    if(!in_array($campaign['statut'],['BROUILLON','EN_PREPARATION'],true))
        jsonResponse(false,'Cette campagne ne peut plus être modifiée.',[],409);

    /* Type */
    $stmt=$pdo->prepare("SELECT id FROM stage_types WHERE id=? AND actif=1");
    $stmt->execute([$stageTypeId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Type de stage invalide.',[],422);

    /* Année */
    $stmt=$pdo->prepare("
        SELECT id FROM annees_academiques
        WHERE id=? AND etablissement_id=?
    ");
    $stmt->execute([$anneeId,$etablissementId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Année académique invalide.',[],422);

    /* Promotions */
    $ph=implode(',',array_fill(0,count($promotionIds),'?'));

    $stmt=$pdo->prepare("
        SELECT COUNT(*)
        FROM promotions
        WHERE etablissement_id=?
          AND id IN ($ph)
    ");
    $stmt->execute(array_merge([$etablissementId],$promotionIds));

    if((int)$stmt->fetchColumn()!==count($promotionIds))
        jsonResponse(false,'Promotion invalide.',[],422);

    /* Les informations de campagne et ses liaisons promotions sont mises à jour ensemble. */
    $pdo->beginTransaction();

    /* Mise à jour de l'entête, puis remplacement des associations de promotions. */
    $pdo->prepare("
        UPDATE stage_campaigns
        SET stage_type_id=?,
            annee_academique_id=?,
            titre=?,
            description=?,
            date_debut=?,
            date_fin=?,
            ouverture_candidatures=?,
            cloture_candidatures=?
        WHERE id=? AND owner_etablissement_id=?
    ")->execute([
        $stageTypeId,$anneeId,$titre,$description?:null,
        $dateDebut,$dateFin,$ouverture?:null,$cloture?:null,
        $id,$etablissementId
    ]);

    $pdo->prepare("
        DELETE FROM stage_campaign_promotions
        WHERE campaign_id=?
    ")->execute([$id]);

    $stmt=$pdo->prepare("
        INSERT INTO stage_campaign_promotions(campaign_id,promotion_id)
        VALUES(?,?)
    ");

    foreach($promotionIds as $promotionId)
        $stmt->execute([$id,$promotionId]);

    $pdo->commit();

    jsonResponse(true,'Campagne modifiée avec succès.');

}catch(Throwable $e){
    /* Retour arrière complet si une validation ou une écriture échoue. */

    if($pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

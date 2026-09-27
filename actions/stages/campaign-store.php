<?php
/**
 * Endpoint AJAX de création d'une campagne universitaire en état brouillon.
 * La campagne et ses promotions sont créées dans une transaction unique.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Création limitée aux administrateurs d'établissement et responsables pédagogiques. */
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

/** Produit un UUID v4 pour identifier la campagne indépendamment de son identifiant numérique. */
function uuidV4(){
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    /* Données de calendrier, type de stage et promotions reçues depuis le formulaire. */
    $etablissementId=currentEtablissementId($pdo);

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

    if(!$etablissementId || !$stageTypeId || !$anneeId || !$titre || !$dateDebut || !$dateFin)
        jsonResponse(false,'Informations obligatoires manquantes.',[],422);

    if($dateFin<$dateDebut)
        jsonResponse(false,'La date de fin doit être postérieure à la date de début.',[],422);

    if($ouverture && $cloture && $cloture<$ouverture)
        jsonResponse(false,'La clôture des candidatures est invalide.',[],422);

    if(!$promotionIds)
        jsonResponse(false,'Sélectionnez au moins une promotion.',[],422);

    /* Type */
    $stmt=$pdo->prepare("
        SELECT id
        FROM stage_types
        WHERE id=? AND actif=1
    ");
    $stmt->execute([$stageTypeId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Type de stage invalide.',[],422);

    /* Année */
    $stmt=$pdo->prepare("
        SELECT id
        FROM annees_academiques
        WHERE id=? AND etablissement_id=?
    ");
    $stmt->execute([$anneeId,$etablissementId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Année académique invalide.',[],422);

    /* Vérifier toutes les promotions */
    $ph=implode(',',array_fill(0,count($promotionIds),'?'));

    $stmt=$pdo->prepare("
        SELECT id
        FROM promotions
        WHERE etablissement_id=?
          AND id IN ($ph)
    ");
    $stmt->execute(array_merge([$etablissementId],$promotionIds));

    $valid=array_map('intval',array_column($stmt->fetchAll(),'id'));

    sort($valid);
    $expected=$promotionIds;
    sort($expected);

    if($valid!==$expected)
        jsonResponse(false,'Une promotion sélectionnée est invalide.',[],422);

    /* L'entête de campagne, son code et ses promotions doivent rester cohérents. */
    $pdo->beginTransaction();

    $stmt=$pdo->prepare("
        INSERT INTO stage_campaigns(
            uuid,stage_type_id,owner_etablissement_id,
            annee_academique_id,type_campagne,
            titre,description,date_debut,date_fin,
            ouverture_candidatures,cloture_candidatures,
            statut,created_by_user_id
        )
        VALUES(
            ?,?,?,?,'UNIVERSITAIRE',
            ?,?,?,?,?,
            ?,'BROUILLON',?
        )
    ");

    $stmt->execute([
        uuidV4(),
        $stageTypeId,
        $etablissementId,
        $anneeId,
        $titre,
        $description?:null,
        $dateDebut,
        $dateFin,
        $ouverture?:null,
        $cloture?:null,
        $_SESSION['user_id']
    ]);

    /* Le code lisible est construit à partir de l'identifiant attribué par la base. */
    $id=(int)$pdo->lastInsertId();
    $code='CAM-'.str_pad($id,6,'0',STR_PAD_LEFT);

    $pdo->prepare("
        UPDATE stage_campaigns
        SET code=?
        WHERE id=?
    ")->execute([$code,$id]);

    $stmt=$pdo->prepare("
        INSERT INTO stage_campaign_promotions(campaign_id,promotion_id)
        VALUES(?,?)
    ");

    /* Association de toutes les promotions validées à la nouvelle campagne. */
    foreach($promotionIds as $promotionId)
        $stmt->execute([$id,$promotionId]);

    $pdo->commit();

    jsonResponse(true,'Campagne créée avec succès.',[
        'id'=>$id,
        'code'=>$code
    ]);

}catch(Throwable $e){
    /* Toute erreur annule la création complète afin d'éviter une campagne partielle. */

    if($pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);

    $campaignId=(int)($_POST['campaign_id']??0);
    $hopitalId=(int)($_POST['host_etablissement_id']??0);
    $conditions=trim($_POST['conditions']??'');

    if(!$campaignId || !$hopitalId)
        jsonResponse(false,'Campagne et hôpital obligatoires.',[],422);

    /* Campagne universitaire D4 */
    $stmt=$pdo->prepare("
        SELECT c.id,c.date_debut,c.date_fin,c.statut,
               st.code stage_type_code
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        WHERE c.id=?
          AND c.owner_etablissement_id=?
          AND c.type_campagne='UNIVERSITAIRE'
    ");
    $stmt->execute([$campaignId,$etablissementId]);
    $campaign=$stmt->fetch();

    if(!$campaign)
        jsonResponse(false,'Campagne introuvable.',[],404);

    if($campaign['stage_type_code']!=='MEDICAL_D4')
        jsonResponse(false,'Les sollicitations hospitalières de cette étape concernent les campagnes D4.',[],422);

    if(!in_array($campaign['statut'],['BROUILLON','EN_PREPARATION'],true))
        jsonResponse(false,'Cette campagne ne peut plus recevoir de nouvelles sollicitations.',[],409);

    /* Hôpital actif */
    $stmt=$pdo->prepare("
        SELECT id,nom
        FROM etablissements
        WHERE id=?
          AND type_etablissement='HOPITAL'
          AND statut IN('VALIDE','ACTIF')
        LIMIT 1
    ");
    $stmt->execute([$hopitalId]);

    if(!$stmt->fetch())
        jsonResponse(false,'Hôpital invalide ou non actif dans STAGIA.',[],422);

    /* Pas de doublon */
    $stmt=$pdo->prepare("
        SELECT id
        FROM stage_campaign_participations
        WHERE university_campaign_id=?
          AND host_etablissement_id=?
        LIMIT 1
    ");
    $stmt->execute([$campaignId,$hopitalId]);

    if($stmt->fetch())
        jsonResponse(false,'Cet hôpital a déjà été sollicité.',[],409);

    $stmt=$pdo->prepare("
        INSERT INTO stage_campaign_participations(
            university_campaign_id,
            host_etablissement_id,
            statut,
            date_debut,
            date_fin,
            conditions
        )
        VALUES(?,?,'SOLLICITEE',?,?,?)
    ");

    $stmt->execute([
        $campaignId,
        $hopitalId,
        $campaign['date_debut'],
        $campaign['date_fin'],
        $conditions?:null
    ]);

    jsonResponse(true,'Hôpital sollicité avec succès.',[
        'id'=>(int)$pdo->lastInsertId()
    ]);

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}
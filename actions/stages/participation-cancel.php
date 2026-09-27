<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);
    $id=(int)($_POST['id']??0);

    if(!$id)
        jsonResponse(false,'Sollicitation invalide.',[],422);

    $stmt=$pdo->prepare("
        SELECT sp.id,sp.statut
        FROM stage_campaign_participations sp
        JOIN stage_campaigns c
          ON c.id=sp.university_campaign_id
        WHERE sp.id=?
          AND c.owner_etablissement_id=?
          AND c.type_campagne='UNIVERSITAIRE'
    ");
    $stmt->execute([$id,$etablissementId]);
    $participation=$stmt->fetch();

    if(!$participation)
        jsonResponse(false,'Sollicitation introuvable.',[],404);

    if($participation['statut']!=='SOLLICITEE')
        jsonResponse(
            false,
            'Une sollicitation ayant déjà reçu une réponse ne peut pas être annulée ici.',
            [],
            409
        );

    $pdo->prepare("
        UPDATE stage_campaign_participations
        SET statut='ANNULEE'
        WHERE id=?
    ")->execute([$id]);

    jsonResponse(true,'Sollicitation annulée.');

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}
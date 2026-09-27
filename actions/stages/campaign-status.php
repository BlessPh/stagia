<?php
/**
 * Endpoint AJAX de changement d'état d'une campagne universitaire.
 * Il applique la machine à états et les prérequis propres aux campagnes D4.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Seuls les responsables de l'établissement propriétaire peuvent changer l'état. */
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    /* La campagne est relue dans le périmètre de la session avant toute transition. */
    $etablissementId=currentEtablissementId($pdo);

    $id=(int)($_POST['id']??0);
    $target=$_POST['statut']??'';

    $stmt=$pdo->prepare("
        SELECT c.id,c.statut,c.stage_type_id,
               st.code stage_type_code
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        WHERE c.id=?
          AND c.owner_etablissement_id=?
          AND c.type_campagne='UNIVERSITAIRE'
    ");
    $stmt->execute([$id,$etablissementId]);
    $campaign=$stmt->fetch();

    if(!$campaign)
        jsonResponse(false,'Campagne introuvable.',[],404);

    /* Machine à états : chaque statut définit explicitement ses sorties autorisées. */
    $transitions=[
        'BROUILLON'=>['EN_PREPARATION','ANNULEE'],
        'EN_PREPARATION'=>['BROUILLON','OUVERTE','ANNULEE'],
        'OUVERTE'=>['CLOTUREE','ANNULEE'],
        'CLOTUREE'=>['TERMINEE'],
        'TERMINEE'=>[],
        'ANNULEE'=>[]
    ];

    if(!in_array($target,$transitions[$campaign['statut']]??[],true))
        jsonResponse(false,'Transition de statut non autorisée.',[],409);

    /* Minimum une promotion avant ouverture */
    if($target==='OUVERTE'){

        $stmt=$pdo->prepare("
            SELECT COUNT(*)
            FROM stage_campaign_promotions
            WHERE campaign_id=?
        ");
        $stmt->execute([$id]);

        if(!(int)$stmt->fetchColumn())
            jsonResponse(false,'Ajoutez au moins une promotion avant ouverture.',[],409);

        /*
         * D4 :
         * au moins un hôpital doit avoir accepté avant ouverture
         */
        if($campaign['stage_type_code']==='MEDICAL_D4'){

            $stmt=$pdo->prepare("
                SELECT COUNT(*)
                FROM stage_campaign_participations
                WHERE university_campaign_id=?
                  AND statut='ACCEPTEE'
            ");
            $stmt->execute([$id]);

            if(!(int)$stmt->fetchColumn())
                jsonResponse(
                    false,
                    'Une campagne D4 ne peut pas être ouverte avant l’acceptation d’au moins un hôpital.',
                    [],
                    409
                );
        }
    }

    /* La transition validée est ensuite persistée pour la seule campagne demandée. */
    $pdo->prepare("
        UPDATE stage_campaigns
        SET statut=?
        WHERE id=? AND owner_etablissement_id=?
    ")->execute([$target,$id,$etablissementId]);

    jsonResponse(true,'Statut de la campagne mis à jour.');

}catch(Throwable $e){
    /* Réponse AJAX uniforme pour les erreurs de règle métier ou de persistance. */
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

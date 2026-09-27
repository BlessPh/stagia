<?php
/**
 * Endpoint AJAX de transition d'état d'une campagne d'accueil.
 * La machine à états protège notamment l'ouverture sans capacité ou sans université acceptée.
 */
if(session_status()!==PHP_SESSION_ACTIVE)
    session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* La publication et la clôture d'une campagne relèvent de l'administration d'accueil. */
requireAjaxRole(['ADMIN_ACCUEIL']);


/* CSRF */
$csrf=$_POST['csrf']??'';

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals($_SESSION['csrf'],$csrf)
){
    jsonResponse(
        false,
        'Jeton de sécurité invalide.',
        [],
        419
    );
}


try{
    /* La transition demandée est confrontée à la liste explicite des transitions autorisées. */

    $hostId=currentEtablissementId($pdo);

    $campaignId=
        (int)($_POST['campaign_id']??0);

    $newStatus=
        strtoupper(
            trim($_POST['statut']??'')
        );


    if(!$hostId || !$campaignId)
        jsonResponse(
            false,
            'Campagne invalide.',
            [],
            422
        );


    /* Machine à états de la campagne d'accueil. */
    $transitions=[

        'BROUILLON'=>[
            'EN_PREPARATION',
            'ANNULEE'
        ],

        'EN_PREPARATION'=>[
            'BROUILLON',
            'OUVERTE',
            'ANNULEE'
        ],

        'OUVERTE'=>[
            'CLOTUREE',
            'ANNULEE'
        ],

        'CLOTUREE'=>[
            'TERMINEE'
        ],

        'TERMINEE'=>[],

        'ANNULEE'=>[]
    ];


    /* Le statut courant est verrouillé avant le contrôle des prérequis et la mise à jour. */
    $pdo->beginTransaction();


    $stmt=$pdo->prepare("
        SELECT
            id,
            statut

        FROM stage_campaigns

        WHERE id=?
          AND owner_etablissement_id=?
          AND type_campagne='ACCUEIL'

        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $campaignId,
        $hostId
    ]);

    $campaign=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    if(!$campaign)
        throw new RuntimeException(
            'Campagne d’accueil introuvable.'
        );


    $current=
        $campaign['statut'];


    if(
        !isset($transitions[$current]) ||
        !in_array(
            $newStatus,
            $transitions[$current],
            true
        )
    ){
        throw new RuntimeException(
            'Transition de statut non autorisée.'
        );
    }


    /* =====================================================
       CONTRÔLE AVANT OUVERTURE
    ====================================================== */
    /* Une ouverture exige une capacité non nulle et au moins une université rattachée. */
    if($newStatus==='OUVERTE'){

        /* Capacité */
        $stmt=$pdo->prepare("
            SELECT capacite_totale

            FROM stage_capacity_pools

            WHERE host_campaign_id=?

            LIMIT 1
        ");

        $stmt->execute([
            $campaignId
        ]);

        $capacity=
            (int)$stmt->fetchColumn();


        if($capacity<=0)
            throw new RuntimeException(
                'Configurez une capacité avant d’ouvrir la campagne.'
            );


        /* Au moins une université acceptée */
        $stmt=$pdo->prepare("
            SELECT COUNT(*)

            FROM stage_campaign_participations

            WHERE host_campaign_id=?
              AND host_etablissement_id=?
              AND statut='ACCEPTEE'
        ");

        $stmt->execute([
            $campaignId,
            $hostId
        ]);


        if((int)$stmt->fetchColumn()<=0)
            throw new RuntimeException(
                'Aucune université n’est encore rattachée à cette campagne.'
            );
    }


    $stmt=$pdo->prepare("
        UPDATE stage_campaigns

        SET statut=?

        WHERE id=?
          AND owner_etablissement_id=?
          AND type_campagne='ACCUEIL'
    ");

    $stmt->execute([
        $newStatus,
        $campaignId,
        $hostId
    ]);


    /* La transition est validée uniquement après tous les contrôles de disponibilité. */
    $pdo->commit();


    jsonResponse(
        true,
        'Statut de la campagne mis à jour.',
        [
            'statut'=>$newStatus
        ]
    );


}catch(Throwable $e){

    if(
        isset($pdo) &&
        $pdo->inTransaction()
    ){
        $pdo->rollBack();
    }


    jsonResponse(
        false,
        $e->getMessage(),
        [],
        422
    );
}

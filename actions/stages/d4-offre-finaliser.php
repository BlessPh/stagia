<?php
/**
 * Endpoint AJAX qui retient définitivement l'offre proposée par une structure d'accueil D4.
 * La capacité proposée est reprise telle quelle et l'opération reste idempotente après finalisation.
 */

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

verifyAjaxCsrf();
requirePermission($pdo,'campaign.university.update');

if(!contextAcademicEnabled())
    jsonResponse(
        false,
        "Cet espace n'est pas un établissement de formation.",
        [],
        403
    );

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);

if(!$eid || !$id)
    jsonResponse(
        false,
        'Participation invalide.',
        [],
        422
    );

try{
    /* La participation est verrouillée dans le périmètre de la campagne universitaire propriétaire. */

    $pdo->beginTransaction();

    /* =====================================================
       CHARGER L'OFFRE / PARTICIPATION
       Compatible avec tous les types de stage
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT
            p.id,
            p.university_campaign_id,
            p.host_etablissement_id,
            p.host_campaign_id,
            p.statut,
            p.capacite_proposee,
            p.capacite_acceptee,

            c.code AS campaign_code,
            c.titre AS campaign_title,
            c.statut AS campaign_status,
            c.type_campagne,

            h.nom AS host_name

        FROM stage_campaign_participations p

        INNER JOIN stage_campaigns c
            ON c.id=p.university_campaign_id

        INNER JOIN etablissements h
            ON h.id=p.host_etablissement_id

        WHERE p.id=?
          AND c.owner_etablissement_id=?
          AND c.type_campagne='UNIVERSITAIRE'

        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $id,
        $eid
    ]);

    $p=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$p)
        throw new RuntimeException(
            'Participation hospitalière introuvable.'
        );

    /* =====================================================
       CONTRÔLES
    ====================================================== */

    if($p['statut']!=='ACCEPTEE')
        throw new RuntimeException(
            "L'établissement d'accueil n'a pas encore accepté cette sollicitation."
        );

    $proposee=(int)($p['capacite_proposee']??0);
    $acceptee=(int)($p['capacite_acceptee']??0);

    if($proposee<=0)
        throw new RuntimeException(
            "Aucune capacité valide n'a été proposée par l'établissement d'accueil."
        );

    /* Idempotence */
    /* Une offre déjà retenue renvoie son résultat sans effectuer une seconde écriture. */
    if($acceptee>0){

        $pdo->commit();

        jsonResponse(
            true,
            'Cette offre est déjà retenue.',
            [
                'id'=>$id,
                'capacite_acceptee'=>$acceptee
            ]
        );
    }

    if(in_array(
        $p['campaign_status'],
        ['TERMINEE','ANNULEE'],
        true
    ))
        throw new RuntimeException(
            'Cette campagne ne permet plus de retenir une offre.'
        );

    /* =====================================================
       RETENIR EXACTEMENT LA CAPACITÉ PROPOSÉE
    ====================================================== */

    $stmt=$pdo->prepare("
        UPDATE stage_campaign_participations

        SET
            capacite_acceptee=capacite_proposee,
            updated_at=NOW()

        WHERE id=?
          AND statut='ACCEPTEE'
          AND capacite_proposee IS NOT NULL
          AND capacite_proposee>0
          AND capacite_acceptee IS NULL
    ");

    $stmt->execute([$id]);

    if($stmt->rowCount()!==1)
        throw new RuntimeException(
            "Impossible de retenir cette offre."
        );

    $pdo->commit();

    jsonResponse(
        true,
        "Offre de {$p['host_name']} retenue : {$proposee} place(s).",
        [
            'id'=>$id,
            'campaign_id'=>(int)$p['university_campaign_id'],
            'host_etablissement_id'=>(int)$p['host_etablissement_id'],
            'capacite_acceptee'=>$proposee
        ]
    );

}catch(Throwable $e){
    /* Toute erreur laisse la capacité acceptée inchangée grâce au rollback. */

    if($pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(
        false,
        $e->getMessage(),
        [],
        422
    );
}

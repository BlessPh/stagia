<?php
/**
 * Endpoint AJAX d'annulation motivée d'une sollicitation hospitalière D4 encore en instruction.
 * L'annulation est tracée dans l'historique des participations sans suppression physique.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-d4-participation.php';

requirePermission($pdo,'campaign.d4.cancel');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$uid=(int)($_SESSION['user_id']??0)?:null;
$id=(int)($_POST['id']??0);
$reason=trim((string)($_POST['reason']??''));

if(!$eid||!$id||$reason==='')jsonResponse(false,"Le motif d'annulation est obligatoire.",[],422);

try{
    /* La participation est verrouillée et vérifiée comme appartenant à l'université connectée. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT p.id,p.statut
        FROM stage_campaign_participations p
        JOIN stage_campaigns c ON c.id=p.university_campaign_id
        WHERE p.id=? AND c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE'
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$id,$eid]);$p=$s->fetch(PDO::FETCH_ASSOC);

    if(!$p)throw new RuntimeException('Sollicitation introuvable.');
    if(!in_array($p['statut'],['SOLLICITEE','EN_ETUDE'],true))
        throw new RuntimeException("Cette sollicitation ne peut plus être annulée à ce stade.");

    $pdo->prepare("
        UPDATE stage_campaign_participations
        SET statut='ANNULEE',cancelled_by_user_id=?,cancelled_at=NOW(),cancellation_reason=?
        WHERE id=?
    ")->execute([$uid,$reason,$id]);

    /* L'historique conserve l'ancien statut, le nouveau statut et le motif de l'opération. */
    d4ParticipationHistory($pdo,$id,'ANNULATION',$p['statut'],'ANNULEE',['motif'=>$reason],$uid);

    $pdo->commit();
    jsonResponse(true,'Sollicitation annulée.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

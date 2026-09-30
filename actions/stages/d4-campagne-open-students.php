<?php
/**
 * Endpoint AJAX d'ouverture d'une campagne universitaire D4 aux étudiants.
 * Une offre hospitalière retenue avec une capacité positive est obligatoire avant la publication.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';

try{
    /* La campagne est relue dans le périmètre de l'établissement académique de session. */
    requirePermission($pdo,'campaign.university.update');
    verifyAjaxCsrf();

    $eid=(int)($_SESSION['etablissement_id']??0);
    $cid=(int)($_POST['campaign_id']??0);

    if(!$eid||!$cid)jsonResponse(false,'Session introuvable.');

    $s=$pdo->prepare("
        SELECT id,statut
        FROM stage_campaigns
        WHERE id=? AND owner_etablissement_id=? AND type_campagne='UNIVERSITAIRE'
        LIMIT 1
    ");
    $s->execute([$cid,$eid]);
    $c=$s->fetch(PDO::FETCH_ASSOC);

    if(!$c)jsonResponse(false,'Session introuvable.');
    if($c['statut']==='OUVERTE')jsonResponse(true,'Session déjà ouverte aux étudiants.');
    /* Seule une session en préparation peut être publiée. */
    if($c['statut']!=='EN_PREPARATION')jsonResponse(false,'Cette session ne peut pas être ouverte maintenant.');

    if(!campaignHasAcceptedHostCapacity($pdo,$cid))
        jsonResponse(false,'Aucune offre retenue pour ouvrir la session.');

    $s=$pdo->prepare("
        UPDATE stage_campaigns
        SET statut='OUVERTE',published_at=COALESCE(published_at,NOW())
        WHERE id=? AND owner_etablissement_id=?
    ");
    $s->execute([$cid,$eid]);

    jsonResponse(true,'Session publiée aux étudiants.');

}catch(Throwable $e){
    error_log('[OPEN STUDENTS] '.$e->getMessage());
    jsonResponse(false,$e->getMessage());
}

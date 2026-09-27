<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-d4-participation.php';

requirePermission($pdo,'campaign.hosting.respond');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);

if(!$eid||!$id)jsonResponse(false,'Sollicitation invalide.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT id,statut
        FROM stage_campaign_participations
        WHERE id=?
          AND host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id,$eid]);
    $p=$s->fetch(PDO::FETCH_ASSOC);

    if(!$p)throw new RuntimeException('Sollicitation introuvable.');
    if($p['statut']!=='SOLLICITEE')
        throw new RuntimeException("Cette sollicitation n'est plus nouvelle.");

    $pdo->prepare("
        UPDATE stage_campaign_participations
        SET statut='EN_ETUDE'
        WHERE id=?
    ")->execute([$id]);

    d4ParticipationHistory(
        $pdo,$id,'MISE_EN_ETUDE',
        'SOLLICITEE','EN_ETUDE',[],
        (int)($_SESSION['user_id']??0)?:null
    );

    $pdo->commit();
    jsonResponse(true,'Sollicitation mise en étude.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

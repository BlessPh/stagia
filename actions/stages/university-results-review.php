<?php
/**
 * Endpoint AJAX du cycle universitaire de réception, validation et archivage d'un relevé de résultats.
 * Chaque action est limitée au statut courant de la transmission verrouillée.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-results.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* La transmission est verrouillée dans le périmètre de l'université avant toute transition. */
    $eid=stageResultHostId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $id=(int)($_POST['id']??0);
    $action=strtoupper(trim((string)($_POST['action']??'')));

    if(!$eid||!$uid||!$id)jsonResponse(false,'Informations incomplètes.',[],422);
    if(!in_array($action,['RECEIVE','VALIDATE','ARCHIVE'],true))jsonResponse(false,'Action invalide.',[],422);

    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT * FROM stage_result_transmissions WHERE id=? AND university_etablissement_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$id,$eid]);
    $t=$s->fetch(PDO::FETCH_ASSOC);
    if(!$t)throw new RuntimeException('Transmission introuvable.');

    /* RECEIVE accuse réception d'un envoi sans encore le valider académiquement. */
    if($action==='RECEIVE'){
        if($t['statut']!=='ENVOYE')throw new RuntimeException('Seuls les résultats envoyés peuvent être marqués reçus.');
        $pdo->prepare("UPDATE stage_result_transmissions SET statut='RECU',received_by=?,received_at=NOW() WHERE id=?")
            ->execute([$uid,$id]);
        $msg='Résultats marqués comme reçus.';
    }elseif($action==='VALIDATE'){
        /* VALIDATE effectue la réception implicite si nécessaire puis marque la validation. */
        if(!in_array($t['statut'],['ENVOYE','RECU'],true))throw new RuntimeException('Cette transmission ne peut plus être validée.');
        $pdo->prepare("
            UPDATE stage_result_transmissions
            SET statut='VALIDE',
                received_by=COALESCE(received_by,?),
                received_at=COALESCE(received_at,NOW()),
                validated_by=?,
                validated_at=NOW()
            WHERE id=?
        ")->execute([$uid,$uid,$id]);
        $msg='Résultats validés par l’université.';
    }else{
        /* ARCHIVE ne s'applique qu'à un relevé déjà reçu ou validé. */
        if(!in_array($t['statut'],['RECU','VALIDE'],true))throw new RuntimeException('Seuls les résultats reçus ou validés peuvent être archivés.');
        $pdo->prepare("UPDATE stage_result_transmissions SET statut='ARCHIVE',archived_by=?,archived_at=NOW() WHERE id=?")
            ->execute([$uid,$id]);
        $msg='Résultats archivés.';
    }

    $pdo->commit();
    jsonResponse(true,$msg,['id'=>$id,'action'=>$action]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],422);
}

<?php
/**
 * Endpoint AJAX d'envoi d'un relevé de résultats hospitaliers vers l'université propriétaire de la campagne.
 * Un instantané détaillé des indicateurs de chaque stagiaire est créé à l'envoi.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-results.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE']);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* L'envoi exige des affectations et au moins une évaluation validée ou finalisée. */
    $hostId=stageResultHostId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $campaignId=(int)($_POST['campaign_id']??0);
    $observation=trim((string)($_POST['observation']??''));

    if(!$hostId||!$uid||!$campaignId)jsonResponse(false,'Informations incomplètes.',[],422);

    $assignments=stageResultAssignments($pdo,$hostId,$campaignId);
    if(!$assignments)jsonResponse(false,'Aucun stagiaire à transmettre pour cette session.',[],422);

    $metrics=stageResultCampaignStats($pdo,$hostId,$campaignId);
    if((int)$metrics['evaluation_count']<=0)
        jsonResponse(false,'Aucune évaluation validée ou finalisée à transmettre.',[],422);

    $latest=stageResultLatestTransmission($pdo,$hostId,$campaignId);
    if($latest&&in_array($latest['statut'],['ENVOYE','RECU','VALIDE','ARCHIVE'],true))
        jsonResponse(false,'Les résultats de cette session ont déjà été transmis.',[],422);

    $universityId=(int)$assignments[0]['university_id'];

    /* La transmission et toutes ses lignes de résultat sont créées de manière atomique. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        INSERT INTO stage_result_transmissions(
            uuid,campaign_id,host_etablissement_id,university_etablissement_id,
            statut,transmitted_by,transmitted_at,observation
        ) VALUES(?,?,?,?, 'ENVOYE',?,NOW(),?)
    ");
    $s->execute([stageResultUuid(),$campaignId,$hostId,$universityId,$uid,$observation?:null]);
    $transmissionId=(int)$pdo->lastInsertId();

    $ins=$pdo->prepare("
        INSERT INTO stage_result_items(
            transmission_id,assignment_id,student_id,rotation_count,presence_count,
            present_count,late_count,absent_count,justified_count,guard_count,presence_rate,
            journal_count,validated_journal_count,evaluation_count,final_score,decision,appreciation
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    /* Les métriques sont figées par stagiaire afin que le relevé transmis reste traçable. */
    foreach($assignments as $a){
        $m=stageResultMetrics($pdo,(int)$a['assignment_id']);
        $app='Présence '.$m['presence_rate'].'% · Journaux validés '.$m['validated_journal_count'].'/'.$m['journal_count'].' · Note '.$m['final_score'].'%';
        $ins->execute([
            $transmissionId,(int)$a['assignment_id'],(int)$a['student_id'],
            $m['rotation_count'],$m['presence_count'],$m['present_count'],$m['late_count'],$m['absent_count'],
            $m['justified_count'],$m['guard_count'],$m['presence_rate'],$m['journal_count'],
            $m['validated_journal_count'],$m['evaluation_count'],$m['final_score'],$m['decision'],$app
        ]);
    }

    $pdo->commit();
    jsonResponse(true,'Résultats transmis à l’université.',['transmission_id'=>$transmissionId]);
}catch(Throwable $e){
    /* Un échec annule l'entête et toutes les lignes de transmission. */
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[HOST RESULTS SEND] '.$e->getMessage());
    jsonResponse(false,'Erreur transmission : '.$e->getMessage(),[],422);
}

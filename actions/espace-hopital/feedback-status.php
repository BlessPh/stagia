<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-feedback.php';

requireAjaxRole(['ENCADREUR','EVALUATEUR_CLINIQUE']);
feedbackVerifyCsrf();

try{
    $hostId=feedbackHostId($pdo);$userId=(int)($_SESSION['user_id']??0);$id=(int)($_POST['id']??0);$action=strtoupper(trim((string)($_POST['action']??'')));
    if(!$id||!in_array($action,['PUBLISH','ARCHIVE'],true))throw new RuntimeException('Action invalide.');
    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT f.* FROM stage_supervision_feedbacks f JOIN stage_assignments a ON a.id=f.assignment_id WHERE f.id=? AND a.host_etablissement_id=? FOR UPDATE");
    $s->execute([$id,$hostId]);$fb=$s->fetch(PDO::FETCH_ASSOC);
    if(!$fb)throw new RuntimeException('Feedback introuvable.');
    if((int)$fb['created_by_user_id']!==$userId)throw new RuntimeException('Action réservée à l’auteur du feedback.');

    if($action==='PUBLISH'){
        if($fb['statut']!=='BROUILLON')throw new RuntimeException('Seul un brouillon peut être publié.');
        $s=$pdo->prepare("UPDATE stage_supervision_feedbacks SET statut='PUBLIE',published_at=NOW(),student_seen_at=NULL WHERE id=?");$s->execute([$id]);$msg='Feedback publié.';
    }else{
        if($fb['statut']==='ARCHIVE')throw new RuntimeException('Feedback déjà archivé.');
        $s=$pdo->prepare("UPDATE stage_supervision_feedbacks SET statut='ARCHIVE',archived_at=NOW() WHERE id=?");$s->execute([$id]);$msg='Feedback archivé.';
    }
    $pdo->commit();jsonResponse(true,$msg,['id'=>$id]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

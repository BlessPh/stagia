<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-feedback.php';

requireAjaxRole(['ENCADREUR','EVALUATEUR_CLINIQUE']);
feedbackVerifyCsrf();

try{
    $hostId=feedbackHostId($pdo);$userId=(int)($_SESSION['user_id']??0);$id=(int)($_POST['id']??0);
    $assignmentId=(int)($_POST['assignment_id']??0);$rotationId=(int)($_POST['rotation_id']??0);
    $date=trim((string)($_POST['date_feedback']??''));$type=strtoupper(trim((string)($_POST['type_feedback']??'OBSERVATION')));
    $title=trim((string)($_POST['titre']??''));$comment=trim((string)($_POST['commentaire']??''));
    $visible=isset($_POST['visible_stagiaire'])&&in_array((string)$_POST['visible_stagiaire'],['1','on','true'],true)?1:0;

    if(!$id||!$assignmentId)throw new RuntimeException('Informations incomplètes.');
    if(!in_array($type,['OBSERVATION','ENCOURAGEMENT','A_AMELIORER','AVERTISSEMENT'],true))throw new RuntimeException('Type de feedback invalide.');
    if($comment===''||mb_strlen($comment)>5000)throw new RuntimeException('Le commentaire est obligatoire et limité à 5000 caractères.');
    if(mb_strlen($title)>180)throw new RuntimeException('Le titre est limité à 180 caractères.');

    $pdo->beginTransaction();
    $s=$pdo->prepare("SELECT f.* FROM stage_supervision_feedbacks f JOIN stage_assignments a ON a.id=f.assignment_id WHERE f.id=? AND a.host_etablissement_id=? FOR UPDATE");
    $s->execute([$id,$hostId]);$fb=$s->fetch(PDO::FETCH_ASSOC);
    if(!$fb)throw new RuntimeException('Feedback introuvable.');
    if((int)$fb['created_by_user_id']!==$userId||$fb['statut']!=='BROUILLON')throw new RuntimeException('Seul votre brouillon peut être modifié.');

    $a=feedbackAssignment($pdo,$assignmentId,$hostId,true);
    if(!feedbackSupervisorCanAccessAssignment($pdo,$assignmentId,$userId))throw new RuntimeException("Vous n'encadrez pas ce stagiaire.");
    $r=feedbackRotation($pdo,$rotationId,$assignmentId,$hostId,$rotationId?$userId:null);
    $date=feedbackValidateDate($date,$a,$r);

    $s=$pdo->prepare("UPDATE stage_supervision_feedbacks SET assignment_id=?,rotation_id=?,date_feedback=?,type_feedback=?,titre=?,commentaire=?,visible_stagiaire=? WHERE id=?");
    $s->execute([$assignmentId,$rotationId?:null,$date,$type,$title?:null,$comment,$visible,$id]);
    $pdo->commit();jsonResponse(true,'Feedback modifié.',['id'=>$id]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

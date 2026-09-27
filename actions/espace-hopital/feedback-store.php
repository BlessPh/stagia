<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-feedback.php';

requireAjaxRole(['ENCADREUR','EVALUATEUR_CLINIQUE']);
feedbackVerifyCsrf();

try{
    if(!feedbackHostCanManage())throw new RuntimeException('Accès refusé.');
    $hostId=feedbackHostId($pdo);$userId=(int)($_SESSION['user_id']??0);
    $assignmentId=(int)($_POST['assignment_id']??0);$rotationId=(int)($_POST['rotation_id']??0);
    $date=trim((string)($_POST['date_feedback']??''));$type=strtoupper(trim((string)($_POST['type_feedback']??'OBSERVATION')));
    $title=trim((string)($_POST['titre']??''));$comment=trim((string)($_POST['commentaire']??''));
    $visible=isset($_POST['visible_stagiaire'])&&in_array((string)$_POST['visible_stagiaire'],['1','on','true'],true)?1:0;

    if(!$assignmentId)throw new RuntimeException('Stagiaire obligatoire.');
    if(!in_array($type,['OBSERVATION','ENCOURAGEMENT','A_AMELIORER','AVERTISSEMENT'],true))throw new RuntimeException('Type de feedback invalide.');
    if(mb_strlen($title)>180)throw new RuntimeException('Le titre est limité à 180 caractères.');
    if($comment===''||mb_strlen($comment)>5000)throw new RuntimeException('Le commentaire est obligatoire et limité à 5000 caractères.');

    $pdo->beginTransaction();
    $a=feedbackAssignment($pdo,$assignmentId,$hostId,true);
    if(!feedbackSupervisorCanAccessAssignment($pdo,$assignmentId,$userId))throw new RuntimeException("Vous n'encadrez pas ce stagiaire.");
    $r=feedbackRotation($pdo,$rotationId,$assignmentId,$hostId,$rotationId?$userId:null);
    $date=feedbackValidateDate($date,$a,$r);

    $s=$pdo->prepare("INSERT INTO stage_supervision_feedbacks(uuid,assignment_id,rotation_id,date_feedback,type_feedback,titre,commentaire,visible_stagiaire,statut,created_by_user_id,actif) VALUES(?,?,?,?,?,?,?,?, 'BROUILLON',?,1)");
    $s->execute([feedbackUuidV4(),$assignmentId,$rotationId?:null,$date,$type,$title?:null,$comment,$visible,$userId]);
    $id=(int)$pdo->lastInsertId();$pdo->commit();
    jsonResponse(true,'Feedback enregistré en brouillon.',['id'=>$id]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);}

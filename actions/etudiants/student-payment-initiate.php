<?php

if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-student-payment.php';

requireAjaxRole(['STAGIAIRE']);
verifyAjaxCsrf();

try{
    $userId=(int)($_SESSION['user_id']??0);
    $stmt=$pdo->prepare("SELECT id FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");
    $stmt->execute([$userId]);
    $studentId=(int)$stmt->fetchColumn();
    if(!$studentId)jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $result=initiateStudentStagePayment($pdo,$studentId,$userId,$_POST,'STAGIA_WEB');
    $created=(bool)($result['payment']['created']??false);
    jsonResponse(true,$created?'Paiement envoyé. En attente de confirmation de l’opérateur.':'Cette demande de paiement est déjà prise en compte.',$result,$created?201:200);
}catch(InvalidArgumentException $e){
    jsonResponse(false,$e->getMessage(),[],422);
}catch(OutOfBoundsException $e){
    jsonResponse(false,$e->getMessage(),[],404);
}catch(DomainException $e){
    jsonResponse(false,$e->getMessage(),[],409);
}catch(Throwable $e){
    error_log('[STUDENT PAYMENT INITIATE] '.$e->getMessage());
    jsonResponse(false,"Une erreur interne empêche l'initiation du paiement.",[],500);
}

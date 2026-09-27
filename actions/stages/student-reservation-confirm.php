<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-student-reservation.php';

requireAjaxRole(['STAGIAIRE']);
verifyAjaxCsrf();

$reservationId=(int)($_POST['reservation_id']??0);
$userId=(int)($_SESSION['user_id']??0);
if(!$reservationId)jsonResponse(false,'Réservation invalide.',[],422);

try{
    $stmt=$pdo->prepare("
        SELECT r.uuid,sp.id student_id
        FROM stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN student_profiles sp ON sp.id=se.student_id
        WHERE r.id=? AND sp.user_id=? LIMIT 1
    ");
    $stmt->execute([$reservationId,$userId]);$owned=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$owned)jsonResponse(false,'Réservation introuvable.',[],404);
    $result=confirmStudentReservation($pdo,(int)$owned['student_id'],$owned['uuid']);
    jsonResponse(true,'Réservation déjà confirmée.',$result);
}catch(DomainException $e){
    jsonResponse(false,$e->getMessage(),['reservation_id'=>$reservationId],409);
}catch(Throwable $e){
    error_log('[STUDENT RESERVATION CONFIRM] '.$e->getMessage());
    jsonResponse(false,'Une erreur interne empêche la confirmation.',[],500);
}

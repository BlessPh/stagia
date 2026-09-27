<?php

/** Expire uniquement les réservations temporaires du stagiaire concerné. */
function expireStudentTemporaryReservations(PDO $pdo,int $studentId):int{
    $stmt=$pdo->prepare("
        UPDATE stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        SET r.statut='EXPIREE'
        WHERE se.student_id=?
          AND r.statut='RESERVEE_TEMPORAIREMENT'
          AND r.expires_at IS NOT NULL
          AND r.expires_at<=NOW()
    ");
    $stmt->execute([$studentId]);
    return $stmt->rowCount();
}

/** État mobile commun aux listes de candidatures, réservations et admissions. */
function studentStageWorkflowStatus(array $row):string{
    $application=(string)($row['application_status']??$row['statut_application']??'');
    $reservation=(string)($row['reservation_status']??$row['statut_reservation']??'');
    $placement=(string)($row['placement_status']??'');
    $admission=(string)($row['admission_status']??'');
    $assignment=(string)($row['assignment_status']??'');
    $completion=(string)($row['completion_status']??'');

    if($completion==='VALIDE')return 'STAGE_VALIDE';
    if($assignment==='TERMINEE')return 'STAGE_TERMINE';
    if($assignment==='ACTIVE')return 'STAGE_EN_COURS';
    if($assignment==='PLANIFIEE')return 'STAGE_PLANIFIE';
    if($admission==='EN_COURS')return 'STAGE_EN_COURS';
    if($admission==='ADMIS')return 'AFFECTATION_EN_ATTENTE';
    if($admission==='ATTENDU')return 'ADMISSION_HOSPITALIERE_EN_ATTENTE';
    if($placement==='CONFIRME')return 'ADMISSION_HOSPITALIERE_EN_ATTENTE';
    if($application==='REFUSEE')return 'CANDIDATURE_REFUSEE';
    if($application==='ANNULEE'||$reservation==='ANNULEE')return 'ANNULEE';
    if($reservation==='EXPIREE')return 'RESERVATION_EXPIREE';
    if($reservation==='EN_ATTENTE_PAIEMENT')return 'EN_ATTENTE_PAIEMENT';
    if($reservation==='CONFIRMEE')return 'PLACEMENT_UNIVERSITAIRE_EN_ATTENTE';
    if($reservation==='RESERVEE_TEMPORAIREMENT')return 'DECISION_UNIVERSITAIRE_EN_ATTENTE';
    return $application!==''?$application:($reservation!==''?$reservation:'INCONNU');
}

function studentStageTypeMode(string $stageTypeCode):array{
    $isD4=$stageTypeCode==='MEDICAL_D4';
    return [
        'is_d4'=>$isD4,
        'self_reservation_allowed'=>$isD4,
        'reservation_mode'=>$isD4?'STUDENT_D4_CHOICE':'UNIVERSITY_MANAGED'
    ];
}

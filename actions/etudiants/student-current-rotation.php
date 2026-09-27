<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-execution.php';

requirePermission($pdo,'stage.self.view');

$userId=(int)($_SESSION['user_id']??0);
$assignmentId=(int)($_GET['assignment_id']??0);

try{
    $s=$pdo->prepare("
        SELECT sp.id student_id
        FROM student_profiles sp
        JOIN student_enrollments se ON se.student_id=sp.id
        JOIN student_academic_enrollments ae ON ae.enrollment_id=se.id
        JOIN stage_applications app ON app.academic_enrollment_id=ae.id
        JOIN stage_reservations sr ON sr.application_id=app.id AND sr.statut='CONFIRMEE'
        JOIN stage_admissions a ON a.reservation_id=sr.id AND a.statut IN('ADMIS','EN_COURS','TERMINE')
        JOIN stage_assignments sa ON sa.admission_id=a.id AND sa.statut<>'ANNULEE'
        WHERE sp.user_id=?
          AND sa.id=?
        LIMIT 1
    ");
    $s->execute([$userId,$assignmentId]);

    if(!$s->fetchColumn()){
        jsonResponse(false,'Affectation introuvable dans votre dossier.',[],404);
    }

    $access=stageExecutionAccess($pdo,$assignmentId);

    jsonResponse(true,'',['execution'=>$access]);

}catch(Throwable $e){
    jsonResponse(false,'Erreur rotation courante : '.$e->getMessage(),[],500);
}

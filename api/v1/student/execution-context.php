<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-execution.php';
require_once __DIR__.'/../../../includes/student-attendance-punch.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$assignmentUuid=trim((string)($_GET['assignment_uuid']??''));

try{
    $punch=studentAttendancePunchContext($pdo,(int)$student['user_id']);
    $execution=null;

    if($assignmentUuid!==''){
        $s=$pdo->prepare("SELECT a.id FROM stage_assignments a JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id JOIN student_enrollments se ON se.id=ae.enrollment_id WHERE a.uuid=? AND se.student_id=? AND a.statut<>'ANNULEE' LIMIT 1");
        $s->execute([$assignmentUuid,(int)$student['student_id']]);
        $assignmentId=(int)$s->fetchColumn();
        if(!$assignmentId)apiResponse(false,'Stage introuvable ou non autorise.',[],404);
        $execution=stageExecutionAccess($pdo,$assignmentId);
    }elseif(!empty($punch['assignment_id'])){
        $execution=stageExecutionAccess($pdo,(int)$punch['assignment_id']);
    }

    apiResponse(true,'',['execution'=>$execution,'punch'=>$punch]);
}catch(Throwable $e){
    apiResponse(false,'Erreur contexte de stage : '.$e->getMessage(),[],500);
}

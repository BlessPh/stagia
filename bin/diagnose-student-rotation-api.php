<?php
declare(strict_types=1);

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/stage-task.php';
require_once __DIR__.'/../includes/student-logbook-api.php';

function rotationApiCheck(string $label,callable $callback):void{
    try{$result=$callback();echo '[OK] '.$label.' : '.$result.PHP_EOL;}
    catch(Throwable $error){echo '[ERREUR] '.$label.' : '.$error->getMessage().PHP_EOL;}
}

$studentId=(int)$pdo->query('SELECT id FROM student_profiles ORDER BY id LIMIT 1')->fetchColumn();
if($studentId<1){echo 'Aucun profil etudiant disponible.'.PHP_EOL;exit(0);}

rotationApiCheck('Collection mobile des taches',static function()use($pdo,$studentId):string{
    $data=stageTaskStudentApiList($pdo,$studentId,['statuses'=>[],'priorities'=>[],
        'assignment_uuid'=>'','rotation_uuid'=>'','due_from'=>'','due_to'=>'','page'=>1,'per_page'=>20]);
    return count($data['items']).' element(s), total '.$data['pagination']['total'];
});

rotationApiCheck('Contexte des rotations',static function()use($pdo,$studentId):string{
    $stmt=$pdo->prepare("SELECT a.id FROM stage_assignments a JOIN stage_admissions ad ON ad.id=a.admission_id
        JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id
        JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id WHERE se.student_id=? ORDER BY a.id DESC LIMIT 1");
    $stmt->execute([$studentId]);$assignmentId=(int)$stmt->fetchColumn();
    $data=stageExecutionAccess($pdo,$assignmentId?:-1);
    if(!$assignmentId)return 'schema valide, aucune affectation';
    return $data['current_rotation']?'rotation active chargee':'aucune rotation active';
});

rotationApiCheck('Objet mobile du journal',static function()use($pdo,$studentId):string{
    $stmt=$pdo->prepare("SELECT le.uuid FROM stage_logbook_entries le JOIN stage_assignments a ON a.id=le.assignment_id
        JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id WHERE se.student_id=? ORDER BY le.id DESC LIMIT 1");
    $stmt->execute([$studentId]);$uuid=(string)$stmt->fetchColumn();
    if($uuid===''){
        try{studentLogbookApiEntry($pdo,$studentId,'00000000-0000-0000-0000-000000000000');}
        catch(OutOfBoundsException){return 'schema principal valide, aucune entree';}
    }
    $entry=studentLogbookApiEntry($pdo,$studentId,$uuid);
    return $entry['uuid'].' ('.$entry['status'].')';
});

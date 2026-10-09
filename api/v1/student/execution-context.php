<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-execution.php';
require_once __DIR__.'/../../../includes/student-attendance-punch.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$assignmentUuid=trim((string)($_GET['assignment_uuid']??''));

try{
    $assignmentId=0;
    if($assignmentUuid!==''){
        $s=$pdo->prepare("SELECT a.id FROM stage_assignments a JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id JOIN student_enrollments se ON se.id=ae.enrollment_id WHERE a.uuid=? AND se.student_id=? AND a.statut<>'ANNULEE' LIMIT 1");
        $s->execute([$assignmentUuid,(int)$student['student_id']]);
        $assignmentId=(int)$s->fetchColumn();
        if(!$assignmentId)apiResponse(false,'Stage introuvable ou non autorisé.',[],404);
    }

    $punch=studentAttendancePunchContext(
        $pdo,(int)$student['user_id'],null,$assignmentId>0?$assignmentId:null
    );
    $execution=null;
    if(!$assignmentId&&!empty($punch['assignment_id']))$assignmentId=(int)$punch['assignment_id'];
    if($assignmentId)$execution=stageExecutionAccess($pdo,$assignmentId);

    $context=null;
    if($assignmentId){
        $s=$pdo->prepare("SELECT
                a.uuid assignment_uuid,a.statut assignment_status,a.date_debut assignment_start,a.date_fin assignment_end,
                c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
                h.id hospital_id,h.code hospital_code,h.nom hospital_name,h.telephone hospital_phone,
                h.email hospital_email,h.adresse hospital_address,h.ville hospital_city,h.province hospital_province,
                d.id department_id,d.code department_code,d.nom department_name,d.type department_type
            FROM stage_assignments a
            JOIN stage_admissions ad ON ad.id=a.admission_id
            JOIN stage_reservations sr ON sr.id=ad.reservation_id
            JOIN stage_applications app ON app.id=sr.application_id
            JOIN stage_campaigns c ON c.id=app.campaign_id
            JOIN etablissements h ON h.id=a.host_etablissement_id
            LEFT JOIN host_units d ON d.id=ad.coordination_unit_id
            WHERE a.id=? LIMIT 1");
        $s->execute([$assignmentId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if($row){
            $normalizeRotation=static function(?array $rotation):?array{
                if(!$rotation)return null;
                $targetType=strtoupper((string)($rotation['unit_type']??''));
                $isUnit=in_array($targetType,['UNITE','UNIT'],true);
                $service=$isUnit?[
                    'id'=>$rotation['parent_unit_id']!==null?(int)$rotation['parent_unit_id']:null,
                    'code'=>$rotation['parent_unit_code'],'name'=>$rotation['parent_unit_name'],'type'=>$rotation['parent_unit_type']
                ]:[
                    'id'=>(int)$rotation['host_unit_id'],'code'=>$rotation['unit_code'],
                    'name'=>$rotation['unit_name'],'type'=>$rotation['unit_type']
                ];
                return [
                    'id'=>(int)$rotation['rotation_id'],'uuid'=>$rotation['rotation_uuid'],
                    'sequence'=>(int)$rotation['sequence_no'],'status'=>$rotation['statut'],
                    'period'=>['start_date'=>$rotation['date_debut'],'end_date'=>$rotation['date_fin']],
                    'service'=>$service,
                    'unit'=>$isUnit?['id'=>(int)$rotation['host_unit_id'],'code'=>$rotation['unit_code'],
                        'name'=>$rotation['unit_name'],'type'=>$rotation['unit_type']]:null,
                    'supervisor'=>isset($rotation['supervisor_user_id'])&&$rotation['supervisor_user_id']!==null?[
                        'user_id'=>(int)$rotation['supervisor_user_id'],'name'=>$rotation['supervisor_name'],
                        'function'=>$rotation['supervisor_function']
                    ]:null,
                    'objectives'=>$rotation['objectifs']??null,'observation'=>$rotation['observation']??null
                ];
            };
            $current=$normalizeRotation($execution['current_rotation']??null);
            $next=$normalizeRotation($execution['next_rotation']??null);
            $context=[
                'date'=>$execution['date']??date('Y-m-d'),
                'assignment'=>['uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],
                    'period'=>['start_date'=>$row['assignment_start'],'end_date'=>$row['assignment_end']]],
                'campaign'=>['id'=>(int)$row['campaign_id'],'uuid'=>$row['campaign_uuid'],
                    'code'=>$row['campaign_code'],'title'=>$row['campaign_title']],
                'hospital'=>['id'=>(int)$row['hospital_id'],'code'=>$row['hospital_code'],'name'=>$row['hospital_name'],
                    'phone'=>$row['hospital_phone'],'email'=>$row['hospital_email'],'address'=>$row['hospital_address'],
                    'city'=>$row['hospital_city'],'province'=>$row['hospital_province']],
                'department'=>$row['department_id']!==null?['id'=>(int)$row['department_id'],
                    'code'=>$row['department_code'],'name'=>$row['department_name'],'type'=>$row['department_type']]:null,
                'current_rotation'=>$current,'next_rotation'=>$next,
                'attendance'=>$punch['attendance']??null,
                'actions'=>[
                    'arrival'=>['allowed'=>($punch['next_action']??null)==='ARRIVEE','method'=>'POST','path'=>'/api/v1/student/attendance/arrival'],
                    'departure'=>['allowed'=>($punch['next_action']??null)==='DEPART','method'=>'POST','path'=>'/api/v1/student/attendance/departure'],
                    'logbook'=>['allowed'=>(bool)($execution['can_logbook']??false),'method'=>'POST','path'=>'/api/v1/student/logbook']
                ],
                'capabilities'=>['attendance'=>(bool)($execution['can_attendance']??false),
                    'logbook'=>(bool)($execution['can_logbook']??false),'evaluation'=>(bool)($execution['can_evaluation']??false)],
                'reason'=>$execution['reason']??$punch['reason']??null,
                'unlock_date'=>$execution['unlock_date']??null
            ];
        }
    }

    apiResponse(true,'',['context'=>$context,'execution'=>$execution,'punch'=>$punch]);
}catch(Throwable $e){
    apiResponse(false,'Erreur contexte de stage : '.$e->getMessage(),[],500);
}

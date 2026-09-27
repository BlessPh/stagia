<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-workflow.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];
$reservationUuid=trim((string)($_GET['reservation_uuid']??''));

try{
    expireStudentTemporaryReservations($pdo,$studentId);
    $sql="
        SELECT r.uuid reservation_uuid,r.statut reservation_status,r.expires_at,r.confirmed_at,
               a.uuid application_uuid,a.statut application_status,
               c.code campaign_code,c.titre campaign_title,c.date_debut campaign_start,c.date_fin campaign_end,
               st.code stage_type_code,st.libelle stage_type_label,
               h.code hospital_code,h.nom hospital_name,h.ville hospital_city,h.province hospital_province,
               pl.uuid placement_uuid,pl.statut placement_status,pl.university_confirmed_at placement_confirmed_at,
               ad.uuid admission_uuid,ad.statut admission_status,ad.admitted_at,ad.observation admission_observation,
               ass.uuid assignment_uuid,ass.statut assignment_status,ass.date_debut assignment_start,
               ass.date_fin assignment_end,ass.assigned_at,ass.ended_at,ass.observation assignment_observation,
               hu.code unit_code,hu.nom unit_name,
               comp.statut completion_status,comp.taux_presence,comp.note_finale
        FROM stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN stage_types st ON st.id=c.stage_type_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_placements pl ON pl.id=(SELECT MAX(pl2.id) FROM stage_placements pl2 WHERE pl2.reservation_id=r.id)
        LEFT JOIN stage_admissions ad ON ad.id=(SELECT MAX(ad2.id) FROM stage_admissions ad2 WHERE ad2.reservation_id=r.id)
        LEFT JOIN stage_assignments ass ON ass.id=(SELECT MAX(ass2.id) FROM stage_assignments ass2 WHERE ass2.admission_id=ad.id)
        LEFT JOIN host_units hu ON hu.id=ass.host_unit_id
        LEFT JOIN stage_completions comp ON comp.assignment_id=ass.id
        WHERE se.student_id=?";
    $params=[$studentId];
    if($reservationUuid!==''){$sql.=' AND r.uuid=?';$params[]=$reservationUuid;}
    $sql.=' ORDER BY r.id DESC';
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $items=[];$stats=['total'=>0,'waiting_decision'=>0,'waiting_payment'=>0,'waiting_placement'=>0,'waiting_admission'=>0,'admitted'=>0,'assigned'=>0,'completed'=>0,'refused'=>0,'expired'=>0];
    foreach($rows as $row){
        $workflow=studentStageWorkflowStatus($row);
        $item=[
            'reservation'=>['uuid'=>$row['reservation_uuid'],'status'=>$row['reservation_status'],'expires_at'=>$row['expires_at'],'confirmed_at'=>$row['confirmed_at']],
            'application'=>['uuid'=>$row['application_uuid'],'status'=>$row['application_status']],
            'placement'=>['exists'=>$row['placement_uuid']!==null,'uuid'=>$row['placement_uuid'],'status'=>$row['placement_status'],'confirmed_at'=>$row['placement_confirmed_at']],
            'admission'=>['exists'=>$row['admission_uuid']!==null,'uuid'=>$row['admission_uuid'],'status'=>$row['admission_status'],'admitted_at'=>$row['admitted_at'],'observation'=>$row['admission_observation']],
            'assignment'=>['exists'=>$row['assignment_uuid']!==null,'uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],'start_date'=>$row['assignment_start'],'end_date'=>$row['assignment_end'],'assigned_at'=>$row['assigned_at'],'ended_at'=>$row['ended_at'],'observation'=>$row['assignment_observation'],'unit'=>['code'=>$row['unit_code'],'name'=>$row['unit_name']]],
            'campaign'=>['code'=>$row['campaign_code'],'title'=>$row['campaign_title'],'start_date'=>$row['campaign_start'],'end_date'=>$row['campaign_end']],
            'stage_type'=>['code'=>$row['stage_type_code'],'label'=>$row['stage_type_label']],
            'mode'=>studentStageTypeMode($row['stage_type_code']),
            'hospital'=>['code'=>$row['hospital_code'],'name'=>$row['hospital_name'],'city'=>$row['hospital_city'],'province'=>$row['hospital_province']],
            'completion'=>['status'=>$row['completion_status'],'attendance_rate'=>$row['taux_presence']!==null?(float)$row['taux_presence']:null,'final_score'=>$row['note_finale']!==null?(float)$row['note_finale']:null],
            'workflow_status'=>$workflow
        ];
        $items[]=$item;$stats['total']++;
        $bucket=[
            'DECISION_UNIVERSITAIRE_EN_ATTENTE'=>'waiting_decision','EN_ATTENTE_PAIEMENT'=>'waiting_payment',
            'PLACEMENT_UNIVERSITAIRE_EN_ATTENTE'=>'waiting_placement','ADMISSION_HOSPITALIERE_EN_ATTENTE'=>'waiting_admission',
            'AFFECTATION_EN_ATTENTE'=>'admitted','STAGE_PLANIFIE'=>'assigned','STAGE_EN_COURS'=>'assigned',
            'STAGE_TERMINE'=>'completed','STAGE_VALIDE'=>'completed','CANDIDATURE_REFUSEE'=>'refused','RESERVATION_EXPIREE'=>'expired'
        ][$workflow]??null;
        if($bucket)$stats[$bucket]++;
    }
    apiResponse(true,'',['items'=>$items,'stats'=>$stats]);
}catch(Throwable $e){
    error_log('[API STUDENT ADMISSIONS] '.$e->getMessage());
    apiResponse(false,"Une erreur interne empêche le chargement des admissions.",[],500);
}

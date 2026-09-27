<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-workflow.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];

try{
    expireStudentTemporaryReservations($pdo,$studentId);
    $stmt=$pdo->prepare("
        SELECT a.uuid,a.statut,a.statut application_status,a.motivation,a.motif_refus,a.submitted_at,a.responded_at,
               c.code campaign_code,c.titre campaign_title,c.date_debut campaign_start,c.date_fin campaign_end,
               st.code stage_type_code,st.libelle stage_type_label,
               h.code hospital_code,h.nom hospital_name,h.ville,h.province,
               r.uuid reservation_uuid,r.statut reservation_status,r.expires_at,r.confirmed_at,r.cancelled_at,
               pl.uuid placement_uuid,pl.statut placement_status,
               ad.uuid admission_uuid,ad.statut admission_status,ad.admitted_at,
               ass.uuid assignment_uuid,ass.statut assignment_status,
               comp.statut completion_status,comp.taux_presence,comp.note_finale
        FROM stage_applications a
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN stage_types st ON st.id=c.stage_type_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_reservations r ON r.application_id=a.id
        LEFT JOIN stage_placements pl ON pl.id=(SELECT MAX(pl2.id) FROM stage_placements pl2 WHERE pl2.reservation_id=r.id)
        LEFT JOIN stage_admissions ad ON ad.id=(SELECT MAX(ad2.id) FROM stage_admissions ad2 WHERE ad2.reservation_id=r.id)
        LEFT JOIN stage_assignments ass ON ass.id=(SELECT MAX(ass2.id) FROM stage_assignments ass2 WHERE ass2.admission_id=ad.id)
        LEFT JOIN stage_completions comp ON comp.assignment_id=ass.id
        WHERE se.student_id=?
        ORDER BY a.submitted_at DESC,a.id DESC
    ");
    $stmt->execute([$studentId]);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){
        $item['stage_type']=['code'=>$item['stage_type_code'],'label'=>$item['stage_type_label']];
        $item['mode']=studentStageTypeMode($item['stage_type_code']);
        $item['workflow_status']=studentStageWorkflowStatus($item);
        $item['taux_presence']=$item['taux_presence']!==null?(float)$item['taux_presence']:null;
        $item['note_finale']=$item['note_finale']!==null?(float)$item['note_finale']:null;
        unset($item['stage_type_code'],$item['stage_type_label']);
    }unset($item);
    apiResponse(true,'',['items'=>$items,'total'=>count($items)]);
}catch(Throwable $e){
    error_log('[API STUDENT APPLICATIONS] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche le chargement des candidatures.',[],500);
}

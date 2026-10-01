<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-workflow.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];

try{
    expireStudentTemporaryReservations($pdo,$studentId);
    $stmt=$pdo->prepare("
        SELECT r.uuid,r.statut,r.statut reservation_status,
               r.expires_at,r.confirmed_at,r.cancelled_at,r.created_at,
               a.uuid application_uuid,a.statut application_status,
               c.code campaign_code,c.titre campaign_title,c.date_debut campaign_start,c.date_fin campaign_end,
               st.code stage_type_code,st.libelle stage_type_label,
               h.code hospital_code,h.nom hospital_name,h.ville,h.province,
               p.frais_requis,p.montant_frais,p.devise,
               pl.uuid placement_uuid,pl.statut placement_status,
               ad.uuid admission_uuid,ad.statut admission_status,
               ass.uuid assignment_uuid,ass.statut assignment_status,
               comp.statut completion_status,
               (SELECT COUNT(*) FROM stage_payments pay JOIN stage_invoices i ON i.id=pay.invoice_id
                WHERE i.reservation_id=r.id AND pay.statut='VALIDE') validated_payment_count
        FROM stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN stage_types st ON st.id=c.stage_type_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_campaign_participations p ON p.id=r.participation_id
        LEFT JOIN stage_placements pl ON pl.id=(SELECT MAX(pl2.id) FROM stage_placements pl2 WHERE pl2.reservation_id=r.id)
        LEFT JOIN stage_admissions ad ON ad.id=(SELECT MAX(ad2.id) FROM stage_admissions ad2 WHERE ad2.reservation_id=r.id)
        LEFT JOIN stage_assignments ass ON ass.id=(SELECT MAX(ass2.id) FROM stage_assignments ass2 WHERE ass2.admission_id=ad.id)
        LEFT JOIN stage_completions comp ON comp.assignment_id=ass.id
        WHERE se.student_id=? ORDER BY r.created_at DESC,r.id DESC
    ");
    $stmt->execute([$studentId]);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats=['total'=>count($items),'temporary'=>0,'waiting_payment'=>0,'confirmed'=>0,'expired'=>0,'cancelled'=>0];
    foreach($items as &$item){
        $item['frais_requis']=(bool)$item['frais_requis'];
        $item['montant_frais']=$item['montant_frais']!==null?(float)$item['montant_frais']:null;
        $item['expired']=$item['statut']==='EXPIREE';
        $item['stage_type']=['code'=>$item['stage_type_code'],'label'=>$item['stage_type_label']];
        $item['mode']=studentStageTypeMode($item['stage_type_code']);
        $item['workflow_status']=studentStageWorkflowStatus($item);
        $item['workflow_message']=studentStageWorkflowMessage($item['workflow_status']);
        $item['can_confirm']=false;
        $item['confirmation_managed_by']=$item['statut']==='EN_ATTENTE_PAIEMENT'?'PAYMENT':'UNIVERSITY';
        $item['can_cancel']=!$item['placement_uuid']&&!$item['admission_uuid']&&(int)$item['validated_payment_count']===0
            &&in_array($item['statut'],['RESERVEE_TEMPORAIREMENT','EN_ATTENTE_PAIEMENT','CONFIRMEE','EXPIREE'],true)
            &&!in_array($item['application_status'],['REFUSEE','ANNULEE'],true);
        $bucket=['RESERVEE_TEMPORAIREMENT'=>'temporary','EN_ATTENTE_PAIEMENT'=>'waiting_payment','CONFIRMEE'=>'confirmed','EXPIREE'=>'expired','ANNULEE'=>'cancelled'][$item['statut']]??null;
        if($bucket)$stats[$bucket]++;
        unset($item['stage_type_code'],$item['stage_type_label'],$item['validated_payment_count'],$item['reservation_status']);
    }unset($item);
    apiResponse(true,'',['items'=>$items,'stats'=>$stats]);
}catch(Throwable $e){
    error_log('[API STUDENT RESERVATIONS] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche le chargement des réservations.',[],500);
}

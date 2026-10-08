<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-workflow.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$studentId=(int)$student['student_id'];

/** Accepte `status=A,B` et `status[]=A&status[]=B`. */
function applicationFilterValues(string $name,array $allowed):array{
    $raw=$_GET[$name]??[];
    $values=is_array($raw)?$raw:explode(',',(string)$raw);
    $values=array_values(array_unique(array_filter(array_map(
        static fn($value):string=>strtoupper(trim((string)$value)),
        $values
    ))));
    $invalid=array_values(array_diff($values,$allowed));
    if($invalid){
        apiResponse(false,'Filtre '.$name.' invalide : '.implode(', ',$invalid).'.',[
            'allowed_values'=>$allowed
        ],422);
    }
    return $values;
}

function applicationNullableIntFilter(string $name):int{
    $raw=trim((string)($_GET[$name]??''));
    if($raw==='')return 0;
    if(!ctype_digit($raw)||(int)$raw<1)apiResponse(false,'Filtre '.$name.' invalide.',[],422);
    return (int)$raw;
}

$applicationStatuses=['BROUILLON','SOUMISE','EN_ETUDE','ACCEPTEE','REFUSEE','ANNULEE'];
$reservationStatuses=['RESERVEE_TEMPORAIREMENT','EN_ATTENTE_PAIEMENT','CONFIRMEE','EXPIREE','ANNULEE'];
$workflowStatuses=[
    'DECISION_UNIVERSITAIRE_EN_ATTENTE','EN_ATTENTE_PAIEMENT','PLACEMENT_UNIVERSITAIRE_EN_ATTENTE','PLACEMENT_ANNULE',
    'ADMISSION_HOSPITALIERE_EN_ATTENTE','AFFECTATION_EN_ATTENTE','STAGE_PLANIFIE','STAGE_EN_COURS',
    'STAGE_TERMINE','STAGE_VALIDE','CANDIDATURE_REFUSEE','ANNULEE','RESERVATION_EXPIREE','BROUILLON'
];

$statusFilter=applicationFilterValues('status',$applicationStatuses);
$reservationFilter=applicationFilterValues('reservation_status',$reservationStatuses);
$workflowFilter=applicationFilterValues('workflow_status',$workflowStatuses);
$campaignFilter=applicationNullableIntFilter('campaign_id');
$hospitalFilter=applicationNullableIntFilter('hospital_id');
$page=max(1,(int)($_GET['page']??1));
$perPage=max(1,min(100,(int)($_GET['per_page']??20)));

try{
    expireStudentTemporaryReservations($pdo,$studentId);
    $stmt=$pdo->prepare("
        SELECT a.uuid,a.statut,a.statut application_status,a.motivation,a.motif_refus,a.submitted_at,a.responded_at,
               c.id campaign_id,c.code campaign_code,c.titre campaign_title,c.date_debut campaign_start,c.date_fin campaign_end,
               st.code stage_type_code,st.libelle stage_type_label,
               h.id hospital_id,h.code hospital_code,h.nom hospital_name,h.ville,h.province,
               r.uuid reservation_uuid,r.statut reservation_status,r.expires_at,r.confirmed_at,r.cancelled_at,
               pl.id placement_id,pl.uuid placement_uuid,pl.statut placement_status,
               pl.date_debut placement_start,pl.date_fin placement_end,
               pl.cancelled_at placement_cancelled_at,pl.cancellation_reason placement_cancellation_reason,
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
    $stmt->execute([$studentId]);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats=['total'=>count($items),'draft'=>0,'submitted'=>0,'under_review'=>0,'accepted'=>0,'rejected'=>0,'cancelled'=>0];
    foreach($items as &$item){
        $item['campaign_id']=(int)$item['campaign_id'];
        $item['hospital_id']=(int)$item['hospital_id'];
        $item['stage_type']=['code'=>$item['stage_type_code'],'label'=>$item['stage_type_label']];
        $item['mode']=studentStageTypeMode($item['stage_type_code']);
        $item['workflow_status']=studentStageWorkflowStatus($item);
        $item['workflow_message']=studentStageWorkflowMessage($item['workflow_status']);
        $item['placement']=$item['placement_uuid']!==null?[
            'id'=>(int)$item['placement_id'],'uuid'=>$item['placement_uuid'],'status'=>$item['placement_status'],
            'start_date'=>$item['placement_start'],'end_date'=>$item['placement_end'],
            'cancelled_at'=>$item['placement_cancelled_at'],
            'cancellation_reason'=>$item['placement_cancellation_reason']
        ]:null;
        $item['taux_presence']=$item['taux_presence']!==null?(float)$item['taux_presence']:null;
        $item['note_finale']=$item['note_finale']!==null?(float)$item['note_finale']:null;
        $bucket=[
            'BROUILLON'=>'draft','SOUMISE'=>'submitted','EN_ETUDE'=>'under_review','ACCEPTEE'=>'accepted',
            'REFUSEE'=>'rejected','ANNULEE'=>'cancelled'
        ][$item['application_status']]??null;
        if($bucket)$stats[$bucket]++;
        unset(
            $item['stage_type_code'],$item['stage_type_label'],$item['placement_id'],
            $item['placement_start'],$item['placement_end'],$item['placement_cancelled_at'],
            $item['placement_cancellation_reason']
        );
    }unset($item);

    $filtered=array_values(array_filter($items,static function(array $item)use(
        $statusFilter,$reservationFilter,$workflowFilter,$campaignFilter,$hospitalFilter
    ):bool{
        if($statusFilter&&!in_array($item['application_status'],$statusFilter,true))return false;
        if($reservationFilter&&!in_array((string)$item['reservation_status'],$reservationFilter,true))return false;
        if($workflowFilter&&!in_array($item['workflow_status'],$workflowFilter,true))return false;
        if($campaignFilter&&(int)$item['campaign_id']!==$campaignFilter)return false;
        if($hospitalFilter&&(int)$item['hospital_id']!==$hospitalFilter)return false;
        return true;
    }));

    $filteredTotal=count($filtered);
    $pages=max(1,(int)ceil($filteredTotal/$perPage));
    if($page>$pages)$page=$pages;
    $pageItems=array_slice($filtered,($page-1)*$perPage,$perPage);

    apiResponse(true,'',[
        'items'=>$pageItems,
        'total'=>$filteredTotal,
        'stats'=>$stats,
        'pagination'=>[
            'page'=>$page,'per_page'=>$perPage,'total'=>$filteredTotal,'pages'=>$pages,
            'from'=>$filteredTotal?($page-1)*$perPage+1:0,
            'to'=>$filteredTotal?($page-1)*$perPage+count($pageItems):0
        ],
        'filters'=>[
            'status'=>$statusFilter,'reservation_status'=>$reservationFilter,
            'workflow_status'=>$workflowFilter,'campaign_id'=>$campaignFilter?:null,
            'hospital_id'=>$hospitalFilter?:null
        ],
        'available_filters'=>[
            'status'=>$applicationStatuses,'reservation_status'=>$reservationStatuses,
            'workflow_status'=>$workflowStatuses
        ]
    ]);
}catch(Throwable $e){
    error_log('[API STUDENT APPLICATIONS] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    apiResponse(false,'Une erreur interne empêche le chargement des candidatures.',[],500);
}

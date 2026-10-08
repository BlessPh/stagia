<?php

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../../includes/stage-student-workflow.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$studentId=(int)$student['student_id'];

/** Accepte `status=A,B` et `status[]=A&status[]=B`. */
function reservationFilterValues(string $name,array $allowed):array{
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

function reservationNullableIntFilter(string $name):int{
    $raw=trim((string)($_GET[$name]??''));
    if($raw==='')return 0;
    if(!ctype_digit($raw)||(int)$raw<1)apiResponse(false,'Filtre '.$name.' invalide.',[],422);
    return (int)$raw;
}

$reservationStatuses=['RESERVEE_TEMPORAIREMENT','EN_ATTENTE_PAIEMENT','CONFIRMEE','EXPIREE','ANNULEE'];
$applicationStatuses=['SOUMISE','EN_ETUDE','ACCEPTEE','REFUSEE','ANNULEE'];
$workflowStatuses=[
    'DECISION_UNIVERSITAIRE_EN_ATTENTE','EN_ATTENTE_PAIEMENT','PLACEMENT_UNIVERSITAIRE_EN_ATTENTE','PLACEMENT_ANNULE',
    'ADMISSION_HOSPITALIERE_EN_ATTENTE','AFFECTATION_EN_ATTENTE','STAGE_PLANIFIE','STAGE_EN_COURS',
    'STAGE_TERMINE','STAGE_VALIDE','CANDIDATURE_REFUSEE','ANNULEE','RESERVATION_EXPIREE'
];
$paymentStatuses=['NOT_REQUIRED','NOT_STARTED','PENDING','FAILED','PARTIAL','PAID','CANCELLED','EXPIRED'];

$statusFilter=reservationFilterValues('status',$reservationStatuses);
$applicationFilter=reservationFilterValues('application_status',$applicationStatuses);
$workflowFilter=reservationFilterValues('workflow_status',$workflowStatuses);
$paymentFilter=reservationFilterValues('payment_status',$paymentStatuses);
$campaignFilter=reservationNullableIntFilter('campaign_id');
$hospitalFilter=reservationNullableIntFilter('hospital_id');
$actionable=strtoupper(trim((string)($_GET['actionable']??'')));
if($actionable!==''&&!in_array($actionable,['CANCEL'],true)){
    apiResponse(false,'Filtre actionable invalide.',['allowed_values'=>['CANCEL']],422);
}
$page=max(1,(int)($_GET['page']??1));
$perPage=max(1,min(100,(int)($_GET['per_page']??20)));

try{
    expireStudentTemporaryReservations($pdo,$studentId);

    $stmt=$pdo->prepare("
        SELECT
            r.id reservation_id,r.uuid,r.statut,r.expires_at,r.confirmed_at,r.cancelled_at,
            r.created_at,r.updated_at,
            a.id application_id,a.uuid application_uuid,a.statut application_status,
            a.motivation,a.motif_refus,a.submitted_at,a.responded_at,
            ae.id academic_enrollment_id,ae.statut academic_enrollment_status,
            aa.id academic_year_id,aa.libelle academic_year_label,
            promo.id promotion_id,promo.code promotion_code,promo.nom promotion_name,promo.niveau promotion_level,
            fil.id program_id,fil.code program_code,fil.nom program_name,
            dep.id department_id,dep.code department_code,dep.nom department_name,
            fac.id academic_unit_id,fac.code academic_unit_code,fac.nom academic_unit_name,
            c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
            c.description campaign_description,c.objectif_stage campaign_objective,c.statut campaign_status,
            c.date_debut campaign_start,c.date_fin campaign_end,
            c.ouverture_candidatures applications_open_at,c.cloture_candidatures applications_close_at,
            uni.code university_code,uni.nom university_name,
            st.code stage_type_code,st.libelle stage_type_label,
            h.id hospital_id,h.code hospital_code,h.nom hospital_name,h.telephone hospital_phone,
            h.email hospital_email,h.adresse hospital_address,h.ville,h.province,h.logo hospital_logo,
            part.id participation_id,part.statut participation_status,
            COALESCE(NULLIF(part.capacite_acceptee,0),NULLIF(part.capacite_allouee,0),0) accepted_capacity,
            part.frais_requis,part.montant_frais,part.devise,part.conditions,
            invoice.uuid invoice_uuid,invoice.reference invoice_reference,invoice.statut invoice_status,
            invoice.montant invoice_amount,invoice.devise invoice_currency,
            invoice.date_emission invoice_issued_at,invoice.date_echeance invoice_due_at,invoice.paid_at invoice_paid_at,
            COALESCE((SELECT SUM(pay.montant) FROM stage_payments pay
                      WHERE pay.invoice_id=invoice.id AND pay.statut='VALIDE'
                        AND pay.devise=invoice.devise),0) amount_paid,
            (SELECT COUNT(*) FROM stage_payments pay
             WHERE pay.invoice_id=invoice.id AND pay.statut IN('INITIE','EN_ATTENTE')) pending_payment_count,
            (SELECT pay.statut FROM stage_payments pay
             WHERE pay.invoice_id=invoice.id ORDER BY pay.id DESC LIMIT 1) latest_payment_status,
            pl.uuid placement_uuid,pl.statut placement_status,
            pl.date_debut placement_start,pl.date_fin placement_end,pl.university_confirmed_at placement_confirmed_at,
            pl.cancelled_at placement_cancelled_at,pl.cancellation_reason placement_cancellation_reason,
            ad.uuid admission_uuid,ad.statut admission_status,ad.admitted_at,ad.observation admission_observation,
            ass.uuid assignment_uuid,ass.statut assignment_status,ass.date_debut assignment_start,
            ass.date_fin assignment_end,ass.assigned_at,ass.ended_at,
            hu.code assignment_unit_code,hu.nom assignment_unit_name,hu.type assignment_unit_type,
            comp.statut completion_status,comp.taux_presence,comp.note_finale
        FROM stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN stage_types st ON st.id=c.stage_type_id
        JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN annees_academiques aa ON aa.id=ae.annee_academique_id
        LEFT JOIN promotions promo ON promo.id=ae.promotion_id
        LEFT JOIN filieres fil ON fil.id=promo.filiere_id
        LEFT JOIN departements dep ON dep.id=fil.departement_id
        LEFT JOIN facultes fac ON fac.id=COALESCE(fil.faculte_id,dep.faculte_id)
        LEFT JOIN stage_campaign_participations part ON part.id=r.participation_id
        LEFT JOIN stage_invoices invoice ON invoice.id=(
            SELECT MAX(invoice2.id) FROM stage_invoices invoice2 WHERE invoice2.reservation_id=r.id
        )
        LEFT JOIN stage_placements pl ON pl.id=(
            SELECT MAX(pl2.id) FROM stage_placements pl2 WHERE pl2.reservation_id=r.id
        )
        LEFT JOIN stage_admissions ad ON ad.id=(
            SELECT MAX(ad2.id) FROM stage_admissions ad2 WHERE ad2.reservation_id=r.id
        )
        LEFT JOIN stage_assignments ass ON ass.id=(
            SELECT MAX(ass2.id) FROM stage_assignments ass2 WHERE ass2.admission_id=ad.id
        )
        LEFT JOIN host_units hu ON hu.id=ass.host_unit_id
        LEFT JOIN stage_completions comp ON comp.assignment_id=ass.id
        WHERE se.student_id=?
        ORDER BY r.created_at DESC,r.id DESC
    ");
    $stmt->execute([$studentId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $allItems=[];
    $stats=['total'=>0,'temporary'=>0,'waiting_payment'=>0,'confirmed'=>0,'expired'=>0,'cancelled'=>0,'can_cancel'=>0];

    foreach($rows as $row){
        $workflow=studentStageWorkflowStatus([
            'application_status'=>$row['application_status'],'reservation_status'=>$row['statut'],
            'placement_status'=>$row['placement_status'],'admission_status'=>$row['admission_status'],
            'assignment_status'=>$row['assignment_status'],'completion_status'=>$row['completion_status']
        ]);
        $canCancel=$row['statut']==='RESERVEE_TEMPORAIREMENT'
            &&in_array($row['application_status'],['SOUMISE','EN_ETUDE'],true)
            &&($row['expires_at']===null||strtotime((string)$row['expires_at'])>time())
            &&$row['placement_uuid']===null&&$row['admission_uuid']===null;

        $feesRequired=(bool)$row['frais_requis'];
        $invoiceAmount=$row['invoice_amount']!==null?(float)$row['invoice_amount']:(float)($row['montant_frais']??0);
        $amountPaid=(float)$row['amount_paid'];
        $remaining=max(0,round($invoiceAmount-$amountPaid,2));
        if(!$feesRequired)$paymentStatus='NOT_REQUIRED';
        elseif($row['invoice_status']==='PAYEE'||($row['invoice_uuid']!==null&&$remaining<=0))$paymentStatus='PAID';
        elseif($row['invoice_status']==='ANNULEE')$paymentStatus='CANCELLED';
        elseif($row['invoice_status']==='EXPIREE')$paymentStatus='EXPIRED';
        elseif($amountPaid>0)$paymentStatus='PARTIAL';
        elseif((int)$row['pending_payment_count']>0)$paymentStatus='PENDING';
        elseif($row['latest_payment_status']==='ECHOUE')$paymentStatus='FAILED';
        else $paymentStatus='NOT_STARTED';

        $item=[
            /* Champs historiques conservés pour les clients existants. */
            'uuid'=>$row['uuid'],'statut'=>$row['statut'],'expires_at'=>$row['expires_at'],
            'confirmed_at'=>$row['confirmed_at'],'cancelled_at'=>$row['cancelled_at'],'created_at'=>$row['created_at'],
            'application_uuid'=>$row['application_uuid'],'application_status'=>$row['application_status'],
            'campaign_code'=>$row['campaign_code'],'campaign_title'=>$row['campaign_title'],
            'campaign_start'=>$row['campaign_start'],'campaign_end'=>$row['campaign_end'],
            'hospital_code'=>$row['hospital_code'],'hospital_name'=>$row['hospital_name'],
            'ville'=>$row['ville'],'province'=>$row['province'],
            'placement_uuid'=>$row['placement_uuid'],'placement_status'=>$row['placement_status'],
            'admission_uuid'=>$row['admission_uuid'],'admission_status'=>$row['admission_status'],
            'assignment_uuid'=>$row['assignment_uuid'],'assignment_status'=>$row['assignment_status'],
            'completion_status'=>$row['completion_status'],
            'frais_requis'=>$feesRequired,
            'montant_frais'=>$row['montant_frais']!==null?(float)$row['montant_frais']:null,
            'devise'=>$row['devise'],'expired'=>$row['statut']==='EXPIREE','can_cancel'=>$canCancel,
            'confirmation_managed_by'=>$row['statut']==='EN_ATTENTE_PAIEMENT'?'PAYMENT':'UNIVERSITY',
            'workflow_status'=>$workflow,'workflow_message'=>studentStageWorkflowMessage($workflow),

            /* Objets structurés pour approvisionner les nouveaux écrans. */
            'reservation'=>[
                'uuid'=>$row['uuid'],'status'=>$row['statut'],'expires_at'=>$row['expires_at'],
                'confirmed_at'=>$row['confirmed_at'],'cancelled_at'=>$row['cancelled_at'],
                'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']
            ],
            'application'=>[
                'uuid'=>$row['application_uuid'],'status'=>$row['application_status'],
                'motivation'=>$row['motivation'],'refusal_reason'=>$row['motif_refus'],
                'submitted_at'=>$row['submitted_at'],'responded_at'=>$row['responded_at']
            ],
            'campaign'=>[
                'id'=>(int)$row['campaign_id'],'uuid'=>$row['campaign_uuid'],'code'=>$row['campaign_code'],
                'title'=>$row['campaign_title'],'description'=>$row['campaign_description'],
                'objective'=>$row['campaign_objective'],'status'=>$row['campaign_status'],
                'start_date'=>$row['campaign_start'],'end_date'=>$row['campaign_end'],
                'applications_open_at'=>$row['applications_open_at'],
                'applications_close_at'=>$row['applications_close_at'],
                'university'=>['code'=>$row['university_code'],'name'=>$row['university_name']]
            ],
            'stage_type'=>['code'=>$row['stage_type_code'],'label'=>$row['stage_type_label']],
            'mode'=>studentStageTypeMode((string)$row['stage_type_code']),
            'academic_context'=>[
                'enrollment_id'=>(int)$row['academic_enrollment_id'],
                'enrollment_status'=>$row['academic_enrollment_status'],
                'academic_year'=>['id'=>(int)$row['academic_year_id'],'label'=>$row['academic_year_label']],
                'promotion'=>['id'=>(int)$row['promotion_id'],'code'=>$row['promotion_code'],'name'=>$row['promotion_name'],'level'=>$row['promotion_level']],
                'program'=>['id'=>(int)$row['program_id'],'code'=>$row['program_code'],'name'=>$row['program_name']],
                'department'=>$row['department_id']!==null?['id'=>(int)$row['department_id'],'code'=>$row['department_code'],'name'=>$row['department_name']]:null,
                'academic_unit'=>$row['academic_unit_id']!==null?['id'=>(int)$row['academic_unit_id'],'code'=>$row['academic_unit_code'],'name'=>$row['academic_unit_name']]:null
            ],
            'hospital'=>[
                'id'=>(int)$row['hospital_id'],'code'=>$row['hospital_code'],'name'=>$row['hospital_name'],
                'phone'=>$row['hospital_phone'],'email'=>$row['hospital_email'],'address'=>$row['hospital_address'],
                'city'=>$row['ville'],'province'=>$row['province'],'logo'=>$row['hospital_logo'],
                'services'=>studentHospitalAvailableServices($pdo,(int)$row['hospital_id']),
                'participation'=>[
                    'id'=>(int)$row['participation_id'],'status'=>$row['participation_status'],
                    'accepted_capacity'=>(int)$row['accepted_capacity'],'conditions'=>$row['conditions']
                ]
            ],
            'payment'=>[
                'required'=>$feesRequired,'status'=>$paymentStatus,
                'amount_required'=>$invoiceAmount,'amount_paid'=>round($amountPaid,2),
                'amount_remaining'=>$remaining,'currency'=>$row['invoice_currency']?:$row['devise'],
                'invoice'=>$row['invoice_uuid']!==null?[
                    'uuid'=>$row['invoice_uuid'],'reference'=>$row['invoice_reference'],
                    'status'=>$row['invoice_status'],'issued_at'=>$row['invoice_issued_at'],
                    'due_at'=>$row['invoice_due_at'],'paid_at'=>$row['invoice_paid_at']
                ]:null
            ],
            'placement'=>$row['placement_uuid']!==null?[
                'uuid'=>$row['placement_uuid'],'status'=>$row['placement_status'],
                'start_date'=>$row['placement_start'],'end_date'=>$row['placement_end'],
                'confirmed_at'=>$row['placement_confirmed_at'],
                'cancelled_at'=>$row['placement_cancelled_at'],
                'cancellation_reason'=>$row['placement_cancellation_reason']
            ]:null,
            'admission'=>$row['admission_uuid']!==null?[
                'uuid'=>$row['admission_uuid'],'status'=>$row['admission_status'],
                'admitted_at'=>$row['admitted_at'],'observation'=>$row['admission_observation']
            ]:null,
            'assignment'=>$row['assignment_uuid']!==null?[
                'uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],
                'start_date'=>$row['assignment_start'],'end_date'=>$row['assignment_end'],
                'assigned_at'=>$row['assigned_at'],'ended_at'=>$row['ended_at'],
                'unit'=>['code'=>$row['assignment_unit_code'],'name'=>$row['assignment_unit_name'],'type'=>$row['assignment_unit_type']]
            ]:null,
            'completion'=>[
                'status'=>$row['completion_status'],
                'attendance_rate'=>$row['taux_presence']!==null?(float)$row['taux_presence']:null,
                'final_score'=>$row['note_finale']!==null?(float)$row['note_finale']:null
            ],
            'workflow'=>[
                'status'=>$workflow,'message'=>studentStageWorkflowMessage($workflow),
                'confirmation_managed_by'=>$row['statut']==='EN_ATTENTE_PAIEMENT'?'PAYMENT':'UNIVERSITY'
            ],
            'actions'=>[
                'cancel'=>[
                    'allowed'=>$canCancel,'method'=>'POST',
                    'endpoint'=>'/api/v1/student/reservations/'.$row['uuid'].'/cancel'
                ]
            ]
        ];

        $allItems[]=$item;
        $stats['total']++;
        $bucket=[
            'RESERVEE_TEMPORAIREMENT'=>'temporary','EN_ATTENTE_PAIEMENT'=>'waiting_payment',
            'CONFIRMEE'=>'confirmed','EXPIREE'=>'expired','ANNULEE'=>'cancelled'
        ][$row['statut']]??null;
        if($bucket)$stats[$bucket]++;
        if($canCancel)$stats['can_cancel']++;
    }

    $filtered=array_values(array_filter($allItems,static function(array $item)use(
        $statusFilter,$applicationFilter,$workflowFilter,$paymentFilter,
        $campaignFilter,$hospitalFilter,$actionable
    ):bool{
        if($statusFilter&&!in_array($item['reservation']['status'],$statusFilter,true))return false;
        if($applicationFilter&&!in_array($item['application']['status'],$applicationFilter,true))return false;
        if($workflowFilter&&!in_array($item['workflow']['status'],$workflowFilter,true))return false;
        if($paymentFilter&&!in_array($item['payment']['status'],$paymentFilter,true))return false;
        if($campaignFilter&&(int)$item['campaign']['id']!==$campaignFilter)return false;
        if($hospitalFilter&&(int)$item['hospital']['id']!==$hospitalFilter)return false;
        if($actionable==='CANCEL'&&empty($item['actions']['cancel']['allowed']))return false;
        return true;
    }));

    $filteredTotal=count($filtered);
    $pages=max(1,(int)ceil($filteredTotal/$perPage));
    if($page>$pages)$page=$pages;
    $items=array_slice($filtered,($page-1)*$perPage,$perPage);

    apiResponse(true,'',[
        'items'=>$items,
        'stats'=>$stats,
        'pagination'=>[
            'page'=>$page,'per_page'=>$perPage,'total'=>$filteredTotal,'pages'=>$pages,
            'from'=>$filteredTotal?($page-1)*$perPage+1:0,
            'to'=>$filteredTotal?($page-1)*$perPage+count($items):0
        ],
        'filters'=>[
            'status'=>$statusFilter,'application_status'=>$applicationFilter,
            'workflow_status'=>$workflowFilter,'payment_status'=>$paymentFilter,
            'campaign_id'=>$campaignFilter?:null,'hospital_id'=>$hospitalFilter?:null,
            'actionable'=>$actionable?:null
        ],
        'available_filters'=>[
            'status'=>$reservationStatuses,'application_status'=>$applicationStatuses,
            'workflow_status'=>$workflowStatuses,'payment_status'=>$paymentStatuses,
            'actionable'=>['CANCEL']
        ]
    ]);
}catch(Throwable $e){
    error_log('[API STUDENT RESERVATIONS] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    apiResponse(false,'Une erreur interne empêche le chargement des réservations.',[],500);
}

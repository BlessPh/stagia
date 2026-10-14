<?php
/** Collection en lecture seule des resultats personnels et de leur circuit administratif. */
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$studentId=(int)$student['student_id'];

$allowedDecisions=['VALIDE','A_REPRENDRE','AJOURNE','NON_VALIDE'];
$allowedTransmissionStatuses=['NON_TRANSMIS','ENVOYE','RECU','VALIDE','ARCHIVE'];
$parseList=static function(mixed $value):array{
    $values=is_array($value)?$value:explode(',',(string)$value);
    return array_values(array_unique(array_filter(array_map(
        static fn($item):string=>strtoupper(trim((string)$item)),
        $values
    ))));
};
$decisions=$parseList($_GET['decision']??$_GET['decisions']??[]);
$transmissionStatuses=$parseList($_GET['transmission_status']??$_GET['transmission_statuses']??[]);
if($invalid=array_values(array_diff($decisions,$allowedDecisions))){
    apiResponse(false,'Filtre decision invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedDecisions],422);
}
if($invalid=array_values(array_diff($transmissionStatuses,$allowedTransmissionStatuses))){
    apiResponse(false,'Filtre transmission_status invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedTransmissionStatuses],422);
}

$assignmentUuid=trim((string)($_GET['assignment_uuid']??''));
$campaignUuid=trim((string)($_GET['campaign_uuid']??''));
$hospitalId=max(0,(int)($_GET['hospital_id']??0));
$page=max(1,(int)($_GET['page']??1));
$perPage=max(1,min(100,(int)($_GET['per_page']??20)));

try{
    $where=['comp.student_id=?'];
    $params=[$studentId];
    if($decisions){$where[]='ri.decision IN('.implode(',',array_fill(0,count($decisions),'?')).')';array_push($params,...$decisions);}
    if($transmissionStatuses){$where[]="COALESCE(t.statut,'NON_TRANSMIS') IN(".implode(',',array_fill(0,count($transmissionStatuses),'?')).')';array_push($params,...$transmissionStatuses);}
    if($assignmentUuid!==''){$where[]='a.uuid=?';$params[]=$assignmentUuid;}
    if($campaignUuid!==''){$where[]='c.uuid=?';$params[]=$campaignUuid;}
    if($hospitalId>0){$where[]='h.id=?';$params[]=$hospitalId;}

    $stmt=$pdo->prepare("
        SELECT
            comp.uuid completion_uuid,comp.statut completion_status,comp.note_finale completion_score,
            comp.taux_presence completion_attendance_rate,comp.validated_at completion_validated_at,
            fd.statut deliberation_status,fd.note_finale deliberation_score,fd.decision deliberation_decision,
            fd.appreciation deliberation_appreciation,fd.date_deliberation,fd.validated_at deliberation_validated_at,
            a.uuid assignment_uuid,a.statut assignment_status,a.date_debut assignment_start,a.date_fin assignment_end,
            c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
            h.id hospital_id,h.code hospital_code,h.nom hospital_name,
            hu.id department_id,hu.code department_code,hu.nom department_name,hu.type department_type,
            ri.rotation_count,ri.presence_count,ri.present_count,ri.late_count,ri.absent_count,
            ri.justified_count,ri.guard_count,ri.presence_rate,ri.journal_count,ri.validated_journal_count,
            ri.evaluation_count,ri.final_score,ri.decision,ri.appreciation,ri.created_at result_created_at,
            t.uuid transmission_uuid,t.statut transmission_status,t.transmitted_at,t.received_at,t.validated_at,t.archived_at,
            cert.uuid certificate_uuid,cert.reference certificate_reference,cert.type_document certificate_type,cert.statut certificate_status,cert.generated_at certificate_generated_at
        FROM stage_completions comp
        INNER JOIN stage_assignments a ON a.id=comp.assignment_id
        INNER JOIN stage_campaigns c ON c.id=comp.campaign_id
        INNER JOIN etablissements h ON h.id=comp.host_etablissement_id
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id
        LEFT JOIN stage_final_deliberations fd ON fd.assignment_id=a.id AND fd.statut='VALIDEE'
        LEFT JOIN stage_result_items ri ON ri.id=(
            SELECT ri2.id
            FROM stage_result_items ri2
            INNER JOIN stage_result_transmissions t2 ON t2.id=ri2.transmission_id
            WHERE ri2.assignment_id=a.id AND ri2.student_id=comp.student_id
            ORDER BY t2.id DESC,ri2.id DESC
            LIMIT 1
        )
        LEFT JOIN stage_result_transmissions t ON t.id=ri.transmission_id
        LEFT JOIN stage_certificates cert ON cert.id=(
            SELECT cert2.id
            FROM stage_certificates cert2
            WHERE cert2.completion_id=comp.id AND cert2.statut<>'ANNULE'
            ORDER BY cert2.id DESC
            LIMIT 1
        )
        WHERE ".implode(' AND ',$where)."
        ORDER BY COALESCE(t.validated_at,t.received_at,t.transmitted_at,comp.validated_at,comp.updated_at,comp.created_at) DESC,comp.id DESC
    ");
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $items=[];
    $stats=[
        'total'=>0,'by_decision'=>array_fill_keys($allowedDecisions,0),
        'by_transmission_status'=>array_fill_keys($allowedTransmissionStatuses,0),
        'average_score'=>null,'average_attendance_rate'=>null,'documents_available'=>0
    ];
    $scoreSum=0.0;$scoreCount=0;$attendanceSum=0.0;$attendanceCount=0;

    foreach($rows as $row){
        $transmissionStatus=$row['transmission_status']?:'NON_TRANSMIS';
        $universityValidated=in_array($transmissionStatus,['VALIDE','ARCHIVE'],true);
        $legacyResult=
            $row['completion_status']==='VALIDE'
            &&empty($row['deliberation_status'])
            &&$universityValidated;
        $officialResultAvailable=
            $universityValidated
            &&($row['deliberation_status']==='VALIDEE'||$legacyResult);
        $decision=$officialResultAvailable
            ?($row['deliberation_status']==='VALIDEE'?($row['deliberation_decision']?:null):($row['decision']?:null))
            :null;
        $score=$officialResultAvailable
            ?($row['deliberation_status']==='VALIDEE'&&$row['deliberation_score']!==null
                ?(float)$row['deliberation_score']
                :($row['final_score']!==null?(float)$row['final_score']:($row['completion_score']!==null?(float)$row['completion_score']:null)))
            :null;
        $attendanceRate=$row['presence_rate']!==null?(float)$row['presence_rate']:($row['completion_attendance_rate']!==null?(float)$row['completion_attendance_rate']:null);
        $documentAvailable=$row['certificate_uuid']!==null
            &&$row['certificate_status']==='GENERE'
            &&$row['completion_status']==='VALIDE'
            &&in_array($transmissionStatus,['VALIDE','ARCHIVE'],true);
        $workflowStatus=match(true){
            $legacyResult=>'COMPLETED',
            $row['deliberation_status']!=='VALIDEE'=>'AWAITING_FINAL_DELIBERATION',
            $transmissionStatus==='NON_TRANSMIS'=>'AWAITING_TRANSMISSION',
            $transmissionStatus==='ENVOYE'=>'AWAITING_UNIVERSITY_RECEIPT',
            $transmissionStatus==='RECU'=>'AWAITING_UNIVERSITY_VALIDATION',
            $row['completion_status']!=='VALIDE'=>'AWAITING_HOSPITAL_CLOSURE',
            in_array($transmissionStatus,['VALIDE','ARCHIVE'],true)=>'COMPLETED',
            default=>'IN_PROGRESS'
        };

        $stats['total']++;
        if($decision!==null&&isset($stats['by_decision'][$decision]))$stats['by_decision'][$decision]++;
        if(isset($stats['by_transmission_status'][$transmissionStatus]))$stats['by_transmission_status'][$transmissionStatus]++;
        if($score!==null){$scoreSum+=$score;$scoreCount++;}
        if($attendanceRate!==null){$attendanceSum+=$attendanceRate;$attendanceCount++;}
        if($documentAvailable)$stats['documents_available']++;

        $fileEndpoint=$documentAvailable
            ?rtrim(BASE_URL,'/').'/api/v1/student/certificates/'.rawurlencode((string)$row['certificate_uuid']).'/file'
            :null;
        $items[]=[
            'decision'=>$decision,'score'=>$score,'attendance_rate'=>$attendanceRate,
            'appreciation'=>$officialResultAvailable
                ?($row['deliberation_status']==='VALIDEE'?$row['deliberation_appreciation']:$row['appreciation'])
                :null,
            'result_source'=>$row['deliberation_status']==='VALIDEE'?'FINAL_DELIBERATION':($legacyResult?'LEGACY_CLOSURE':null),
            'metrics'=>[
                'rotations'=>(int)($row['rotation_count']??0),'attendance_records'=>(int)($row['presence_count']??0),
                'present'=>(int)($row['present_count']??0),'late'=>(int)($row['late_count']??0),'absent'=>(int)($row['absent_count']??0),
                'justified'=>(int)($row['justified_count']??0),'guard'=>(int)($row['guard_count']??0),
                'logbook_entries'=>(int)($row['journal_count']??0),'validated_logbook_entries'=>(int)($row['validated_journal_count']??0),
                'evaluations'=>(int)($row['evaluation_count']??0)
            ],
            'administrative_process'=>[
                'status'=>$workflowStatus,'student_action_required'=>false,
                'final_deliberation'=>[
                    'status'=>$row['deliberation_status'],
                    'date'=>$row['date_deliberation'],
                    'validated_at'=>$row['deliberation_validated_at'],
                    'result_available'=>$officialResultAvailable
                ],
                'hospital_closure'=>['status'=>$row['completion_status'],'validated_at'=>$row['completion_validated_at']],
                'university_transmission'=>[
                    'uuid'=>$row['transmission_uuid'],'status'=>$transmissionStatus,
                    'transmitted_at'=>$row['transmitted_at'],'received_at'=>$row['received_at'],
                    'validated_at'=>$row['validated_at'],'archived_at'=>$row['archived_at']
                ]
            ],
            'assignment'=>['uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],'start_date'=>$row['assignment_start'],'end_date'=>$row['assignment_end']],
            'campaign'=>['uuid'=>$row['campaign_uuid'],'code'=>$row['campaign_code'],'title'=>$row['campaign_title']],
            'hospital'=>['id'=>(int)$row['hospital_id'],'code'=>$row['hospital_code'],'name'=>$row['hospital_name']],
            'department'=>$row['department_id']!==null?['id'=>(int)$row['department_id'],'code'=>$row['department_code'],'name'=>$row['department_name'],'type'=>$row['department_type']]:null,
            'document'=>$row['certificate_uuid']!==null?[
                'uuid'=>$row['certificate_uuid'],'reference'=>$row['certificate_reference'],'type'=>$row['certificate_type'],
                'status'=>$row['certificate_status'],'available'=>$documentAvailable,'generated_at'=>$row['certificate_generated_at'],
                'file_endpoint'=>$fileEndpoint,'download_endpoint'=>$fileEndpoint?$fileEndpoint.'?download=1':null
            ]:null
        ];
    }
    $stats['average_score']=$scoreCount?round($scoreSum/$scoreCount,2):null;
    $stats['average_attendance_rate']=$attendanceCount?round($attendanceSum/$attendanceCount,2):null;

    $total=count($items);$pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;
    $offset=($page-1)*$perPage;$pageItems=array_slice($items,$offset,$perPage);
    apiResponse(true,'',[
        'items'=>$pageItems,'stats'=>$stats,
        'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total?$offset+1:0,'to'=>$total?$offset+count($pageItems):0],
        'filters'=>['decisions'=>$decisions,'transmission_statuses'=>$transmissionStatuses,'assignment_uuid'=>$assignmentUuid?:null,'campaign_uuid'=>$campaignUuid?:null,'hospital_id'=>$hospitalId?:null],
        'available_filters'=>['decisions'=>$allowedDecisions,'transmission_statuses'=>$allowedTransmissionStatuses,'per_page_max'=>100]
    ]);
}catch(Throwable $e){
    error_log('[STUDENT RESULTS] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empeche le chargement des resultats.',[],500);
}

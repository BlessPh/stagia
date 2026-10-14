<?php
/** Collection en lecture seule des clotures hospitalieres de l'etudiant. */
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$studentId=(int)$student['student_id'];

$allowedStatuses=['EN_PREPARATION','PRET','VALIDE','REFUSE','ANNULE'];
$parseList=static function(mixed $value):array{
    $values=is_array($value)?$value:explode(',',(string)$value);
    return array_values(array_unique(array_filter(array_map(
        static fn($item):string=>strtoupper(trim((string)$item)),
        $values
    ))));
};
$statuses=$parseList($_GET['status']??$_GET['statuses']??[]);
if($invalid=array_values(array_diff($statuses,$allowedStatuses))){
    apiResponse(false,'Filtre status invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedStatuses],422);
}

$assignmentUuid=trim((string)($_GET['assignment_uuid']??''));
$campaignUuid=trim((string)($_GET['campaign_uuid']??''));
$hospitalId=max(0,(int)($_GET['hospital_id']??0));
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$validDate=static function(string $value):bool{
    if($value==='')return true;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date!==false&&$date->format('Y-m-d')===$value;
};
if(!$validDate($dateFrom))apiResponse(false,'Filtre date_from invalide. Format attendu : YYYY-MM-DD.',[],422);
if(!$validDate($dateTo))apiResponse(false,'Filtre date_to invalide. Format attendu : YYYY-MM-DD.',[],422);
if($dateFrom!==''&&$dateTo!==''&&$dateFrom>$dateTo)apiResponse(false,'La date de debut ne peut pas etre posterieure a la date de fin.',[],422);

$page=max(1,(int)($_GET['page']??1));
$perPage=max(1,min(100,(int)($_GET['per_page']??20)));

try{
    $where=['comp.student_id=?'];
    $params=[$studentId];
    if($statuses){$where[]='comp.statut IN('.implode(',',array_fill(0,count($statuses),'?')).')';array_push($params,...$statuses);}
    if($assignmentUuid!==''){$where[]='a.uuid=?';$params[]=$assignmentUuid;}
    if($campaignUuid!==''){$where[]='c.uuid=?';$params[]=$campaignUuid;}
    if($hospitalId>0){$where[]='h.id=?';$params[]=$hospitalId;}
    $activityDate='DATE(COALESCE(comp.validated_at,comp.prepared_at,comp.updated_at,comp.created_at))';
    if($dateFrom!==''){$where[]=$activityDate.'>=?';$params[]=$dateFrom;}
    if($dateTo!==''){$where[]=$activityDate.'<=?';$params[]=$dateTo;}

    $stmt=$pdo->prepare("
        SELECT
            comp.*,
            a.uuid assignment_uuid,a.statut assignment_status,a.date_debut assignment_start,a.date_fin assignment_end,
            c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
            h.id hospital_id,h.code hospital_code,h.nom hospital_name,h.telephone hospital_phone,h.email hospital_email,
            hu.id department_id,hu.code department_code,hu.nom department_name,hu.type department_type,
            validator.id validator_id,TRIM(CONCAT_WS(' ',validator.prenom,validator.nom,validator.postnom)) validator_name,
            fd.statut deliberation_status,fd.date_deliberation,fd.validated_at deliberation_validated_at,
            (
                SELECT t.statut FROM stage_result_items ri
                INNER JOIN stage_result_transmissions t ON t.id=ri.transmission_id
                WHERE ri.assignment_id=a.id
                ORDER BY t.id DESC,ri.id DESC LIMIT 1
            ) university_result_status,
            (
                SELECT t.validated_at FROM stage_result_items ri
                INNER JOIN stage_result_transmissions t ON t.id=ri.transmission_id
                WHERE ri.assignment_id=a.id
                ORDER BY t.id DESC,ri.id DESC LIMIT 1
            ) university_validated_at,
            (SELECT COUNT(DISTINCT att.rotation_id) FROM stage_attendances att WHERE att.assignment_id=a.id) attendance_rotation_count,
            (SELECT COUNT(DISTINCT log.rotation_id) FROM stage_logbook_entries log WHERE log.assignment_id=a.id AND log.statut='VALIDE') validated_logbook_rotation_count,
            (SELECT COUNT(DISTINCT ev.rotation_id) FROM stage_evaluations ev WHERE ev.assignment_id=a.id AND ev.type_evaluation='FIN_ROTATION' AND ev.statut='FINALISEE') finalized_evaluation_rotation_count
        FROM stage_completions comp
        INNER JOIN stage_assignments a ON a.id=comp.assignment_id
        INNER JOIN stage_campaigns c ON c.id=comp.campaign_id
        INNER JOIN etablissements h ON h.id=comp.host_etablissement_id
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id
        LEFT JOIN users validator ON validator.id=comp.validated_by
        LEFT JOIN stage_final_deliberations fd ON fd.assignment_id=a.id AND fd.statut='VALIDEE'
        WHERE ".implode(' AND ',$where)."
        ORDER BY COALESCE(comp.validated_at,comp.prepared_at,comp.updated_at,comp.created_at) DESC,comp.id DESC
    ");
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $items=[];
    $stats=['total'=>0,'by_status'=>array_fill_keys($allowedStatuses,0),'ready'=>0,'validated'=>0,'average_score'=>null,'average_attendance_rate'=>null];
    $scoreSum=0.0;$scoreCount=0;$attendanceSum=0.0;$attendanceCount=0;
    $today=date('Y-m-d');

    foreach($rows as $row){
        $status=(string)$row['statut'];
        $totalRotations=(int)$row['total_rotations'];
        $completedRotations=(int)$row['rotations_terminees'];
        $attendanceRotations=(int)$row['attendance_rotation_count'];
        $logbookRotations=(int)$row['validated_logbook_rotation_count'];
        $evaluationRotations=(int)$row['finalized_evaluation_rotation_count'];
        $blockers=[];
        if($status==='EN_PREPARATION'){
            if($row['assignment_end']&&$today<$row['assignment_end'])$blockers[]=['code'=>'STAGE_PERIOD_NOT_ENDED','message'=>'La periode du stage n’est pas encore terminee.'];
            if($totalRotations===0)$blockers[]=['code'=>'NO_ROTATION','message'=>'Aucune rotation n’est encore enregistree.'];
            elseif($completedRotations<$totalRotations)$blockers[]=['code'=>'ROTATIONS_PENDING','message'=>($totalRotations-$completedRotations).' rotation(s) restent a terminer.'];
            if($totalRotations>0&&$attendanceRotations<$totalRotations)$blockers[]=['code'=>'ATTENDANCE_INCOMPLETE','message'=>'Les presences ne couvrent pas encore toutes les rotations.'];
            if($totalRotations>0&&$logbookRotations<$totalRotations)$blockers[]=['code'=>'LOGBOOK_INCOMPLETE','message'=>'Un journal valide manque pour certaines rotations.'];
            if((int)$row['total_journaux']>(int)$row['journaux_valides'])$blockers[]=['code'=>'LOGBOOK_PENDING','message'=>'Certains journaux attendent encore leur validation.'];
            if($totalRotations>0&&$evaluationRotations<$totalRotations)$blockers[]=['code'=>'FINAL_EVALUATION_INCOMPLETE','message'=>'Une evaluation finale manque pour certaines rotations.'];
            if($row['deliberation_status']!=='VALIDEE')$blockers[]=['code'=>'FINAL_DELIBERATION_PENDING','message'=>'La deliberation finale hospitaliere doit etre validee.'];
            if(!in_array($row['university_result_status'],['VALIDE','ARCHIVE'],true)||empty($row['university_validated_at']))$blockers[]=['code'=>'UNIVERSITY_VALIDATION_PENDING','message'=>'Le resultat doit etre transmis puis valide par l’universite.'];
        }

        $score=$status==='VALIDE'&&$row['note_finale']!==null?(float)$row['note_finale']:null;
        $attendanceRate=$row['taux_presence']!==null?(float)$row['taux_presence']:null;
        if($score!==null){$scoreSum+=$score;$scoreCount++;}
        if($attendanceRate!==null){$attendanceSum+=$attendanceRate;$attendanceCount++;}
        $stats['total']++;
        if(isset($stats['by_status'][$status]))$stats['by_status'][$status]++;
        if($status==='PRET')$stats['ready']++;
        if($status==='VALIDE')$stats['validated']++;

        $items[]=[
            'uuid'=>$row['uuid'],'status'=>$status,
            'score'=>$score,'attendance_rate'=>$attendanceRate,
            'final_appreciation'=>$row['appreciation_finale'],'decision_comment'=>$row['decision_commentaire'],
            'prepared_at'=>$row['prepared_at'],'validated_at'=>$row['validated_at'],
            'final_deliberation'=>['status'=>$row['deliberation_status'],'date'=>$row['date_deliberation'],'validated_at'=>$row['deliberation_validated_at']],
            'university_validation'=>['status'=>$row['university_result_status']?:'NON_TRANSMIS','validated_at'=>$row['university_validated_at']],
            'progress'=>[
                'rotations'=>['total'=>$totalRotations,'completed'=>$completedRotations],
                'attendance'=>['records'=>(int)$row['total_presences'],'covered_rotations'=>$attendanceRotations],
                'logbook'=>['total'=>(int)$row['total_journaux'],'validated'=>(int)$row['journaux_valides'],'covered_rotations'=>$logbookRotations],
                'final_evaluations'=>['completed_rotations'=>$evaluationRotations]
            ],
            'readiness'=>['ready'=>in_array($status,['PRET','VALIDE'],true),'blockers'=>$blockers],
            'validator'=>$row['validator_id']!==null?['user_id'=>(int)$row['validator_id'],'name'=>$row['validator_name']]:null,
            'assignment'=>['uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],'start_date'=>$row['assignment_start'],'end_date'=>$row['assignment_end']],
            'campaign'=>['uuid'=>$row['campaign_uuid'],'code'=>$row['campaign_code'],'title'=>$row['campaign_title']],
            'hospital'=>['id'=>(int)$row['hospital_id'],'code'=>$row['hospital_code'],'name'=>$row['hospital_name'],'phone'=>$row['hospital_phone'],'email'=>$row['hospital_email']],
            'department'=>$row['department_id']!==null?['id'=>(int)$row['department_id'],'code'=>$row['department_code'],'name'=>$row['department_name'],'type'=>$row['department_type']]:null
        ];
    }
    $stats['average_score']=$scoreCount?round($scoreSum/$scoreCount,2):null;
    $stats['average_attendance_rate']=$attendanceCount?round($attendanceSum/$attendanceCount,2):null;

    $total=count($items);$pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;
    $offset=($page-1)*$perPage;$pageItems=array_slice($items,$offset,$perPage);
    apiResponse(true,'',[
        'items'=>$pageItems,'stats'=>$stats,
        'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total?$offset+1:0,'to'=>$total?$offset+count($pageItems):0],
        'filters'=>['statuses'=>$statuses,'assignment_uuid'=>$assignmentUuid?:null,'campaign_uuid'=>$campaignUuid?:null,'hospital_id'=>$hospitalId?:null,'date_from'=>$dateFrom?:null,'date_to'=>$dateTo?:null],
        'available_filters'=>['statuses'=>$allowedStatuses,'per_page_max'=>100]
    ]);
}catch(Throwable $e){
    error_log('[STUDENT COMPLETIONS] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empeche le chargement des clotures.',[],500);
}

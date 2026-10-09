<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$studentId=(int)$student['student_id'];
$allowedTypes=['CONTINUE','MI_ROTATION','FIN_ROTATION'];
$allowedStatuses=['VALIDEE','FINALISEE'];
$parseList=static function(mixed $value):array{
    $values=is_array($value)?$value:explode(',',(string)$value);
    return array_values(array_unique(array_filter(array_map(static fn($v):string=>strtoupper(trim((string)$v)),$values))));
};
$types=$parseList($_GET['type']??$_GET['types']??[]);
$statuses=$parseList($_GET['status']??$_GET['statuses']??$allowedStatuses);
if($invalid=array_values(array_diff($types,$allowedTypes)))apiResponse(false,'Filtre type invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedTypes],422);
if($invalid=array_values(array_diff($statuses,$allowedStatuses)))apiResponse(false,'Filtre status invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedStatuses],422);
if(!$statuses)$statuses=$allowedStatuses;
$assignmentUuid=trim((string)($_GET['assignment_uuid']??''));
$rotationUuid=trim((string)($_GET['rotation_uuid']??''));
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$validDate=static function(string $value):bool{
    if($value==='')return true;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date!==false&&$date->format('Y-m-d')===$value;
};
if(!$validDate($dateFrom))apiResponse(false,'Filtre date_from invalide. Format attendu : YYYY-MM-DD.',[],422);
if(!$validDate($dateTo))apiResponse(false,'Filtre date_to invalide. Format attendu : YYYY-MM-DD.',[],422);
if($dateFrom!==''&&$dateTo!==''&&$dateFrom>$dateTo)apiResponse(false,'La date de début ne peut pas être postérieure à la date de fin.',[],422);
$page=max(1,(int)($_GET['page']??1));
$perPage=max(1,min(100,(int)($_GET['per_page']??20)));

try{
    $where=['se.student_id=?'];$params=[$studentId];
    $where[]='ev.statut IN('.implode(',',array_fill(0,count($statuses),'?')).')';array_push($params,...$statuses);
    if($types){$where[]='ev.type_evaluation IN('.implode(',',array_fill(0,count($types),'?')).')';array_push($params,...$types);}
    if($assignmentUuid!==''){$where[]='a.uuid=?';$params[]=$assignmentUuid;}
    if($rotationUuid!==''){$where[]='rot.uuid=?';$params[]=$rotationUuid;}
    $dateExpression='DATE(COALESCE(ev.evaluated_at,ev.validated_at,ev.finalized_at,ev.updated_at,ev.created_at))';
    if($dateFrom!==''){$where[]=$dateExpression.'>=?';$params[]=$dateFrom;}
    if($dateTo!==''){$where[]=$dateExpression.'<=?';$params[]=$dateTo;}
    $sql="SELECT ev.*,a.uuid assignment_uuid,a.statut assignment_status,a.date_debut assignment_start,a.date_fin assignment_end,
        c.code campaign_code,c.titre campaign_title,h.code hospital_code,h.nom hospital_name,
        rot.uuid rotation_uuid,rot.sequence_no rotation_sequence,hu.id unit_id,hu.code unit_code,hu.nom unit_name,hu.type unit_type,
        evaluator.id evaluator_id,TRIM(CONCAT_WS(' ',evaluator.prenom,evaluator.nom,evaluator.postnom)) evaluator_name,
        (SELECT eu.fonction FROM etablissement_users eu WHERE eu.user_id=evaluator.id AND eu.etablissement_id=a.host_etablissement_id ORDER BY eu.principal DESC,eu.id DESC LIMIT 1) evaluator_function
        FROM stage_evaluations ev JOIN stage_assignments a ON a.id=ev.assignment_id
        JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id JOIN stage_campaigns c ON c.id=app.campaign_id
        JOIN etablissements h ON h.id=a.host_etablissement_id LEFT JOIN stage_rotations rot ON rot.id=ev.rotation_id
        LEFT JOIN host_units hu ON hu.id=rot.host_unit_id LEFT JOIN users evaluator ON evaluator.id=ev.evaluator_user_id
        WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(ev.evaluated_at,ev.validated_at,ev.finalized_at,ev.updated_at,ev.created_at) DESC,ev.id DESC";
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $scoreStmt=$pdo->prepare('SELECT sc.code,sc.nom,sc.categorie,es.note,es.note_max,es.poids,es.commentaire FROM stage_evaluation_scores es LEFT JOIN stage_competencies sc ON sc.id=es.competency_id WHERE es.evaluation_id=? ORDER BY es.id');
    $items=[];$notes=[];$stats=['total'=>0,'continuous'=>0,'mid_rotation'=>0,'final_rotation'=>0,'validated'=>0,'finalized'=>0,'average'=>null];
    foreach($rows as $row){
        $scoreStmt->execute([(int)$row['id']]);$scores=$scoreStmt->fetchAll(PDO::FETCH_ASSOC);$scoreTotal=0.0;$scoreMaximum=0.0;
        foreach($scores as &$score){$score['note']=(float)$score['note'];$score['note_max']=(float)$score['note_max'];$score['poids']=(float)$score['poids'];$scoreTotal+=$score['note'];$scoreMaximum+=$score['note_max'];}unset($score);
        $type=strtoupper((string)$row['type_evaluation']);$status=strtoupper((string)$row['statut']);$note=$row['note_finale']!==null?(float)$row['note_finale']:null;if($note!==null)$notes[]=$note;
        $stats['total']++;if($type==='CONTINUE')$stats['continuous']++;elseif($type==='MI_ROTATION')$stats['mid_rotation']++;elseif($type==='FIN_ROTATION')$stats['final_rotation']++;if($status==='VALIDEE')$stats['validated']++;if($status==='FINALISEE')$stats['finalized']++;
        $items[]=['uuid'=>$row['uuid'],'type'=>$type,'status'=>$status,'note'=>$note,'appreciation'=>$row['appreciation'],'strengths'=>$row['points_forts'],'improvement_areas'=>$row['axes_amelioration'],
            'evaluated_at'=>$row['evaluated_at'],'submitted_at'=>$row['submitted_at'],'validated_at'=>$row['validated_at'],'finalized_at'=>$row['finalized_at'],
            'score_summary'=>['total'=>round($scoreTotal,2),'maximum'=>round($scoreMaximum,2),'percentage'=>$scoreMaximum>0?round(($scoreTotal/$scoreMaximum)*100,2):null],'scores'=>$scores,
            'evaluator'=>$row['evaluator_id']!==null?['user_id'=>(int)$row['evaluator_id'],'name'=>$row['evaluator_name'],'function'=>$row['evaluator_function']?:$row['evaluator_role']]:null,
            'rotation'=>$row['rotation_uuid']!==null?['uuid'=>$row['rotation_uuid'],'sequence'=>(int)$row['rotation_sequence']]:null,
            'assignment'=>['uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],'start_date'=>$row['assignment_start'],'end_date'=>$row['assignment_end']],
            'campaign'=>['code'=>$row['campaign_code'],'title'=>$row['campaign_title']],'hospital'=>['code'=>$row['hospital_code'],'name'=>$row['hospital_name']],
            'unit'=>$row['unit_id']!==null?['id'=>(int)$row['unit_id'],'code'=>$row['unit_code'],'name'=>$row['unit_name'],'type'=>$row['unit_type']]:null];
    }
    if($notes)$stats['average']=round(array_sum($notes)/count($notes),2);
    $finalEvaluation=null;foreach($items as $item){if($item['type']==='FIN_ROTATION'){$finalEvaluation=$item;break;}}
    $total=count($items);$pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;$offset=($page-1)*$perPage;$pageItems=array_slice($items,$offset,$perPage);
    apiResponse(true,'',['items'=>$pageItems,'stats'=>$stats,'final_evaluation'=>$finalEvaluation,
        'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total?$offset+1:0,'to'=>$total?$offset+count($pageItems):0],
        'filters'=>['types'=>$types,'statuses'=>$statuses,'assignment_uuid'=>$assignmentUuid?:null,'rotation_uuid'=>$rotationUuid?:null,'date_from'=>$dateFrom?:null,'date_to'=>$dateTo?:null],
        'available_filters'=>['types'=>$allowedTypes,'statuses'=>$allowedStatuses,'per_page_max'=>100]]);
}catch(Throwable $e){
    error_log('[STUDENT EVALUATIONS] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche le chargement des évaluations.',[],500);
}

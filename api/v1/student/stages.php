<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-execution.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$studentId=(int)$student['student_id'];

/** Accepte paramètre simple, tableau PHP et liste séparée par des virgules. */
function studentStageFilterValues(array $names,bool $uppercase=false):array{
    $values=[];
    foreach($names as $name){
        if(!array_key_exists($name,$_GET))continue;
        $raw=is_array($_GET[$name])?$_GET[$name]:[$_GET[$name]];
        foreach($raw as $value){
            foreach(explode(',',(string)$value) as $part){
                $part=trim($part);
                if($part==='')continue;
                $values[]=$uppercase?strtoupper($part):$part;
            }
        }
    }
    return array_values(array_unique($values));
}

function studentStageIntegerFilters(array $names):array{
    return array_values(array_unique(array_filter(
        array_map('intval',studentStageFilterValues($names)),
        static fn(int $id):bool=>$id>0
    )));
}

function studentStageDateFilter(string $name):?string{
    $value=trim((string)($_GET[$name]??''));
    if($value==='')return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$date||$date->format('Y-m-d')!==$value)
        apiResponse(false,"Le paramètre {$name} doit respecter le format YYYY-MM-DD.",[],422);
    return $value;
}

function studentStageNormalizedUnitType(?string $type):string{
    return strtoupper(strtr(trim((string)$type),[
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'E','è'=>'E','ê'=>'E','ë'=>'E'
    ]));
}

try{
    $statusAliases=[
        'PLANIFIE'=>'PLANIFIEE','PLANIFIEE'=>'PLANIFIEE','ACTIVE'=>'ACTIVE','EN_COURS'=>'ACTIVE',
        'TERMINE'=>'TERMINEE','TERMINEE'=>'TERMINEE','ANNULE'=>'ANNULEE','ANNULEE'=>'ANNULEE'
    ];
    $requestedStatuses=studentStageFilterValues(['status','status[]','statuses','assignment_status'],true);
    $statuses=[];
    foreach($requestedStatuses as $status){
        if(!isset($statusAliases[$status]))apiResponse(false,"Statut d’affectation invalide : {$status}.",[],422);
        $statuses[]=$statusAliases[$status];
    }
    /* Compatibilité : sans filtre, les affectations annulées restent masquées. */
    $statuses=array_values(array_unique($statuses?:['PLANIFIEE','ACTIVE','TERMINEE']));

    $allowedRotationStatuses=['PLANIFIEE','ACTIVE','TERMINEE','ANNULEE'];
    $rotationStatuses=studentStageFilterValues(['rotation_status','rotation_status[]','rotation_statuses'],true);
    foreach($rotationStatuses as $status){
        if(!in_array($status,$allowedRotationStatuses,true))apiResponse(false,"Statut de rotation invalide : {$status}.",[],422);
    }

    $campaignIds=studentStageIntegerFilters(['campaign_id','campaign_id[]','campaign_ids']);
    $hospitalIds=studentStageIntegerFilters(['hospital_id','hospital_id[]','hospital_ids']);
    $dateFrom=studentStageDateFilter('date_from');
    $dateTo=studentStageDateFilter('date_to');
    if($dateFrom&&$dateTo&&$dateFrom>$dateTo)apiResponse(false,'date_from doit précéder date_to.',[],422);

    $allowedIncludes=['rotations','supervisors','execution_access','summary'];
    $includes=studentStageFilterValues(['include','include[]','includes']);
    if(!$includes)$includes=$allowedIncludes;
    foreach($includes as $include){
        if(!in_array($include,$allowedIncludes,true))apiResponse(false,"Inclusion inconnue : {$include}.",[],422);
    }

    $where=['se.student_id=?'];
    $params=[$studentId];
    $placeholders=static fn(array $values):string=>implode(',',array_fill(0,count($values),'?'));

    $where[]='a.statut IN('.$placeholders($statuses).')';
    array_push($params,...$statuses);
    if($campaignIds){$where[]='app.campaign_id IN('.$placeholders($campaignIds).')';array_push($params,...$campaignIds);}
    if($hospitalIds){$where[]='a.host_etablissement_id IN('.$placeholders($hospitalIds).')';array_push($params,...$hospitalIds);}
    if($dateFrom){$where[]='a.date_fin>=?';$params[]=$dateFrom;}
    if($dateTo){$where[]='a.date_debut<=?';$params[]=$dateTo;}
    if($rotationStatuses){
        $where[]='EXISTS(SELECT 1 FROM stage_rotations rf WHERE rf.assignment_id=a.id AND rf.statut IN('.$placeholders($rotationStatuses).'))';
        array_push($params,...$rotationStatuses);
    }

    $sql="SELECT DISTINCT
            a.id assignment_id,a.uuid assignment_uuid,a.statut assignment_status,
            a.date_debut,a.date_fin,a.observation,a.assigned_at,a.ended_at,
            ad.id admission_id,ad.statut admission_status,ad.coordination_unit_id,
            app.id application_id,app.campaign_id,app.host_etablissement_id,
            c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
            st.code stage_type_code,st.libelle stage_type_label,
            h.id hospital_id,h.code hospital_code,h.nom hospital_name,
            h.telephone hospital_phone,h.email hospital_email,h.ville hospital_city,h.province hospital_province,
            department.id department_id,department.code department_code,
            department.nom department_name,department.type department_type,department.actif department_active,
            assigned_unit.id initial_unit_id,assigned_unit.code initial_unit_code,
            assigned_unit.nom initial_unit_name,assigned_unit.type initial_unit_type,
            ae.id academic_enrollment_id,p.id promotion_id,p.nom promotion_name,
            g.id group_id,g.uuid group_uuid,g.code group_code,g.nom group_name,g.statut group_status,
            comp.statut completion_status,comp.taux_presence,comp.note_finale,
            comp.total_rotations,comp.total_presences,comp.total_retards,comp.total_absences,
            comp.total_justifiees,comp.total_journaux,comp.journaux_valides
        FROM student_enrollments se
        JOIN student_academic_enrollments ae ON ae.enrollment_id=se.id
        JOIN stage_applications app ON app.academic_enrollment_id=ae.id
        JOIN stage_reservations sr ON sr.application_id=app.id AND sr.statut='CONFIRMEE'
        JOIN stage_admissions ad ON ad.reservation_id=sr.id AND ad.statut IN('ADMIS','EN_COURS','TERMINE')
        JOIN stage_assignments a ON a.admission_id=ad.id AND a.host_etablissement_id=app.host_etablissement_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN stage_types st ON st.id=c.stage_type_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN host_units department ON department.id=ad.coordination_unit_id AND department.host_etablissement_id=a.host_etablissement_id
        LEFT JOIN host_units assigned_unit ON assigned_unit.id=a.host_unit_id
        LEFT JOIN promotions p ON p.id=ae.promotion_id
        LEFT JOIN stage_groups g ON g.id=(
            SELECT gs2.group_id
            FROM stage_group_students gs2
            JOIN stage_groups g2 ON g2.id=gs2.group_id
            WHERE gs2.campaign_id=app.campaign_id
              AND gs2.academic_enrollment_id=ae.id
              AND g2.host_etablissement_id=app.host_etablissement_id
              AND g2.statut<>'ANNULE'
            ORDER BY g2.id DESC LIMIT 1
        )
        LEFT JOIN stage_completions comp ON comp.assignment_id=a.id
        WHERE ".implode(' AND ',$where)."
        ORDER BY FIELD(a.statut,'ACTIVE','PLANIFIEE','TERMINEE','ANNULEE'),a.date_debut,a.id";
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $rotationSql="SELECT
            r.id rotation_id,r.uuid rotation_uuid,r.group_id,r.sequence_no,
            r.date_debut,r.date_fin,r.statut,r.objectifs,r.observation,
            target.id target_id,target.code target_code,target.nom target_name,target.type target_type,target.parent_id target_parent_id,
            parent.id parent_id,parent.code parent_code,parent.nom parent_name,parent.type parent_type,parent.parent_id parent_department_id,
            supervisor.id supervisor_user_id,
            CONCAT_WS(' ',supervisor.prenom,supervisor.nom,supervisor.postnom) supervisor_name,
            COALESCE(NULLIF(eu.fonction,''),rs.role_supervision,'Encadreur') supervisor_function
        FROM stage_rotations r
        JOIN host_units target ON target.id=r.host_unit_id
        LEFT JOIN host_units parent ON parent.id=target.parent_id
        LEFT JOIN stage_rotation_supervisors rs ON rs.id=(SELECT rs2.id FROM stage_rotation_supervisors rs2 WHERE rs2.rotation_id=r.id AND rs2.actif=1 ORDER BY rs2.principal DESC,rs2.id LIMIT 1)
        LEFT JOIN users supervisor ON supervisor.id=rs.user_id
        LEFT JOIN etablissement_users eu ON eu.user_id=supervisor.id AND eu.etablissement_id=r.host_etablissement_id
        WHERE r.assignment_id=?";
    if($rotationStatuses)$rotationSql.=' AND r.statut IN('.$placeholders($rotationStatuses).')';
    else $rotationSql.=" AND r.statut<>'ANNULEE'";
    $rotationSql.=' ORDER BY r.sequence_no,r.date_debut,r.id';
    $rotationStmt=$pdo->prepare($rotationSql);

    $today=date('Y-m-d');
    $items=[];
    $summary=['total'=>0,'by_status'=>['PLANIFIEE'=>0,'ACTIVE'=>0,'TERMINEE'=>0,'ANNULEE'=>0],
        'by_workflow_status'=>['PLANIFIE'=>0,'EN_COURS'=>0,'TERMINE'=>0,'VALIDE'=>0]];

    foreach($rows as $row){
        foreach(['assignment_id','admission_id','application_id','campaign_id','host_etablissement_id','hospital_id','academic_enrollment_id'] as $key)$row[$key]=(int)$row[$key];
        foreach(['coordination_unit_id','department_id','initial_unit_id','promotion_id','group_id'] as $key)$row[$key]=$row[$key]!==null?(int)$row[$key]:null;
        foreach(['total_rotations','total_presences','total_retards','total_absences','total_justifiees','total_journaux','journaux_valides'] as $key)$row[$key]=(int)($row[$key]??0);
        foreach(['taux_presence','note_finale'] as $key)$row[$key]=$row[$key]!==null?(float)$row[$key]:null;

        $workflowStatus=$row['completion_status']==='VALIDE'?'VALIDE':match($row['assignment_status']){
            'ACTIVE'=>'EN_COURS','PLANIFIEE'=>'PLANIFIE','TERMINEE'=>'TERMINE',default=>$row['assignment_status']
        };

        $rotations=[];
        if(in_array('rotations',$includes,true)){
            $rotationParams=[$row['assignment_id']];
            if($rotationStatuses)array_push($rotationParams,...$rotationStatuses);
            $rotationStmt->execute($rotationParams);
            foreach($rotationStmt->fetchAll(PDO::FETCH_ASSOC) as $index=>$rotation){
                $targetType=studentStageNormalizedUnitType($rotation['target_type']);
                $parentType=studentStageNormalizedUnitType($rotation['parent_type']);
                $isUnit=in_array($targetType,['UNITE','UNIT'],true);
                $rotationDepartmentId=$isUnit?(int)($rotation['parent_department_id']??0):(int)($rotation['target_parent_id']??0);
                $validServiceTarget=$targetType==='SERVICE'||($isUnit&&$parentType==='SERVICE');
                $belongsToDepartment=$row['department_id']!==null&&$rotationDepartmentId===(int)$row['department_id'];
                $service=$isUnit&&$rotation['parent_id']!==null
                    ?['id'=>(int)$rotation['parent_id'],'code'=>$rotation['parent_code'],'name'=>$rotation['parent_name'],'type'=>$rotation['parent_type']]
                    :['id'=>(int)$rotation['target_id'],'code'=>$rotation['target_code'],'name'=>$rotation['target_name'],'type'=>$rotation['target_type']];
                $unit=$isUnit?['id'=>(int)$rotation['target_id'],'code'=>$rotation['target_code'],'name'=>$rotation['target_name'],'type'=>$rotation['target_type']]:null;
                $supervisor=null;
                if(in_array('supervisors',$includes,true)&&$rotation['supervisor_user_id']!==null){
                    $supervisor=['user_id'=>(int)$rotation['supervisor_user_id'],'name'=>trim((string)$rotation['supervisor_name']),'function'=>$rotation['supervisor_function']];
                }
                $rotations[]=[
                    'id'=>(int)$rotation['rotation_id'],'uuid'=>$rotation['rotation_uuid'],
                    'position'=>$index+1,'sequence'=>(int)$rotation['sequence_no'],'status'=>$rotation['statut'],
                    'period'=>['start_date'=>$rotation['date_debut'],'end_date'=>$rotation['date_fin']],
                    'service'=>$service,'unit'=>$unit,'supervisor'=>$supervisor,
                    'objectives'=>$rotation['objectifs'],'observation'=>$rotation['observation'],
                    'integrity'=>[
                        'valid'=>$validServiceTarget&&$belongsToDepartment,
                        'service_target_valid'=>$validServiceTarget,
                        'belongs_to_assignment_department'=>$belongsToDepartment
                    ]
                ];
            }
        }

        $currentRotation=null;$nextRotation=null;
        foreach($rotations as $rotation){
            if($currentRotation===null&&($rotation['status']==='ACTIVE'||($rotation['status']!=='TERMINEE'&&$rotation['period']['start_date']<=$today&&$rotation['period']['end_date']>=$today)))$currentRotation=$rotation;
            if($nextRotation===null&&$rotation['status']==='PLANIFIEE'&&$rotation['period']['start_date']>$today)$nextRotation=$rotation;
        }

        $department=$row['department_id']!==null?[
            'id'=>$row['department_id'],'code'=>$row['department_code'],'name'=>$row['department_name'],
            'type'=>$row['department_type'],'active'=>(bool)$row['department_active']
        ]:null;

        /* Les clés historiques sont conservées pour ne pas casser les clients déjà déployés. */
        $item=$row;
        $item['workflow_status']=$workflowStatus;
        $item['department']=$department;
        $departmentType=$department?studentStageNormalizedUnitType($department['type']):'';
        $item['department_integrity']=[
            'valid'=>$department!==null&&!empty($department['active'])&&in_array($departmentType,['DEPARTEMENT','COORDINATION'],true),
            'source'=>'stage_admissions.coordination_unit_id'
        ];
        $item['hospital']=['id'=>$row['hospital_id'],'code'=>$row['hospital_code'],'name'=>$row['hospital_name'],
            'phone'=>$row['hospital_phone'],'email'=>$row['hospital_email'],
            'location'=>['city'=>$row['hospital_city'],'province'=>$row['hospital_province']]];
        $item['campaign']=['id'=>$row['campaign_id'],'uuid'=>$row['campaign_uuid'],'code'=>$row['campaign_code'],
            'title'=>$row['campaign_title'],'stage_type'=>['code'=>$row['stage_type_code'],'label'=>$row['stage_type_label']]];
        $item['period']=['start_date'=>$row['date_debut'],'end_date'=>$row['date_fin']];
        $item['planning']=['current_rotation'=>$currentRotation,'next_rotation'=>$nextRotation,'rotations'=>$rotations,'total'=>count($rotations)];
        $item['rotations']=$rotations;
        $item['execution_access']=in_array('execution_access',$includes,true)?stageExecutionAccess($pdo,$row['assignment_id']):null;
        $items[]=$item;

        $summary['total']++;
        if(isset($summary['by_status'][$row['assignment_status']]))$summary['by_status'][$row['assignment_status']]++;
        if(isset($summary['by_workflow_status'][$workflowStatus]))$summary['by_workflow_status'][$workflowStatus]++;
    }

    apiResponse(true,'',[
        'student'=>['id'=>$studentId,'stagia_code'=>$student['stagia_code']],
        'items'=>$items,
        'filters'=>['statuses'=>$statuses,'rotation_statuses'=>$rotationStatuses,'campaign_ids'=>$campaignIds,
            'hospital_ids'=>$hospitalIds,'date_from'=>$dateFrom,'date_to'=>$dateTo,'includes'=>$includes],
        'summary'=>in_array('summary',$includes,true)?$summary:null
    ]);
}catch(Throwable $error){
    error_log('[API STUDENT STAGES] '.$error->getMessage());
    apiResponse(false,'Impossible de charger les affectations et leur planification.',[],500);
}

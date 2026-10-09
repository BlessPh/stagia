<?php
declare(strict_types=1);

function studentLogbookApiColumns(PDO $pdo,string $table):array{
    static $cache=[];
    if(isset($cache[$table]))return $cache[$table];
    $stmt=$pdo->query('SHOW COLUMNS FROM `'.$table.'`');
    return $cache[$table]=array_column($stmt->fetchAll(PDO::FETCH_ASSOC),'Field');
}

function studentLogbookApiPick(array $row,array $keys,mixed $default=null):mixed{
    foreach($keys as $key)if(array_key_exists($key,$row)&&$row[$key]!==null&&$row[$key]!=='')return $row[$key];
    return $default;
}

function studentLogbookApiStatus(string $status):string{
    return match(strtoupper(trim($status))){
        'SOUMIS','SOUMISE'=>'SOUMISE','VALIDE','VALIDEE'=>'VALIDEE','REJETE','REJETEE'=>'REJETEE',default=>'BROUILLON'
    };
}

/** Recharge une entree complete apres une mutation, avec controle de proprietaire. */
function studentLogbookApiEntry(PDO $pdo,int $studentId,string $uuid):array{
    $stmt=$pdo->prepare("SELECT le.*,
            a.uuid assignment_uuid,a.statut assignment_status,a.date_debut assignment_start,a.date_fin assignment_end,
            c.code campaign_code,c.titre campaign_title,h.code hospital_code,h.nom hospital_name,
            rot.uuid rotation_uuid,rot.sequence_no rotation_sequence,rot.statut rotation_status,
            rot.date_debut rotation_start,rot.date_fin rotation_end,
            target.id target_id,target.code target_code,target.nom target_name,target.type target_type,
            parent.id parent_id,parent.code parent_code,parent.nom parent_name,parent.type parent_type
        FROM stage_logbook_entries le
        JOIN stage_assignments a ON a.id=le.assignment_id
        JOIN stage_admissions ad ON ad.id=a.admission_id
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN stage_rotations rot ON rot.id=le.rotation_id
        LEFT JOIN host_units target ON target.id=rot.host_unit_id
        LEFT JOIN host_units parent ON parent.id=target.parent_id
        WHERE le.uuid=? AND se.student_id=? LIMIT 1");
    $stmt->execute([$uuid,$studentId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new OutOfBoundsException('Journal introuvable ou non autorise.');

    $activityColumns=studentLogbookApiColumns($pdo,'stage_logbook_activities');$foreignKey=null;
    foreach(['logbook_entry_id','entry_id','logbook_id'] as $candidate)if(in_array($candidate,$activityColumns,true)){$foreignKey=$candidate;break;}
    $activities=[];
    if($foreignKey){
        $activityStmt=$pdo->prepare('SELECT * FROM stage_logbook_activities WHERE `'.$foreignKey.'`=? ORDER BY id');
        $activityStmt->execute([(int)$row['id']]);
        foreach($activityStmt->fetchAll(PDO::FETCH_ASSOC) as $activity){
            $quantity=studentLogbookApiPick($activity,['quantite','quantity','nombre']);
            $activities[]=[
                'uuid'=>studentLogbookApiPick($activity,['uuid','public_id']),
                'activity'=>studentLogbookApiPick($activity,['intitule','activite','activity','libelle','titre','description']),
                'description'=>studentLogbookApiPick($activity,['description']),
                'category'=>studentLogbookApiPick($activity,['categorie','category','type_activite','type']),
                'involvement_level'=>studentLogbookApiPick($activity,['niveau_implication','involvement_level','niveau']),
                'quantity'=>$quantity!==null?(int)$quantity:null,
                'observation'=>studentLogbookApiPick($activity,['observation','notes','commentaire'])
            ];
        }
    }
    $status=studentLogbookApiStatus((string)studentLogbookApiPick($row,['statut','status'],'BROUILLON'));
    $targetType=strtoupper((string)($row['target_type']??''));$isUnit=in_array($targetType,['UNITE','UNIT'],true);
    return [
        'uuid'=>studentLogbookApiPick($row,['uuid','public_id']),
        'date'=>studentLogbookApiPick($row,['date_journal','date_journee','entry_date','date_activite','date_stage','date']),
        'status'=>$status,
        'summary'=>studentLogbookApiPick($row,['resume_activites','resume','summary','description','observations','observation']),
        'learning'=>studentLogbookApiPick($row,['apprentissages','lecons_apprises','learning','apprentissage','lecons']),
        'difficulties'=>studentLogbookApiPick($row,['difficultes','difficulties']),
        'observation'=>studentLogbookApiPick($row,['observation_etudiant','student_observation']),
        'submitted_at'=>studentLogbookApiPick($row,['submitted_at','date_soumission']),
        'validated_at'=>studentLogbookApiPick($row,['validated_at','date_validation']),
        'validator_comment'=>studentLogbookApiPick($row,['commentaire_encadreur','validation_comment','commentaire_validation','validator_comment','commentaire']),
        'activities'=>$activities,'activities_count'=>count($activities),
        'assignment'=>['uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],
            'period'=>['start_date'=>$row['assignment_start'],'end_date'=>$row['assignment_end']]],
        'rotation'=>$row['rotation_uuid']!==null?['uuid'=>$row['rotation_uuid'],'sequence'=>(int)$row['rotation_sequence'],
            'status'=>$row['rotation_status'],'period'=>['start_date'=>$row['rotation_start'],'end_date'=>$row['rotation_end']]]:null,
        'campaign'=>['code'=>$row['campaign_code'],'title'=>$row['campaign_title']],
        'hospital'=>['code'=>$row['hospital_code'],'name'=>$row['hospital_name']],
        'service'=>$isUnit?['id'=>$row['parent_id']!==null?(int)$row['parent_id']:null,'code'=>$row['parent_code'],
            'name'=>$row['parent_name'],'type'=>$row['parent_type']]:['id'=>$row['target_id']!==null?(int)$row['target_id']:null,
            'code'=>$row['target_code'],'name'=>$row['target_name'],'type'=>$row['target_type']],
        'unit'=>$isUnit?['id'=>(int)$row['target_id'],'code'=>$row['target_code'],'name'=>$row['target_name'],'type'=>$row['target_type']]:null,
        'actions'=>[
            'edit'=>['allowed'=>in_array($status,['BROUILLON','REJETEE'],true),'method'=>'POST','path'=>'/api/v1/student/logbook'],
            'submit'=>['allowed'=>$status==='BROUILLON','method'=>'POST','path'=>'/api/v1/student/logbook/'.$uuid.'/submit']
        ]
    ];
}

<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-execution.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];

try{
    $s=$pdo->prepare("SELECT DISTINCT a.id assignment_id,a.uuid assignment_uuid,a.statut assignment_status,a.date_debut,a.date_fin,a.observation,a.assigned_at,a.ended_at,
        ad.id admission_id,ad.statut admission_status,app.id application_id,app.campaign_id,app.host_etablissement_id,c.code campaign_code,c.titre campaign_title,st.code stage_type_code,
        h.code hospital_code,h.nom hospital_name,h.ville hospital_city,h.province hospital_province,hu.id initial_unit_id,hu.code initial_unit_code,hu.nom initial_unit_name,hu.type initial_unit_type,
        ae.id academic_enrollment_id,p.id promotion_id,p.nom promotion_name,g.id group_id,g.uuid group_uuid,g.code group_code,g.nom group_name,g.statut group_status,
        comp.statut completion_status,comp.taux_presence,comp.note_finale,comp.total_rotations,comp.total_presences,comp.total_retards,comp.total_absences,comp.total_justifiees,comp.total_journaux,comp.journaux_valides
        FROM student_enrollments se JOIN student_academic_enrollments ae ON ae.enrollment_id=se.id JOIN stage_applications app ON app.academic_enrollment_id=ae.id
        JOIN stage_reservations sr ON sr.application_id=app.id AND sr.statut='CONFIRMEE' JOIN stage_admissions ad ON ad.reservation_id=sr.id AND ad.statut IN('ADMIS','EN_COURS','TERMINE')
        JOIN stage_assignments a ON a.admission_id=ad.id AND a.host_etablissement_id=app.host_etablissement_id AND a.statut<>'ANNULEE'
        JOIN stage_campaigns c ON c.id=app.campaign_id LEFT JOIN stage_types st ON st.id=c.stage_type_id JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id LEFT JOIN promotions p ON p.id=ae.promotion_id
        LEFT JOIN stage_group_students gs ON gs.campaign_id=app.campaign_id AND gs.academic_enrollment_id=ae.id
        LEFT JOIN stage_groups g ON g.id=gs.group_id AND g.host_etablissement_id=app.host_etablissement_id AND g.statut<>'ANNULE'
        LEFT JOIN stage_completions comp ON comp.assignment_id=a.id WHERE se.student_id=? ORDER BY a.date_debut DESC,a.id DESC");
    $s->execute([$studentId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    $rotationStmt=$pdo->prepare("SELECT r.id rotation_id,r.uuid rotation_uuid,r.group_id,r.sequence_no,r.date_debut,r.date_fin,r.statut,r.objectifs,r.observation,
        hu.id unit_id,hu.code unit_code,hu.nom unit_name,hu.type unit_type,su.id supervisor_user_id,CONCAT_WS(' ',su.prenom,su.nom,su.postnom) supervisor_name,
        COALESCE(NULLIF(eu.fonction,''),rs.role_supervision,'Encadreur') supervisor_function
        FROM stage_rotations r JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN stage_rotation_supervisors rs ON rs.id=(SELECT rs2.id FROM stage_rotation_supervisors rs2 WHERE rs2.rotation_id=r.id AND rs2.actif=1 ORDER BY rs2.principal DESC,rs2.id LIMIT 1)
        LEFT JOIN users su ON su.id=rs.user_id LEFT JOIN etablissement_users eu ON eu.user_id=su.id AND eu.etablissement_id=r.host_etablissement_id
        WHERE r.assignment_id=? AND r.statut<>'ANNULEE' ORDER BY r.sequence_no,r.date_debut,r.id");

    $summary=['total'=>count($items),'planned'=>0,'active'=>0,'completed'=>0,'validated'=>0];
    foreach($items as &$item){
        foreach(['assignment_id','admission_id','application_id','campaign_id','host_etablissement_id','academic_enrollment_id'] as $key)$item[$key]=(int)$item[$key];
        foreach(['initial_unit_id','promotion_id','group_id'] as $key)$item[$key]=$item[$key]!==null?(int)$item[$key]:null;
        foreach(['total_rotations','total_presences','total_retards','total_absences','total_justifiees','total_journaux','journaux_valides'] as $key)$item[$key]=(int)($item[$key]??0);
        foreach(['taux_presence','note_finale'] as $key)$item[$key]=$item[$key]!==null?(float)$item[$key]:null;
        $rotationStmt->execute([$item['assignment_id']]);$rotations=$rotationStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rotations as &$rotation){foreach(['rotation_id','group_id','sequence_no','unit_id','supervisor_user_id'] as $key)$rotation[$key]=$rotation[$key]!==null?(int)$rotation[$key]:null;}unset($rotation);
        $item['rotations']=$rotations;$item['execution_access']=stageExecutionAccess($pdo,$item['assignment_id']);
        $item['workflow_status']=$item['completion_status']==='VALIDE'?'VALIDE':($item['assignment_status']==='ACTIVE'?'EN_COURS':($item['assignment_status']==='PLANIFIEE'?'PLANIFIE':($item['assignment_status']==='TERMINEE'?'TERMINE':$item['assignment_status'])));
        if($item['workflow_status']==='PLANIFIE')$summary['planned']++;if($item['workflow_status']==='EN_COURS')$summary['active']++;if($item['workflow_status']==='TERMINE')$summary['completed']++;if($item['workflow_status']==='VALIDE')$summary['validated']++;
    }unset($item);
    apiResponse(true,'',['student'=>['id'=>$studentId,'stagia_code'=>$student['stagia_code']],'items'=>$items,'summary'=>$summary]);
}catch(Throwable $e){
    apiResponse(false,'Erreur stages : '.$e->getMessage(),[],500);
}

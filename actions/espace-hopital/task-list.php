<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-task.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','EVALUATEUR_CLINIQUE','AUTORITE_HOSPITALIERE']);
if(function_exists('contextPermission')&&!contextPermission('supervision.hosting.view'))jsonResponse(false,'Permission insuffisante.',[],403);

try{
    stageTaskEnsureSchema($pdo);
    $hostId=(int)currentEtablissementId($pdo);$userId=(int)($_SESSION['user_id']??0);$role=$_SESSION['role_code']??'';
    if(!$hostId||!$userId)jsonResponse(false,'Aucun établissement associé.',[],403);
    $canManage=taskHostCanManage();

    $clinical=stageTaskClinicalRole($role);
    $assignWhere="a.host_etablissement_id=? AND a.statut<>'ANNULEE'";$assignParams=[$hostId];
    if($clinical){$assignWhere.=" AND EXISTS(SELECT 1 FROM stage_rotations r INNER JOIN stage_rotation_supervisors s ON s.rotation_id=r.id AND s.user_id=? AND s.actif=1 WHERE r.assignment_id=a.id)";$assignParams[]=$userId;}

    $assignmentSql="SELECT DISTINCT a.id AS assignment_id,sp.id AS student_id,sp.stagia_code,CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom) AS student_name
        FROM stage_assignments a
        INNER JOIN stage_admissions ad ON ad.id=a.admission_id
        INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
        INNER JOIN stage_applications sa ON sa.id=sr.application_id
        INNER JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        INNER JOIN student_enrollments se ON se.id=sae.enrollment_id
        INNER JOIN student_profiles sp ON sp.id=se.student_id
        WHERE $assignWhere ORDER BY student_name";
    $s=$pdo->prepare($assignmentSql);$s->execute($assignParams);$assignments=$s->fetchAll(PDO::FETCH_ASSOC);

    $rotWhere="r.host_etablissement_id=? AND r.statut<>'ANNULEE'";$rotParams=[$hostId];
    if($clinical){$rotWhere.=" AND EXISTS(SELECT 1 FROM stage_rotation_supervisors s WHERE s.rotation_id=r.id AND s.user_id=? AND s.actif=1)";$rotParams[]=$userId;}
    $s=$pdo->prepare("SELECT r.id,r.assignment_id,r.sequence_no,r.date_debut,r.date_fin,hu.nom AS unit_name
        FROM stage_rotations r INNER JOIN host_units hu ON hu.id=r.host_unit_id
        WHERE $rotWhere ORDER BY r.date_debut DESC,r.id DESC");
    $s->execute($rotParams);$rotations=$s->fetchAll(PDO::FETCH_ASSOC);

    $planItems=[];$planTitle="NULL AS plan_item_title";$planJoin="";
    $usePlan=stageTaskTableExists($pdo,'stage_training_plan_items')
        && stageTaskColumnExists($pdo,'stage_training_plan_items','assignment_id')
        && stageTaskColumnExists($pdo,'stage_training_plan_items','titre');
    if($usePlan){
        $typeCol=stageTaskColumnExists($pdo,'stage_training_plan_items','type_item')?'pi.type_item':'NULL';
        $planTitle="pi.titre AS plan_item_title";
        $planJoin=" LEFT JOIN stage_training_plan_items pi ON pi.id=t.training_plan_item_id";
        $s=$pdo->prepare("SELECT pi.id,pi.assignment_id,$typeCol AS type_item,pi.titre FROM stage_training_plan_items pi INNER JOIN stage_assignments a ON a.id=pi.assignment_id WHERE a.host_etablissement_id=? ORDER BY pi.id DESC");
        $s->execute([$hostId]);$planItems=$s->fetchAll(PDO::FETCH_ASSOC);
    }

    $where=["t.host_etablissement_id=?"];$params=[$hostId];
    if($clinical){$where[]="(t.created_by=? OR EXISTS(SELECT 1 FROM stage_rotations rx INNER JOIN stage_rotation_supervisors sx ON sx.rotation_id=rx.id AND sx.user_id=? AND sx.actif=1 WHERE rx.id=t.rotation_id))";$params[]=$userId;$params[]=$userId;}
    $status=trim($_GET['status']??'');if($status!==''){$where[]="t.statut=?";$params[]=$status;}
    $student=(int)($_GET['student_id']??0);if($student>0){$where[]="t.student_id=?";$params[]=$student;}
    $search=trim($_GET['search']??'');if($search!==''){$where[]="(t.titre LIKE ? OR t.description LIKE ? OR sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ? OR sp.stagia_code LIKE ?)";for($i=0;$i<6;$i++)$params[]='%'.$search.'%';}

    $stmt=$pdo->prepare("SELECT t.*,sp.stagia_code,CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom) AS student_name,
            COALESCE(hur.nom,hua.nom) AS unit_name,r.sequence_no,$planTitle
        FROM stage_tasks t
        INNER JOIN student_profiles sp ON sp.id=t.student_id
        INNER JOIN stage_assignments a ON a.id=t.assignment_id
        LEFT JOIN stage_rotations r ON r.id=t.rotation_id
        LEFT JOIN host_units hur ON hur.id=r.host_unit_id
        LEFT JOIN host_units hua ON hua.id=a.host_unit_id
        $planJoin
        WHERE ".implode(' AND ',$where)."
        ORDER BY FIELD(t.statut,'TERMINEE','A_REVOIR','EN_COURS','A_FAIRE','VALIDEE','ANNULEE'),t.date_echeance IS NULL,t.date_echeance,t.id DESC");
    $stmt->execute($params);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats=['total'=>0,'a_faire'=>0,'en_cours'=>0,'terminees'=>0,'a_revoir'=>0,'validees'=>0,'annulees'=>0];$byStudent=[];
    foreach($items as &$x){
        $x['id']=(int)$x['id'];$x['assignment_id']=(int)$x['assignment_id'];$x['student_id']=(int)$x['student_id'];$x['rotation_id']=$x['rotation_id']?(int)$x['rotation_id']:null;$x['training_plan_item_id']=$x['training_plan_item_id']?(int)$x['training_plan_item_id']:null;
        $stats['total']++;$map=['A_FAIRE'=>'a_faire','EN_COURS'=>'en_cours','TERMINEE'=>'terminees','A_REVOIR'=>'a_revoir','VALIDEE'=>'validees','ANNULEE'=>'annulees'];if(isset($map[$x['statut']]))$stats[$map[$x['statut']]]++;
        $sid=$x['student_id'];if(!isset($byStudent[$sid]))$byStudent[$sid]=['id'=>$sid,'name'=>$x['student_name'],'stagia_code'=>$x['stagia_code'],'total'=>0,'validated'=>0];
        if($x['statut']!=='ANNULEE'){$byStudent[$sid]['total']++;if($x['statut']==='VALIDEE')$byStudent[$sid]['validated']++;}
    }unset($x);
    foreach($assignments as $a){$sid=(int)$a['student_id'];if(!isset($byStudent[$sid]))$byStudent[$sid]=['id'=>$sid,'name'=>$a['student_name'],'stagia_code'=>$a['stagia_code'],'total'=>0,'validated'=>0];}
    $students=array_values($byStudent);foreach($students as &$s){$s['progress']=$s['total']?round($s['validated']*100/$s['total']):0;}unset($s);

    jsonResponse(true,'',['items'=>$items,'stats'=>$stats,'students'=>$students,'assignments'=>$assignments,'rotations'=>$rotations,'plan_items'=>$planItems,'can_manage'=>$canManage]);
}catch(Throwable $e){error_log('[TASK LIST] '.$e->getMessage());jsonResponse(false,'Erreur tâches : '.$e->getMessage(),[],500);}

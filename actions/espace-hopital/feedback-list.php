<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-feedback.php';

try{
    requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','EVALUATEUR_CLINIQUE','CHEF_SERVICE','AUTORITE_HOSPITALIERE']);
    if(!feedbackHostCanView())throw new RuntimeException('Accès refusé.');

    $hostId=feedbackHostId($pdo);$userId=(int)($_SESSION['user_id']??0);$role=feedbackRole();$canManage=feedbackHostCanManage();
    $search=trim((string)($_GET['search']??''));$status=strtoupper(trim((string)($_GET['status']??'')));$type=strtoupper(trim((string)($_GET['type']??'')));$studentId=(int)($_GET['student_id']??0);

    $scope=['a.host_etablissement_id=?','f.actif=1'];$scopeParams=[$hostId];
    if(in_array($role,['ENCADREUR','EVALUATEUR_CLINIQUE'],true)){
        $scope[]="EXISTS(SELECT 1 FROM stage_rotations rx JOIN stage_rotation_supervisors rs ON rs.rotation_id=rx.id AND rs.user_id=? AND rs.actif=1 WHERE rx.assignment_id=f.assignment_id AND rx.statut<>'ANNULEE')";
        $scopeParams[]=$userId;
    }

    $where=$scope;$params=$scopeParams;
    if($search!==''){$where[]='(f.titre LIKE ? OR f.commentaire LIKE ? OR sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ? OR sp.stagia_code LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like,$like,$like,$like);}
    if(in_array($status,['BROUILLON','PUBLIE','ARCHIVE'],true)){$where[]='f.statut=?';$params[]=$status;}
    if(in_array($type,['OBSERVATION','ENCOURAGEMENT','A_AMELIORER','AVERTISSEMENT'],true)){$where[]='f.type_feedback=?';$params[]=$type;}
    if($studentId){$where[]='sp.id=?';$params[]=$studentId;}

    $studentJoin="
        LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
        LEFT JOIN stage_applications app ON app.id=sr.application_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id";

    $s=$pdo->prepare("SELECT f.id,f.uuid,f.assignment_id,f.rotation_id,f.date_feedback,f.type_feedback,f.titre,f.commentaire,f.visible_stagiaire,f.statut,f.created_by_user_id,f.published_at,f.created_at,f.updated_at,
             sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,r.sequence_no,r.date_debut rotation_start,r.date_fin rotation_end,hu.nom unit_name,TRIM(CONCAT_WS(' ',u.prenom,u.nom)) author_name
        FROM stage_supervision_feedbacks f
        JOIN stage_assignments a ON a.id=f.assignment_id $studentJoin
        LEFT JOIN stage_rotations r ON r.id=f.rotation_id
        LEFT JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN users u ON u.id=f.created_by_user_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY f.date_feedback DESC,f.created_at DESC,f.id DESC");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$x){$x['id']=(int)$x['id'];$x['assignment_id']=(int)$x['assignment_id'];$x['rotation_id']=$x['rotation_id']!==null?(int)$x['rotation_id']:null;$x['student_id']=(int)($x['student_id']??0);$x['visible_stagiaire']=(int)$x['visible_stagiaire'];$x['student_name']=feedbackStudentName($x);$x['mine']=(int)$x['created_by_user_id']===$userId;}unset($x);

    $s=$pdo->prepare("SELECT COUNT(*) total,SUM(f.statut='BROUILLON') brouillons,SUM(f.statut='PUBLIE') publies,SUM(f.type_feedback='A_AMELIORER' AND f.statut<>'ARCHIVE') a_ameliorer,SUM(f.type_feedback='AVERTISSEMENT' AND f.statut<>'ARCHIVE') avertissements
        FROM stage_supervision_feedbacks f JOIN stage_assignments a ON a.id=f.assignment_id WHERE ".implode(' AND ',$scope));
    $s->execute($scopeParams);$stats=$s->fetch(PDO::FETCH_ASSOC)?:[];foreach(['total','brouillons','publies','a_ameliorer','avertissements'] as $k)$stats[$k]=(int)($stats[$k]??0);

    $scope2=['a.host_etablissement_id=?'];$p2=[$hostId];
    if(in_array($role,['ENCADREUR','EVALUATEUR_CLINIQUE'],true)){$scope2[]="EXISTS(SELECT 1 FROM stage_rotations rx JOIN stage_rotation_supervisors rs ON rs.rotation_id=rx.id AND rs.user_id=? AND rs.actif=1 WHERE rx.assignment_id=a.id AND rx.statut<>'ANNULEE')";$p2[]=$userId;}
    $s=$pdo->prepare("SELECT DISTINCT sp.id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom FROM stage_assignments a $studentJoin WHERE ".implode(' AND ',$scope2)." AND sp.id IS NOT NULL ORDER BY sp.nom,sp.postnom,sp.prenom");
    $s->execute($p2);$students=$s->fetchAll(PDO::FETCH_ASSOC);foreach($students as &$st){$st['id']=(int)$st['id'];$st['name']=feedbackStudentName($st);}unset($st);

    $assignments=[];$rotations=[];
    if($canManage){
        $s=$pdo->prepare("SELECT DISTINCT a.id assignment_id,a.date_debut,a.date_fin,a.statut,sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom
            FROM stage_assignments a $studentJoin
            JOIN stage_rotations r ON r.assignment_id=a.id AND r.statut<>'ANNULEE'
            JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1
            WHERE a.host_etablissement_id=? AND a.statut IN('PLANIFIEE','ACTIVE','TERMINEE') AND sp.id IS NOT NULL
            ORDER BY sp.nom,sp.postnom,sp.prenom,a.id");
        $s->execute([$userId,$hostId]);$assignments=$s->fetchAll(PDO::FETCH_ASSOC);foreach($assignments as &$a){$a['assignment_id']=(int)$a['assignment_id'];$a['student_id']=(int)$a['student_id'];$a['student_name']=feedbackStudentName($a);}unset($a);

        $s=$pdo->prepare("SELECT r.id,r.assignment_id,r.sequence_no,r.date_debut,r.date_fin,r.statut,hu.nom unit_name FROM stage_rotations r JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 JOIN host_units hu ON hu.id=r.host_unit_id WHERE r.host_etablissement_id=? AND r.statut<>'ANNULEE' ORDER BY r.assignment_id,r.sequence_no");
        $s->execute([$userId,$hostId]);$rotations=$s->fetchAll(PDO::FETCH_ASSOC);foreach($rotations as &$r){$r['id']=(int)$r['id'];$r['assignment_id']=(int)$r['assignment_id'];}unset($r);
    }

    jsonResponse(true,'',['items'=>$items,'stats'=>$stats,'students'=>$students,'assignments'=>$assignments,'rotations'=>$rotations,'can_manage'=>$canManage]);
}catch(Throwable $e){jsonResponse(false,'Erreur feedback : '.$e->getMessage(),[],403);}

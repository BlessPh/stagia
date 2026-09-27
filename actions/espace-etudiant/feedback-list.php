<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-feedback.php';

try{
    requireAjaxRole(['STAGIAIRE']);
    $studentId=feedbackStudentId($pdo);if(!$studentId)throw new RuntimeException('Profil étudiant introuvable.');
    $type=strtoupper(trim((string)($_GET['type']??'')));
    $where=["se.student_id=?","f.actif=1","f.statut='PUBLIE'","f.visible_stagiaire=1"];$params=[$studentId];
    if(in_array($type,['OBSERVATION','ENCOURAGEMENT','A_AMELIORER','AVERTISSEMENT'],true)){$where[]='f.type_feedback=?';$params[]=$type;}

    $s=$pdo->prepare("SELECT f.id,f.date_feedback,f.type_feedback,f.titre,f.commentaire,f.published_at,r.sequence_no,hu.nom unit_name,TRIM(CONCAT_WS(' ',u.prenom,u.nom)) author_name,et.nom host_name
        FROM stage_supervision_feedbacks f
        JOIN stage_assignments a ON a.id=f.assignment_id
        LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
        LEFT JOIN stage_applications app ON app.id=sr.application_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN stage_rotations r ON r.id=f.rotation_id
        LEFT JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN users u ON u.id=f.created_by_user_id
        LEFT JOIN etablissements et ON et.id=a.host_etablissement_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY f.date_feedback DESC,f.published_at DESC,f.id DESC");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);foreach($items as &$x)$x['id']=(int)$x['id'];unset($x);

    $s=$pdo->prepare("SELECT COUNT(*) total,SUM(f.type_feedback='ENCOURAGEMENT') encouragements,SUM(f.type_feedback='A_AMELIORER') a_ameliorer,SUM(f.type_feedback='AVERTISSEMENT') avertissements
        FROM stage_supervision_feedbacks f
        JOIN stage_assignments a ON a.id=f.assignment_id
        LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
        LEFT JOIN stage_applications app ON app.id=sr.application_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        WHERE se.student_id=? AND f.actif=1 AND f.statut='PUBLIE' AND f.visible_stagiaire=1");
    $s->execute([$studentId]);$stats=$s->fetch(PDO::FETCH_ASSOC)?:[];foreach(['total','encouragements','a_ameliorer','avertissements'] as $k)$stats[$k]=(int)($stats[$k]??0);

    $u=$pdo->prepare("UPDATE stage_supervision_feedbacks f
        JOIN stage_assignments a ON a.id=f.assignment_id
        LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
        LEFT JOIN stage_applications app ON app.id=sr.application_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        SET f.student_seen_at=COALESCE(f.student_seen_at,NOW())
        WHERE se.student_id=? AND f.actif=1 AND f.statut='PUBLIE' AND f.visible_stagiaire=1 AND f.student_seen_at IS NULL");
    $u->execute([$studentId]);

    jsonResponse(true,'',['items'=>$items,'stats'=>$stats]);
}catch(Throwable $e){jsonResponse(false,'Erreur retours : '.$e->getMessage(),[],403);}

<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/permissions.php';

if(!function_exists('feedbackUuidV4')){
function feedbackUuidV4():string{$d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));}
function feedbackRole():string{return strtoupper((string)($_SESSION['role_code']??''));}
function feedbackHostId(PDO $pdo):int{return function_exists('currentEtablissementId')?(int)currentEtablissementId($pdo):(int)($_SESSION['etablissement_id']??0);}
function feedbackHostCanView():bool{return in_array(feedbackRole(),['ADMIN_ACCUEIL','COORDINATEUR_STAGES','ENCADREUR','EVALUATEUR_CLINIQUE','CHEF_SERVICE','AUTORITE_HOSPITALIERE'],true);}
function feedbackHostCanManage():bool{return in_array(feedbackRole(),['ENCADREUR','EVALUATEUR_CLINIQUE'],true);}
function feedbackStudentName(array $x):string{return trim(implode(' ',array_filter([$x['nom']??'',$x['postnom']??'',$x['prenom']??''])))?:('Stagiaire #'.($x['student_id']??$x['id']??''));}
function feedbackStudentId(PDO $pdo):int{$s=$pdo->prepare("SELECT id FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");$s->execute([(int)($_SESSION['user_id']??0)]);return(int)$s->fetchColumn();}
function feedbackEnsureSchema(PDO $pdo):void{
    $pdo->exec("CREATE TABLE IF NOT EXISTS stage_supervision_feedbacks(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL UNIQUE,
        assignment_id BIGINT UNSIGNED NOT NULL,
        rotation_id BIGINT UNSIGNED NULL,
        date_feedback DATE NOT NULL,
        type_feedback ENUM('OBSERVATION','ENCOURAGEMENT','A_AMELIORER','AVERTISSEMENT') NOT NULL DEFAULT 'OBSERVATION',
        titre VARCHAR(180) NULL,
        commentaire TEXT NOT NULL,
        visible_stagiaire TINYINT(1) NOT NULL DEFAULT 1,
        statut ENUM('BROUILLON','PUBLIE','ARCHIVE') NOT NULL DEFAULT 'BROUILLON',
        created_by_user_id INT NOT NULL,
        published_at DATETIME NULL,
        archived_at DATETIME NULL,
        student_seen_at DATETIME NULL,
        actif TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_fb_assignment(assignment_id),INDEX idx_fb_rotation(rotation_id),INDEX idx_fb_student_seen(student_seen_at),
        INDEX idx_fb_status(statut,visible_stagiaire,actif)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $cols=[];$s=$pdo->query("SHOW COLUMNS FROM stage_supervision_feedbacks");foreach($s->fetchAll(PDO::FETCH_ASSOC) as $c)$cols[$c['Field']]=1;
    /* Ordre important : archived_at doit exister avant student_seen_at. */
    if(empty($cols['archived_at'])){ $pdo->exec("ALTER TABLE stage_supervision_feedbacks ADD archived_at DATETIME NULL AFTER published_at"); $cols['archived_at']=1; }
    if(empty($cols['student_seen_at'])){ $pdo->exec("ALTER TABLE stage_supervision_feedbacks ADD student_seen_at DATETIME NULL AFTER archived_at"); $cols['student_seen_at']=1; }
    try{$pdo->exec("CREATE INDEX idx_fb_student_seen ON stage_supervision_feedbacks(student_seen_at)");}catch(Throwable $e){}
}
function feedbackAssignment(PDO $pdo,int $assignmentId,int $hostId,bool $active=false):array{
    $sql="SELECT a.id assignment_id,a.host_etablissement_id,a.host_unit_id,a.statut assignment_status,a.date_debut,a.date_fin,
                 sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom
          FROM stage_assignments a
          LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
          LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
          LEFT JOIN stage_applications app ON app.id=sr.application_id
          LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
          LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
          LEFT JOIN student_profiles sp ON sp.id=se.student_id
          WHERE a.id=? AND a.host_etablissement_id=?".($active?" AND a.statut IN('PLANIFIEE','ACTIVE','TERMINEE')":"")." LIMIT 1";
    $s=$pdo->prepare($sql);$s->execute([$assignmentId,$hostId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Affectation introuvable.');return$r;
}
function feedbackRotation(PDO $pdo,int $rotationId,int $assignmentId,int $hostId,?int $supervisorUserId=null):?array{
    if(!$rotationId)return null;$s=$pdo->prepare("SELECT r.*,hu.nom unit_name FROM stage_rotations r LEFT JOIN host_units hu ON hu.id=r.host_unit_id WHERE r.id=? AND r.assignment_id=? AND r.host_etablissement_id=? AND r.statut<>'ANNULEE' LIMIT 1");
    $s->execute([$rotationId,$assignmentId,$hostId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Rotation introuvable.');
    if($supervisorUserId){$x=$pdo->prepare("SELECT 1 FROM stage_rotation_supervisors WHERE rotation_id=? AND user_id=? AND actif=1 LIMIT 1");$x->execute([$rotationId,$supervisorUserId]);if(!$x->fetchColumn())throw new RuntimeException("Vous n'encadrez pas cette rotation.");}
    return$r;
}
function feedbackSupervisorCanAccessAssignment(PDO $pdo,int $assignmentId,int $userId):bool{
    $s=$pdo->prepare("SELECT 1 FROM stage_rotations r JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE r.assignment_id=? AND r.statut<>'ANNULEE' LIMIT 1");
    $s->execute([$userId,$assignmentId]);return(bool)$s->fetchColumn();
}
function feedbackValidateDate(string $date,array $assignment,?array $rotation=null):string{
    $date=trim($date)?:date('Y-m-d');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Date invalide.');return$date;
}
function feedbackVerifyCsrf():void{$csrf=$_POST['csrf']??'';if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf)){if(function_exists('jsonResponse'))jsonResponse(false,'Jeton de sécurité invalide.',[],419);throw new RuntimeException('Jeton de sécurité invalide.');}}
function feedbackStudentVisibleList(PDO $pdo,int $studentId,string $type=''):array{
    $type=strtoupper(trim($type));$where=["se.student_id=?","f.actif=1","f.statut='PUBLIE'","f.visible_stagiaire=1"];$params=[$studentId];
    if(in_array($type,['OBSERVATION','ENCOURAGEMENT','A_AMELIORER','AVERTISSEMENT'],true)){$where[]='f.type_feedback=?';$params[]=$type;}
    $s=$pdo->prepare("SELECT f.id,f.uuid,f.assignment_id,f.rotation_id,f.date_feedback,f.type_feedback,f.titre,f.commentaire,f.published_at,f.student_seen_at,a.uuid assignment_uuid,r.uuid rotation_uuid,r.sequence_no,hu.nom unit_name,TRIM(CONCAT_WS(' ',u.prenom,u.nom,u.postnom)) author_name,et.nom host_name
        FROM stage_supervision_feedbacks f JOIN stage_assignments a ON a.id=f.assignment_id JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN stage_rotations r ON r.id=f.rotation_id LEFT JOIN host_units hu ON hu.id=r.host_unit_id LEFT JOIN users u ON u.id=f.created_by_user_id LEFT JOIN etablissements et ON et.id=a.host_etablissement_id
        WHERE ".implode(' AND ',$where)." ORDER BY f.date_feedback DESC,f.published_at DESC,f.id DESC");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$x){$x['id']=(int)$x['id'];$x['assignment_id']=(int)$x['assignment_id'];$x['rotation_id']=$x['rotation_id']!==null?(int)$x['rotation_id']:null;$x['seen']=$x['student_seen_at']!==null;}unset($x);
    $s=$pdo->prepare("SELECT COUNT(*) total,SUM(f.type_feedback='ENCOURAGEMENT') encouragements,SUM(f.type_feedback='A_AMELIORER') a_ameliorer,SUM(f.type_feedback='AVERTISSEMENT') avertissements FROM stage_supervision_feedbacks f JOIN stage_assignments a ON a.id=f.assignment_id JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id JOIN student_enrollments se ON se.id=ae.enrollment_id WHERE se.student_id=? AND f.actif=1 AND f.statut='PUBLIE' AND f.visible_stagiaire=1");
    $s->execute([$studentId]);$stats=$s->fetch(PDO::FETCH_ASSOC)?:[];foreach(['total','encouragements','a_ameliorer','avertissements'] as $k)$stats[$k]=(int)($stats[$k]??0);
    $u=$pdo->prepare("UPDATE stage_supervision_feedbacks f JOIN stage_assignments a ON a.id=f.assignment_id JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id JOIN student_enrollments se ON se.id=ae.enrollment_id SET f.student_seen_at=COALESCE(f.student_seen_at,NOW()) WHERE se.student_id=? AND f.actif=1 AND f.statut='PUBLIE' AND f.visible_stagiaire=1 AND f.student_seen_at IS NULL");
    $u->execute([$studentId]);return ['items'=>$items,'stats'=>$stats];
}
}
try{feedbackEnsureSchema($pdo);}catch(Throwable $e){error_log('[STAGE FEEDBACK SCHEMA] '.$e->getMessage());}

<?php
declare(strict_types=1);

const STAGE_JUSTIFICATION_TYPES=['NON_DEMARRAGE','INTERRUPTION'];
const STAGE_JUSTIFICATION_REASONS=[
    'MALADIE_INDISPONIBILITE','MOTIF_FAMILIAL','MOTIF_ACADEMIQUE','PROBLEME_ADMINISTRATIF',
    'PROBLEME_FINANCIER','CHANGEMENT_AFFECTATION','FORCE_MAJEURE','ABANDON_VOLONTAIRE','AUTRE'
];
const STAGE_JUSTIFICATION_STATUSES=['EN_ATTENTE','ACCEPTEE','REJETEE'];

function stageJustificationTableExists(PDO $pdo):bool{
    static $cache=null;if($cache!==null)return $cache;
    $s=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stage_followup_justifications'");
    return $cache=(bool)$s->fetchColumn();
}
function stageJustificationRequireSchema(PDO $pdo):void{
    if(!stageJustificationTableExists($pdo))throw new RuntimeException('Migration des justifications de stage requise.');
}
function stageJustificationUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}
function stageJustificationStudentId(PDO $pdo,int $userId):int{
    $s=$pdo->prepare("SELECT id FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");$s->execute([$userId]);return (int)$s->fetchColumn();
}
function stageJustificationReasonLabels():array{
    return ['MALADIE_INDISPONIBILITE'=>'Maladie / indisponibilité','MOTIF_FAMILIAL'=>'Motif familial',
        'MOTIF_ACADEMIQUE'=>'Motif académique','PROBLEME_ADMINISTRATIF'=>'Problème administratif',
        'PROBLEME_FINANCIER'=>'Problème financier','CHANGEMENT_AFFECTATION'=>'Changement d’affectation',
        'FORCE_MAJEURE'=>'Force majeure','ABANDON_VOLONTAIRE'=>'Abandon volontaire','AUTRE'=>'Autre'];
}
function stageJustificationBaseSql():string{
    return "SELECT a.id assignment_id,a.uuid assignment_uuid,a.statut assignment_status,
      COALESCE(a.date_debut,c.date_debut) date_debut,COALESCE(a.date_fin,c.date_fin) date_fin,
      c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
      h.id host_id,h.nom host_name,hu.nom unit_name,uni.nom university_name,
      COALESCE(att.total,0) attendance_total,COALESCE(logb.total,0) logbook_total,COALESCE(ev.total,0) evaluation_total,
      comp.statut completion_status,comp.note_finale,j.id justification_id,j.uuid justification_uuid,
      j.type_situation,j.motif justification_reason,j.explication justification_explanation,
      j.statut justification_status,j.document_nom_original,j.document_mime_type,j.document_taille,
      j.decision_commentaire,j.submitted_at,j.reviewed_at,j.student_seen_at
    FROM stage_assignments a
    JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id
    JOIN stage_applications app ON app.id=sr.application_id JOIN stage_campaigns c ON c.id=app.campaign_id
    JOIN etablissements uni ON uni.id=c.owner_etablissement_id
    JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
    JOIN student_enrollments se ON se.id=ae.enrollment_id
    LEFT JOIN etablissements h ON h.id=a.host_etablissement_id LEFT JOIN host_units hu ON hu.id=a.host_unit_id
    LEFT JOIN (SELECT assignment_id,COUNT(*) total FROM stage_attendances GROUP BY assignment_id) att ON att.assignment_id=a.id
    LEFT JOIN (SELECT assignment_id,COUNT(*) total FROM stage_logbook_entries GROUP BY assignment_id) logb ON logb.assignment_id=a.id
    LEFT JOIN (SELECT assignment_id,COUNT(*) total FROM stage_evaluations GROUP BY assignment_id) ev ON ev.assignment_id=a.id
    LEFT JOIN stage_completions comp ON comp.assignment_id=a.id
    LEFT JOIN stage_followup_justifications j ON j.assignment_id=a.id AND j.student_id=se.student_id";
}
function stageJustificationNormalize(array $x,string $today):array{
    foreach(['assignment_id','campaign_id','host_id','attendance_total','logbook_total','evaluation_total','justification_id','document_taille'] as $f)$x[$f]=(int)($x[$f]??0);
    $traces=$x['attendance_total']+$x['logbook_total']+$x['evaluation_total'];$validated=($x['completion_status']??'')==='VALIDE';
    $type=(string)($x['type_situation']??'');
    if($validated)$situation='VALIDE';elseif($type==='INTERRUPTION'&&$traces>0)$situation='INTERROMPU';
    elseif($type==='NON_DEMARRAGE'&&$traces===0)$situation='NON_SUIVI';elseif($traces>0)$situation='SUIVI';
    elseif(!empty($x['date_debut'])&&$x['date_debut']>$today)$situation='PLANIFIE';else $situation='AUCUNE_TRACE';
    $can=!$validated&&!empty($x['date_debut'])&&$x['date_debut']<=$today&&($x['justification_status']??'')!=='ACCEPTEE';
    $labels=stageJustificationReasonLabels();
    return array_merge($x,['trace_count'=>$traces,'situation'=>$situation,
        'allowed_type'=>$can?($traces>0?'INTERRUPTION':'NON_DEMARRAGE'):null,'can_justify'=>$can,
        'reason_label'=>$labels[$x['justification_reason']??'']??($x['justification_reason']??null),
        'has_document'=>!empty($x['justification_uuid'])&&!empty($x['document_nom_original']),
        'decision_unread'=>in_array(($x['justification_status']??''),['ACCEPTEE','REJETEE'],true)&&empty($x['student_seen_at'])]);
}
function stageJustificationListForStudent(PDO $pdo,int $studentId,bool $markSeen=false):array{
    stageJustificationRequireSchema($pdo);$s=$pdo->prepare(stageJustificationBaseSql()." WHERE se.student_id=? AND a.statut<>'ANNULEE' ORDER BY COALESCE(a.date_debut,c.date_debut) DESC,a.id DESC");$s->execute([$studentId]);
    $items=[];$stats=['total'=>0,'EN_ATTENTE'=>0,'ACCEPTEE'=>0,'REJETEE'=>0,'unread'=>0];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$item=stageJustificationNormalize($row,date('Y-m-d'));$items[]=$item;$stats['total']++;$status=(string)($item['justification_status']??'');if(isset($stats[$status]))$stats[$status]++;if($item['decision_unread'])$stats['unread']++;}
    if($markSeen&&$stats['unread']){$u=$pdo->prepare("UPDATE stage_followup_justifications SET student_seen_at=NOW() WHERE student_id=? AND statut IN('ACCEPTEE','REJETEE') AND student_seen_at IS NULL");$u->execute([$studentId]);}
    if($markSeen&&$stats['unread']){
        try{
            $u=$pdo->prepare("UPDATE notifications n JOIN student_profiles sp ON sp.user_id=n.utilisateur_id SET n.lue_le=COALESCE(n.lue_le,NOW()) WHERE sp.id=? AND n.type_evenement='stage.justification.reviewed' AND n.lue_le IS NULL");
            $u->execute([$studentId]);
        }catch(Throwable $e){error_log('[JUSTIFICATION MARK NOTIFICATIONS READ] '.$e->getMessage());}
    }
    return ['items'=>$items,'stats'=>$stats,'reason_labels'=>stageJustificationReasonLabels()];
}
function stageJustificationResolveAssignment(PDO $pdo,int $studentId,array $input,bool $lock=false):array{
    $id=(int)($input['assignment_id']??0);$uuid=trim((string)($input['assignment_uuid']??''));$where=$id?'a.id=?':'a.uuid=?';$value=$id?:$uuid;
    if($value==='')throw new InvalidArgumentException('Affectation de stage invalide.');
    $sql="SELECT a.id,a.uuid,a.statut,COALESCE(a.date_debut,c.date_debut) date_debut,COALESCE(a.date_fin,c.date_fin) date_fin,
      a.host_etablissement_id,c.id campaign_id,c.owner_etablissement_id university_etablissement_id,
      COALESCE(att.total,0)+COALESCE(logb.total,0)+COALESCE(ev.total,0) trace_count,comp.statut completion_status
    FROM stage_assignments a JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id
    JOIN stage_applications app ON app.id=sr.application_id JOIN stage_campaigns c ON c.id=app.campaign_id
    JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
    JOIN student_enrollments se ON se.id=ae.enrollment_id AND se.student_id=?
    LEFT JOIN (SELECT assignment_id,COUNT(*) total FROM stage_attendances GROUP BY assignment_id) att ON att.assignment_id=a.id
    LEFT JOIN (SELECT assignment_id,COUNT(*) total FROM stage_logbook_entries GROUP BY assignment_id) logb ON logb.assignment_id=a.id
    LEFT JOIN (SELECT assignment_id,COUNT(*) total FROM stage_evaluations GROUP BY assignment_id) ev ON ev.assignment_id=a.id
    LEFT JOIN stage_completions comp ON comp.assignment_id=a.id WHERE $where AND a.statut<>'ANNULEE' LIMIT 1".($lock?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);$s->execute([$studentId,$value]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new InvalidArgumentException('Stage introuvable.');return $row;
}
function stageJustificationStoreUpload(array $file,int $universityId,int $assignmentId):array{
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return [];
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Le document n’a pas pu être reçu.');
    $size=(int)($file['size']??0);if($size<1||$size>8*1024*1024)throw new InvalidArgumentException('Le document doit avoir une taille maximale de 8 Mo.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name'])?:'';$ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];
    if(!isset($ext[$mime]))throw new InvalidArgumentException('Formats autorisés : PDF, JPG et PNG.');
    $dir=dirname(__DIR__).'/storage/stage-justifications/'.$universityId.'/'.$assignmentId;
    if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Le stockage des justifications est indisponible.');
    $stored=stageJustificationUuid().'.'.$ext[$mime];$absolute=$dir.'/'.$stored;if(!move_uploaded_file((string)$file['tmp_name'],$absolute))throw new RuntimeException('Impossible d’enregistrer le document.');
    return ['document_nom_original'=>mb_substr(basename((string)$file['name']),0,255),'document_nom_stockage'=>$stored,'document_mime_type'=>$mime,'document_taille'=>$size,
        'document_path'=>'storage/stage-justifications/'.$universityId.'/'.$assignmentId.'/'.$stored,'absolute_path'=>$absolute];
}
function stageJustificationDeleteStoredFile(string $relative):void{
    $root=realpath(dirname(__DIR__).'/storage/stage-justifications');$path=realpath(dirname(__DIR__).'/'.ltrim($relative,'/\\'));
    if($root&&$path&&str_starts_with($path,$root.DIRECTORY_SEPARATOR)&&is_file($path))@unlink($path);
}
function stageJustificationSubmit(PDO $pdo,int $studentId,int $userId,array $input,array $file=[]):array{
    stageJustificationRequireSchema($pdo);$type=strtoupper(trim((string)($input['type_situation']??'')));$reason=strtoupper(trim((string)($input['motif']??$input['reason']??'')));$explanation=trim((string)($input['explication']??$input['explanation']??''));
    if(!in_array($type,STAGE_JUSTIFICATION_TYPES,true)||!in_array($reason,STAGE_JUSTIFICATION_REASONS,true))throw new InvalidArgumentException('Informations de justification invalides.');
    if($reason==='AUTRE'&&$explanation==='')throw new InvalidArgumentException('Précisez le motif de la justification.');
    $started=false;$upload=[];$oldPath='';
    try{if(!$pdo->inTransaction()){$pdo->beginTransaction();$started=true;}$assignment=stageJustificationResolveAssignment($pdo,$studentId,$input,true);
        if(($assignment['completion_status']??'')==='VALIDE')throw new InvalidArgumentException('Un stage déjà validé ne peut plus être justifié.');
        if(empty($assignment['date_debut'])||$assignment['date_debut']>date('Y-m-d'))throw new InvalidArgumentException('Ce stage n’a pas encore commencé.');
        $expected=(int)$assignment['trace_count']>0?'INTERRUPTION':'NON_DEMARRAGE';if($type!==$expected)throw new InvalidArgumentException('La situation du stage a changé. Actualisez puis réessayez.');
        $s=$pdo->prepare('SELECT * FROM stage_followup_justifications WHERE assignment_id=? AND student_id=? LIMIT 1 FOR UPDATE');$s->execute([(int)$assignment['id'],$studentId]);$current=$s->fetch(PDO::FETCH_ASSOC)?:null;
        if($current&&$current['statut']==='ACCEPTEE')throw new InvalidArgumentException('Cette justification a déjà été acceptée et ne peut plus être modifiée.');
        $upload=stageJustificationStoreUpload($file,(int)$assignment['university_etablissement_id'],(int)$assignment['id']);
        if($current){$oldPath=(string)($current['document_path']??'');$sets="type_situation=?,motif=?,explication=?,statut='EN_ATTENTE',submitted_by=?,submitted_at=NOW(),reviewed_by=NULL,reviewed_at=NULL,decision_commentaire=NULL,student_seen_at=NULL";$params=[$type,$reason,$explanation?:null,$userId];
            if($upload){$sets.=',document_nom_original=?,document_nom_stockage=?,document_mime_type=?,document_taille=?,document_path=?';array_push($params,$upload['document_nom_original'],$upload['document_nom_stockage'],$upload['document_mime_type'],$upload['document_taille'],$upload['document_path']);}$params[]=(int)$current['id'];$pdo->prepare("UPDATE stage_followup_justifications SET $sets WHERE id=?")->execute($params);$justificationId=(int)$current['id'];$uuid=(string)$current['uuid'];
        }else{$uuid=stageJustificationUuid();$pdo->prepare("INSERT INTO stage_followup_justifications(uuid,assignment_id,student_id,campaign_id,university_etablissement_id,host_etablissement_id,type_situation,motif,explication,document_nom_original,document_nom_stockage,document_mime_type,document_taille,document_path,statut,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,'EN_ATTENTE',?,NOW())")
            ->execute([$uuid,(int)$assignment['id'],$studentId,(int)$assignment['campaign_id'],(int)$assignment['university_etablissement_id'],(int)$assignment['host_etablissement_id'],$type,$reason,$explanation?:null,$upload['document_nom_original']??null,$upload['document_nom_stockage']??null,$upload['document_mime_type']??null,$upload['document_taille']??null,$upload['document_path']??null,$userId]);$justificationId=(int)$pdo->lastInsertId();}
        if($started)$pdo->commit();if($upload&&$oldPath!==''&&$oldPath!==$upload['document_path'])stageJustificationDeleteStoredFile($oldPath);stageJustificationNotifyUniversity($pdo,$justificationId);
        return ['id'=>$justificationId,'uuid'=>$uuid,'status'=>'EN_ATTENTE','message'=>$current?'Justification mise à jour et renvoyée à votre établissement.':'Justification envoyée à votre établissement.'];
    }catch(Throwable $e){if($started&&$pdo->inTransaction())$pdo->rollBack();if(!empty($upload['absolute_path'])&&is_file($upload['absolute_path']))@unlink($upload['absolute_path']);throw $e;}
}
function stageJustificationFile(PDO $pdo,string $uuid,int $studentId=0,int $universityId=0):?array{
    stageJustificationRequireSchema($pdo);$where=['j.uuid=?'];$params=[$uuid];if($studentId){$where[]='j.student_id=?';$params[]=$studentId;}if($universityId){$where[]='j.university_etablissement_id=?';$params[]=$universityId;}
    $s=$pdo->prepare('SELECT j.* FROM stage_followup_justifications j WHERE '.implode(' AND ',$where).' LIMIT 1');$s->execute($params);$x=$s->fetch(PDO::FETCH_ASSOC);if(!$x||empty($x['document_path']))return null;
    $root=realpath(dirname(__DIR__).'/storage/stage-justifications');$path=realpath(dirname(__DIR__).'/'.ltrim((string)$x['document_path'],'/\\'));if(!$root||!$path||!str_starts_with($path,$root.DIRECTORY_SEPARATOR)||!is_file($path))return null;$x['absolute_path']=$path;return $x;
}
function stageJustificationUniversityList(PDO $pdo,int $universityId,string $status=''):array{
    stageJustificationRequireSchema($pdo);$where=['j.university_etablissement_id=?'];$params=[$universityId];if($status&&in_array($status,STAGE_JUSTIFICATION_STATUSES,true)){$where[]='j.statut=?';$params[]=$status;}
    $s=$pdo->prepare("SELECT j.*,a.uuid assignment_uuid,c.code campaign_code,c.titre campaign_title,h.nom host_name,hu.nom unit_name,sp.stagia_code,TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)) student_name
      FROM stage_followup_justifications j JOIN stage_assignments a ON a.id=j.assignment_id JOIN stage_campaigns c ON c.id=j.campaign_id
      LEFT JOIN etablissements h ON h.id=j.host_etablissement_id LEFT JOIN host_units hu ON hu.id=a.host_unit_id JOIN student_profiles sp ON sp.id=j.student_id
      WHERE ".implode(' AND ',$where)." ORDER BY FIELD(j.statut,'EN_ATTENTE','REJETEE','ACCEPTEE'),j.submitted_at DESC,j.id DESC");$s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);$stats=['total'=>count($items),'EN_ATTENTE'=>0,'ACCEPTEE'=>0,'REJETEE'=>0];foreach($items as $x)$stats[$x['statut']]++;
    return ['items'=>$items,'stats'=>$stats,'reason_labels'=>stageJustificationReasonLabels()];
}
function stageJustificationReview(PDO $pdo,int $universityId,int $userId,string $uuid,string $decision,string $comment=''):array{
    stageJustificationRequireSchema($pdo);$decision=strtoupper(trim($decision));if(!in_array($decision,['ACCEPTEE','REJETEE'],true))throw new InvalidArgumentException('Décision invalide.');if($decision==='REJETEE'&&trim($comment)==='')throw new InvalidArgumentException('Un commentaire est requis pour un rejet.');
    $pdo->beginTransaction();try{$s=$pdo->prepare('SELECT * FROM stage_followup_justifications WHERE uuid=? AND university_etablissement_id=? LIMIT 1 FOR UPDATE');$s->execute([$uuid,$universityId]);$x=$s->fetch(PDO::FETCH_ASSOC);if(!$x)throw new InvalidArgumentException('Justification introuvable.');if($x['statut']!=='EN_ATTENTE')throw new InvalidArgumentException('Cette justification a déjà été traitée.');
        $pdo->prepare('UPDATE stage_followup_justifications SET statut=?,decision_commentaire=?,reviewed_by=?,reviewed_at=NOW(),student_seen_at=NULL WHERE id=?')->execute([$decision,trim($comment)?:null,$userId,(int)$x['id']]);
        try{$n=$pdo->prepare("UPDATE notifications SET lue_le=COALESCE(lue_le,NOW()) WHERE utilisateur_id=? AND type_evenement='stage.justification.submitted' AND JSON_UNQUOTE(JSON_EXTRACT(donnees,'$.justification_uuid'))=?");$n->execute([$userId,$uuid]);}catch(Throwable $e){error_log('[JUSTIFICATION REVIEW NOTIFICATION READ] '.$e->getMessage());}
        $pdo->commit();stageJustificationNotifyStudent($pdo,(int)$x['id']);return ['uuid'=>$uuid,'status'=>$decision];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function stageJustificationMarkSeen(PDO $pdo,int $studentId,string $uuid):bool{
    stageJustificationRequireSchema($pdo);$s=$pdo->prepare("UPDATE stage_followup_justifications SET student_seen_at=COALESCE(student_seen_at,NOW()) WHERE uuid=? AND student_id=? AND statut IN('ACCEPTEE','REJETEE')");$s->execute([$uuid,$studentId]);
    $exists=$pdo->prepare("SELECT sp.user_id FROM stage_followup_justifications j JOIN student_profiles sp ON sp.id=j.student_id WHERE j.uuid=? AND j.student_id=? AND j.statut IN('ACCEPTEE','REJETEE') LIMIT 1");$exists->execute([$uuid,$studentId]);$userId=(int)$exists->fetchColumn();
    if($userId){try{$n=$pdo->prepare("UPDATE notifications SET lue_le=COALESCE(lue_le,NOW()) WHERE utilisateur_id=? AND type_evenement='stage.justification.reviewed' AND JSON_UNQUOTE(JSON_EXTRACT(donnees,'$.justification_uuid'))=?");$n->execute([$userId,$uuid]);}catch(Throwable $e){error_log('[JUSTIFICATION MARK NOTIFICATION READ] '.$e->getMessage());}}
    return $userId>0;
}
function stageJustificationNotifyUniversity(PDO $pdo,int $id):void{
    try{
        require_once __DIR__.'/communication-native.php';
        $s=$pdo->prepare("SELECT j.uuid,j.university_etablissement_id,c.titre,TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)) student_name FROM stage_followup_justifications j JOIN stage_campaigns c ON c.id=j.campaign_id JOIN student_profiles sp ON sp.id=j.student_id WHERE j.id=?");
        $s->execute([$id]);$x=$s->fetch(PDO::FETCH_ASSOC);if(!$x)return;
        $q=$pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN role_assignments ra ON ra.user_id=u.id AND ra.etablissement_id=? AND ra.actif=1 AND ra.revoked_at IS NULL JOIN roles r ON r.id=ra.role_id WHERE u.actif=1 AND r.code IN('ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE')");
        $q->execute([(int)$x['university_etablissement_id']]);
        $data=[
            'event_key'=>'stage.justification.submitted:'.$x['uuid'],
            'justification_uuid'=>$x['uuid'],
            'action'=>[
                'type'=>'stage_justification','target_id'=>$x['uuid'],
                'label'=>'Examiner la justification','title'=>$x['titre'],
                'metadata'=>['status'=>'EN_ATTENTE']
            ]
        ];
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $uid){
            communicationNotifier($pdo,(int)$uid,(int)$x['university_etablissement_id'],'stage.justification.submitted',
                'Nouvelle justification de stage',$x['student_name'].' a envoyé une justification pour « '.$x['titre'].' ».','/views/stages/justifications.php',$data);
        }
    }catch(Throwable $e){error_log('[JUSTIFICATION NOTIFY UNIVERSITY] '.$e->getMessage());}
}
function stageJustificationNotifyStudent(PDO $pdo,int $id):void{
    try{
        require_once __DIR__.'/communication-native.php';
        $s=$pdo->prepare("SELECT j.uuid,j.statut,j.decision_commentaire,j.university_etablissement_id,sp.user_id,c.titre FROM stage_followup_justifications j JOIN student_profiles sp ON sp.id=j.student_id JOIN stage_campaigns c ON c.id=j.campaign_id WHERE j.id=?");
        $s->execute([$id]);$x=$s->fetch(PDO::FETCH_ASSOC);if(!$x)return;
        $accepted=$x['statut']==='ACCEPTEE';$title=$accepted?'Justification de stage acceptée':'Justification de stage rejetée';
        $content='Votre justification pour « '.$x['titre'].' » a été '.($accepted?'acceptée.':'rejetée.').(!empty($x['decision_commentaire'])?' Commentaire : '.$x['decision_commentaire']:'');
        $data=[
            'event_key'=>'stage.justification.reviewed:'.$x['uuid'].':'.$x['statut'],
            'justification_uuid'=>$x['uuid'],
            'action'=>[
                'type'=>'stage_justification','target_id'=>$x['uuid'],
                'label'=>'Voir la décision','title'=>$x['titre'],
                'metadata'=>['status'=>$x['statut']]
            ]
        ];
        communicationNotifier($pdo,(int)$x['user_id'],(int)$x['university_etablissement_id'],'stage.justification.reviewed',
            $title,$content,'/views/espace-etudiant/justifications-stage.php',$data);
    }catch(Throwable $e){error_log('[JUSTIFICATION NOTIFY STUDENT] '.$e->getMessage());}
}

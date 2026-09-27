<?php
/**
 * Endpoint AJAX de saisie et de validation des présences de stage.
 * Il retourne exclusivement des réponses JSON via le helper AJAX commun.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

/* Connexion, format de réponse et contrôles d'autorisation centralisés. */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Les rôles autorisés interviennent dans le pointage ou la revue de présence. */
requireAjaxRole(['ADMIN_ACCUEIL','ENCADREUR','EVALUATEUR_CLINIQUE','POINTEUR']);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

/** Génère l'identifiant UUID v4 attribué aux nouvelles présences. */
function attendanceUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    /* Contexte de l'acteur connecté et valeurs reçues depuis le formulaire. */
    $role=$_SESSION['role_code']??'';$uid=(int)($_SESSION['user_id']??0);
    $hostId=(int)currentEtablissementId($pdo);
    $rotationId=(int)($_POST['rotation_id']??0);
    $date=trim($_POST['date_presence']??'');
    $statut=strtoupper(trim($_POST['statut']??''));
    $arrivee=trim($_POST['heure_arrivee']??'');$depart=trim($_POST['heure_depart']??'');
    $retard=max(0,(int)($_POST['minutes_retard']??0));
    $justification=trim($_POST['justification']??'');$observation=trim($_POST['observation']??'');

    if(!$hostId||!$rotationId||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))jsonResponse(false,'Informations incomplètes ou date invalide.',[],422);
    if(!in_array($statut,['PRESENT','RETARD','ABSENT','JUSTIFIE','GARDE'],true))jsonResponse(false,'Statut invalide.',[],422);
    /* Test local : l'encadreur peut pointer une date future si elle est dans la période de rotation.
       Le pointeur reste limité à la journée courante. */
    if($role==='POINTEUR'&&$date!==date('Y-m-d'))jsonResponse(false,'Le pointeur enregistre uniquement la journée en cours.',[],422);
    if(function_exists('contextPermission')&&!contextPermission(['attendance.hosting.record','attendance.manage']))jsonResponse(false,'Permission insuffisante.',[],403);
    if($statut==='RETARD'&&!$arrivee)jsonResponse(false,"L'heure d'arrivée est obligatoire pour un retard.",[],422);
    if($statut==='RETARD'&&$retard<=0)jsonResponse(false,'Indiquez le nombre de minutes de retard.',[],422);
    if($statut==='JUSTIFIE'&&$justification==='')jsonResponse(false,'La justification est obligatoire.',[],422);
    if($arrivee&&$depart&&$depart<$arrivee)jsonResponse(false,"L'heure de départ doit suivre l'heure d'arrivée.",[],422);

    if(in_array($statut,['ABSENT','JUSTIFIE'],true)){$arrivee=$depart=null;$retard=0;}
    if($statut!=='RETARD')$retard=0;

    /* La présence et son éventuel historique sont enregistrés de manière atomique. */
    $pdo->beginTransaction();

    /* La rotation est chargée dans le périmètre de l'établissement d'accueil. */
    $stmt=$pdo->prepare("SELECT r.id,r.assignment_id,r.host_unit_id,r.date_debut,r.date_fin,sp.id student_id
        FROM stage_rotations r
        INNER JOIN stage_assignments a ON a.id=r.assignment_id
        INNER JOIN stage_admissions ad ON ad.id=a.admission_id
        INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
        INNER JOIN stage_applications sa ON sa.id=sr.application_id
        INNER JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        INNER JOIN student_enrollments se ON se.id=sae.enrollment_id
        INNER JOIN student_profiles sp ON sp.id=se.student_id
        WHERE r.id=? AND r.host_etablissement_id=? AND r.statut<>'ANNULEE' LIMIT 1");
    $stmt->execute([$rotationId,$hostId]);$rotation=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$rotation)throw new RuntimeException('Rotation introuvable.');
    if($date<$rotation['date_debut']||$date>$rotation['date_fin'])throw new RuntimeException('La date est hors période de rotation.');

    /* L'encadreur ne peut intervenir que sur une rotation qui lui est attribuée. */
    if(in_array($role,['ENCADREUR','EVALUATEUR_CLINIQUE'],true)){
        $stmt=$pdo->prepare("SELECT 1 FROM stage_rotation_supervisors WHERE rotation_id=? AND user_id=? AND actif=1 LIMIT 1");
        $stmt->execute([$rotationId,$uid]);
        if(!$stmt->fetchColumn())throw new RuntimeException('Vous n’êtes pas encadreur de cette rotation.');
    }elseif($role==='POINTEUR'){
        $stmt=$pdo->prepare("SELECT 1
            FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
            WHERE ra.user_id=? AND r.code='POINTEUR' AND ra.actif=1
              AND ra.etablissement_id=?
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
              AND (ra.scope_type='ORGANIZATION' OR (ra.scope_type='UNIT' AND ra.scope_entity='HOST_UNIT' AND ra.scope_id=?))
            LIMIT 1");
        $stmt->execute([$uid,$hostId,$rotation['host_unit_id']]);
        if(!$stmt->fetchColumn())throw new RuntimeException('Cette unité ne fait pas partie de votre périmètre de pointage.');
    }

    /* Le verrou SQL évite deux mises à jour concurrentes de la même présence. */
    $stmt=$pdo->prepare("SELECT id,statut,heure_arrivee,heure_depart,minutes_retard,recorded_by,validated_by,source,device_id
        FROM stage_attendances WHERE rotation_id=? AND student_id=? AND date_presence=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$rotationId,$rotation['student_id'],$date]);$current=$stmt->fetch(PDO::FETCH_ASSOC);

    /* Sécurité métier : lors du tout premier pointage, on enregistre uniquement l'arrivée.
       Le départ sera ajouté lors d'une seconde action explicite. */
    if(!$current&&!in_array($statut,['ABSENT','JUSTIFIE'],true))$depart=null;

    /* La validation définitive est réservée aux acteurs habilités, hors pointeur. */
    $canReview=$role!=='POINTEUR'&&(!function_exists('contextPermission')||contextPermission(['attendance.hosting.review','attendance.manage']));

    if($current){
        /* Présence existante : complétion par pointeur ou revue par responsable. */
        $id=(int)$current['id'];
        if($role==='POINTEUR'){
            if((int)($current['recorded_by']??0)!==$uid||!empty($current['validated_by'])||$date!==date('Y-m-d'))
                throw new RuntimeException('Ce pointage existe déjà. Une correction doit être faite par un encadreur ou un responsable habilité.');

            $stmt=$pdo->prepare("UPDATE stage_attendances SET statut=?,heure_arrivee=?,heure_depart=?,minutes_retard=?,justification=?,observation=?,recorded_by=? WHERE id=? AND host_etablissement_id=?");
            $stmt->execute([$statut,$arrivee?:null,$depart?:null,$retard,$justification?:null,$observation?:null,$uid,$id,$hostId]);
            $message='Pointage complété. Il reste à valider par un responsable habilité.';
        }else{
            if(!$canReview)throw new RuntimeException('Vous n’êtes pas autorisé à corriger ou valider cette présence.');

            $stmt=$pdo->prepare("UPDATE stage_attendances SET statut=?,heure_arrivee=?,heure_depart=?,minutes_retard=?,justification=?,observation=?,validated_by=?,validated_at=NOW() WHERE id=? AND host_etablissement_id=?");
            $stmt->execute([$statut,$arrivee?:null,$depart?:null,$retard,$justification?:null,$observation?:null,$uid,$id,$hostId]);

            $historyObs=substr($observation!==''?$observation:'Validation/correction de présence.',0,500);
            $stmt=$pdo->prepare("INSERT INTO stage_attendance_review_history(attendance_id,previous_status,new_status,previous_minutes_retard,new_minutes_retard,observation,reviewer_user_id) VALUES(?,?,?,?,?,?,?)");
            $stmt->execute([$id,$current['statut'],$statut,(int)($current['minutes_retard']??0),$retard,$historyObs,$uid]);
            $message='Présence validée / corrigée avec succès.';
        }
    }else{
        /* Nouvelle présence : celle créée par un pointeur reste non validée. */
        $validatedBy=$role==='POINTEUR'?null:$uid;
        $stmt=$pdo->prepare("INSERT INTO stage_attendances(
            uuid,rotation_id,assignment_id,student_id,host_etablissement_id,date_presence,
            heure_arrivee,heure_depart,statut,minutes_retard,source,justification,observation,
            recorded_by,validated_by,validated_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,'MANUEL',?,?,?,?,IF(? IS NULL,NULL,NOW()))");
        $stmt->execute([
            attendanceUuid(),$rotationId,$rotation['assignment_id'],$rotation['student_id'],$hostId,$date,
            $arrivee?:null,$depart?:null,$statut,$retard,$justification?:null,$observation?:null,
            $uid,$validatedBy,$validatedBy
        ]);
        $id=(int)$pdo->lastInsertId();
        $isAbsence=in_array($statut,['ABSENT','JUSTIFIE'],true);
        if($isAbsence)
            $message=$role==='POINTEUR'?'Absence enregistrée. Elle reste à valider.':'Absence enregistrée et validée.';
        else
            $message=$role==='POINTEUR'?'Arrivée enregistrée. Le départ sera pointé plus tard.':'Arrivée enregistrée. Le départ sera enregistré à la sortie.';
    }

    /* Validation finale de toutes les écritures avant la réponse au navigateur. */
    $pdo->commit();
    jsonResponse(true,$message,['id'=>$id,'statut'=>$statut,'validated'=>$role==='POINTEUR'?0:1]);
}catch(Throwable $e){
    /* Annulation complète puis journalisation si une règle ou une écriture échoue. */
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[HOST ATTENDANCE SAVE] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}

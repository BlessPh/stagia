<?php
/**
 * Endpoint AJAX qui associe un identifiant de pointage à un stagiaire.
 * L'identifiant est rattaché à un appareil précis de l'établissement courant.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

/* Dépendances de persistance, réponse JSON et contrôle des permissions. */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Cette opération d'administration est réservée à l'accueil habilité. */
requireAjaxRole(['ADMIN_ACCUEIL']);
if(function_exists('contextPermission')&&!contextPermission('attendance.device.manage'))
    jsonResponse(false,'Permission insuffisante.',[],403);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* Lecture et normalisation des paramètres du formulaire d'association. */
    $hostId=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $deviceId=(int)($_POST['device_id']??0);
    $studentId=(int)($_POST['student_id']??0);
    $type=strtoupper(trim($_POST['credential_type']??''));
    $ref=trim($_POST['credential_ref']??'');

    if(!$hostId||!$deviceId||!$studentId||$ref==='')jsonResponse(false,'Informations incomplètes.',[],422);
    if(!in_array($type,['EMPREINTE','VISAGE','RFID','NFC','QR','MOBILE','AUTRE'],true))
        jsonResponse(false,'Type d’identifiant invalide.',[],422);

    /* L'appareil doit être actif et appartenir à l'établissement de la session. */
    $s=$pdo->prepare("SELECT 1 FROM attendance_devices WHERE id=? AND host_etablissement_id=? AND actif=1");
    $s->execute([$deviceId,$hostId]);
    if(!$s->fetchColumn())jsonResponse(false,'Appareil invalide.',[],422);

    /* Le stagiaire est contrôlé dans les rotations de cet établissement d'accueil. */
    $s=$pdo->prepare("SELECT 1
        FROM stage_rotations r
        INNER JOIN stage_assignments a ON a.id=r.assignment_id
        INNER JOIN stage_admissions ad ON ad.id=a.admission_id
        INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
        INNER JOIN stage_applications sa ON sa.id=sr.application_id
        INNER JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        INNER JOIN student_enrollments se ON se.id=sae.enrollment_id
        WHERE r.host_etablissement_id=? AND se.student_id=? AND r.statut<>'ANNULEE'
        LIMIT 1");
    $s->execute([$hostId,$studentId]);
    if(!$s->fetchColumn())jsonResponse(false,'Ce stagiaire n’appartient pas aux rotations de cet établissement.',[],422);

    /* La contrainte unique permet de créer ou de réactiver l'association sans doublon. */
    $s=$pdo->prepare("INSERT INTO attendance_device_credentials(
        device_id,student_id,credential_type,credential_ref,actif,created_by
    ) VALUES(?,?,?,?,1,?)
    ON DUPLICATE KEY UPDATE student_id=VALUES(student_id),credential_type=VALUES(credential_type),actif=1");
    $s->execute([$deviceId,$studentId,$type,$ref,$uid]);

    jsonResponse(true,'Identifiant appareil associé au stagiaire.');
}catch(Throwable $e){
    /* Les incidents sont journalisés avant le renvoi de l'erreur AJAX. */
    error_log('[ATTENDANCE CREDENTIAL SAVE] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}

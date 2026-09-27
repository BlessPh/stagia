<?php
/**
 * Endpoint AJAX de consultation des appareils de pointage d'un établissement.
 * Les référentiels d'unités, stagiaires et identifiants sont inclus pour l'interface.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* La liste de configuration est accessible à l'accueil doté du droit de lecture. */
requireAjaxRole(['ADMIN_ACCUEIL']);
if(function_exists('contextPermission')&&!contextPermission('attendance.device.view'))
    jsonResponse(false,'Permission insuffisante.',[],403);

try{
    /* L'établissement de session sert de filtre obligatoire à toutes les requêtes. */
    $hostId=(int)currentEtablissementId($pdo);
    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);

    /* Appareils, unité associée et nombre d'identifiants actifs par appareil. */
    $stmt=$pdo->prepare("
        SELECT d.id,d.uuid,d.code,d.nom,d.type,d.fabricant,d.modele,
               d.integration_mode,d.heure_limite_arrivee,d.actif,d.last_seen_at,
               d.host_unit_id,hu.nom unit_name,hu.code unit_code,
               COUNT(dc.id) credentials_count
        FROM attendance_devices d
        LEFT JOIN host_units hu ON hu.id=d.host_unit_id
        LEFT JOIN attendance_device_credentials dc ON dc.device_id=d.id AND dc.actif=1
        WHERE d.host_etablissement_id=?
        GROUP BY d.id
        ORDER BY d.actif DESC,d.nom
    ");
    $stmt->execute([$hostId]);
    $devices=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("
        SELECT id,code,nom,type,parent_id,actif
        FROM host_units
        WHERE host_etablissement_id=? AND actif=1
        ORDER BY type,nom
    ");
    $stmt->execute([$hostId]);
    $units=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("
        SELECT DISTINCT sp.id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom
        FROM stage_rotations r
        INNER JOIN stage_assignments a ON a.id=r.assignment_id
        INNER JOIN stage_admissions ad ON ad.id=a.admission_id
        INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
        INNER JOIN stage_applications sa ON sa.id=sr.application_id
        INNER JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        INNER JOIN student_enrollments se ON se.id=sae.enrollment_id
        INNER JOIN student_profiles sp ON sp.id=se.student_id
        WHERE r.host_etablissement_id=? AND r.statut<>'ANNULEE'
        ORDER BY sp.nom,sp.prenom
    ");
    $stmt->execute([$hostId]);
    $students=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("
        SELECT dc.id,dc.device_id,dc.student_id,dc.credential_type,dc.credential_ref,dc.actif,
               sp.stagia_code,sp.nom,sp.postnom,sp.prenom
        FROM attendance_device_credentials dc
        INNER JOIN attendance_devices d ON d.id=dc.device_id
        INNER JOIN student_profiles sp ON sp.id=dc.student_id
        WHERE d.host_etablissement_id=?
        ORDER BY d.nom,sp.nom
    ");
    $stmt->execute([$hostId]);
    $credentials=$stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Conversion explicite des identifiants et booléens avant l'encodage JSON. */
    foreach($devices as &$d){
        $d['id']=(int)$d['id'];$d['host_unit_id']=$d['host_unit_id']!==null?(int)$d['host_unit_id']:null;
        $d['actif']=(int)$d['actif'];$d['credentials_count']=(int)$d['credentials_count'];
    }unset($d);
    foreach($students as &$s)$s['id']=(int)$s['id'];unset($s);
    foreach($credentials as &$c){
        $c['id']=(int)$c['id'];$c['device_id']=(int)$c['device_id'];$c['student_id']=(int)$c['student_id'];$c['actif']=(int)$c['actif'];
    }unset($c);

    /* Réponse groupée afin d'éviter plusieurs chargements côté interface. */
    jsonResponse(true,'',['devices'=>$devices,'units'=>$units,'students'=>$students,'credentials'=>$credentials]);
}catch(Throwable $e){
    /* Les détails techniques sont journalisés côté serveur. */
    error_log('[ATTENDANCE DEVICE LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur appareils : '.$e->getMessage(),[],500);
}

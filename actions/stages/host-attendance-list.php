<?php
/**
 * Endpoint AJAX qui compose le registre de présence d'une journée donnée.
 * Les lignes retournées sont filtrées selon le rôle et le périmètre de l'acteur.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Consultation destinée aux acteurs opérationnels du suivi de présence. */
requireAjaxRole(['ADMIN_ACCUEIL','ENCADREUR','EVALUATEUR_CLINIQUE','POINTEUR']);

try{
    /* Identification de l'établissement courant et de la date demandée. */
    $role=$_SESSION['role_code']??'';$uid=(int)($_SESSION['user_id']??0);
    $hostId=(int)currentEtablissementId($pdo);
    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);

    $date=trim($_GET['date']??date('Y-m-d'));
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))jsonResponse(false,'Date invalide.',[],422);

    if(function_exists('contextPermission')&&!contextPermission(['attendance.hosting.view','attendance.hosting.record','attendance.manage']))
        jsonResponse(false,'Permission insuffisante.',[],403);

    /* Fragment SQL ajouté pour borner les encadreurs et pointeurs à leur périmètre. */
    $scopeSql='';$scopeParams=[];
    if(in_array($role,['ENCADREUR','EVALUATEUR_CLINIQUE'],true)){
        $scopeSql=" AND EXISTS(
            SELECT 1 FROM stage_rotation_supervisors srs
            WHERE srs.rotation_id=r.id AND srs.user_id=? AND srs.actif=1
        )";
        $scopeParams[]=$uid;
    }elseif($role==='POINTEUR'){
        $scopeSql=" AND EXISTS(
            SELECT 1
            FROM role_assignments pra
            JOIN roles pr ON pr.id=pra.role_id
            WHERE pra.user_id=? AND pr.code='POINTEUR'
              AND pra.actif=1 AND pra.etablissement_id=?
              AND (pra.starts_at IS NULL OR pra.starts_at<=NOW())
              AND (pra.ends_at IS NULL OR pra.ends_at>=NOW())
              AND (
                  pra.scope_type='ORGANIZATION'
                  OR (pra.scope_type='UNIT' AND pra.scope_entity='HOST_UNIT' AND pra.scope_id=r.host_unit_id)
              )
        )";
        array_push($scopeParams,$uid,$hostId);
    }

    /* La requête réunit rotation, étudiant, présence éventuelle et appareil associé. */
    $stmt=$pdo->prepare("SELECT
        r.id rotation_id,r.sequence_no,r.date_debut,r.date_fin,
        a.id assignment_id,
        sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
        hu.code unit_code,hu.nom unit_name,p.nom parent_name,
        e.nom university_name,
        att.id attendance_id,att.statut,att.heure_arrivee,att.heure_depart,
        att.minutes_retard,att.source,att.device_id,att.justification,att.observation,
        att.recorded_by,att.validated_by,att.validated_at,
        dev.nom device_name,dev.code device_code,dev.type device_type
    FROM stage_rotations r
    INNER JOIN stage_assignments a ON a.id=r.assignment_id
    INNER JOIN stage_admissions ad ON ad.id=a.admission_id
    INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
    INNER JOIN stage_applications sa ON sa.id=sr.application_id
    INNER JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
    INNER JOIN student_enrollments se ON se.id=sae.enrollment_id
    INNER JOIN student_profiles sp ON sp.id=se.student_id
    INNER JOIN stage_campaigns c ON c.id=sa.campaign_id
    INNER JOIN etablissements e ON e.id=c.owner_etablissement_id
    INNER JOIN host_units hu ON hu.id=r.host_unit_id
    LEFT JOIN host_units p ON p.id=hu.parent_id
    LEFT JOIN stage_attendances att ON att.rotation_id=r.id AND att.student_id=sp.id AND att.date_presence=?
    LEFT JOIN attendance_devices dev ON dev.id=att.device_id
    WHERE r.host_etablissement_id=? AND r.statut<>'ANNULEE'
      AND ? BETWEEN r.date_debut AND r.date_fin
      $scopeSql
    ORDER BY hu.nom,sp.nom,sp.prenom");

    $stmt->execute(array_merge([$date,$hostId,$date],$scopeParams));
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $canReview=$role!=='POINTEUR' && (!function_exists('contextPermission')||contextPermission(['attendance.hosting.review','attendance.manage']));
    $canRecord=!function_exists('contextPermission')||contextPermission(['attendance.hosting.record','attendance.manage']);
    $today=date('Y-m-d');
    $stats=['total'=>count($items),'presents'=>0,'retards'=>0,'absents'=>0,'justifies'=>0,'gardes'=>0,'non_pointes'=>0,'a_valider'=>0];

    /* Conversion des types JSON, droits par ligne et agrégation des statistiques. */
    foreach($items as &$x){
        foreach(['rotation_id','assignment_id','student_id'] as $f)$x[$f]=(int)$x[$f];
        $x['attendance_id']=$x['attendance_id']!==null?(int)$x['attendance_id']:null;
        $x['minutes_retard']=(int)($x['minutes_retard']??0);
        $x['recorded_by']=$x['recorded_by']!==null?(int)$x['recorded_by']:null;
        $x['validated_by']=$x['validated_by']!==null?(int)$x['validated_by']:null;
        $x['device_id']=$x['device_id']!==null?(int)$x['device_id']:null;

        $existing=(bool)$x['attendance_id'];
        $pointerCanComplete=$role==='POINTEUR'&&$existing&&!$x['validated_by']&&$x['recorded_by']===$uid&&$date===$today;
        $x['can_record']=$canRecord&&(!$existing||$pointerCanComplete)&&($role!=='POINTEUR'||$date===$today)?1:0;
        $x['can_review']=$existing&&$canReview?1:0;
        if($existing&&!$x['validated_by'])$stats['a_valider']++;

        switch($x['statut']){
            case 'PRESENT':$stats['presents']++;break;
            case 'RETARD':$stats['retards']++;break;
            case 'ABSENT':$stats['absents']++;break;
            case 'JUSTIFIE':$stats['justifies']++;break;
            case 'GARDE':$stats['gardes']++;break;
            default:$stats['non_pointes']++;
        }
    }
    unset($x);

    /* Réponse unique : lignes du registre, compteurs et rôle de l'utilisateur connecté. */
    jsonResponse(true,'',['date'=>$date,'items'=>$items,'stats'=>$stats,'actor_role'=>$role]);
}catch(Throwable $e){
    /* Les détails restent consignés côté serveur ; le client reçoit une erreur AJAX. */
    error_log('[HOST ATTENDANCE LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur présences : '.$e->getMessage(),[],500);
}

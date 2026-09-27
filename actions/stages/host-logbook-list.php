<?php
/**
 * Endpoint AJAX de consultation des journaux de bord soumis et en attente de revue.
 * Les encadreurs ne voient que les rotations dont ils sont explicitement responsables.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Accès réservé aux acteurs d'accueil et d'encadrement habilités. */
requireAjaxRole(['ADMIN_ACCUEIL','ENCADREUR','EVALUATEUR_CLINIQUE']);

if(function_exists('contextPermission')&&!contextPermission('supervision.hosting.view'))
    jsonResponse(false,'Permission insuffisante.',[],403);

try{
    /* Le contexte de session fixe à la fois l'établissement et le rôle de consultation. */
    $hostId=(int)currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);
    $role=$_SESSION['role_code']??'';

    if(!$hostId||!$userId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    $canReviewPermission=!function_exists('contextPermission')||contextPermission('logbook.hosting.review');

    /* La page Journaux affiche seulement les journaux à traiter.
       Après validation ou rejet, le statut change et le journal disparaît. */
    /* La liste est volontairement limitée aux journaux soumis, donc encore à traiter. */
    $where=[
        "e.host_etablissement_id=?",
        "r.host_etablissement_id=?",
        "e.statut='SOUMIS'"
    ];
    $params=[$hostId,$hostId];

    /* L'encadreur voit seulement les journaux de ses rotations. */
    if(in_array($role,['ENCADREUR','EVALUATEUR_CLINIQUE'],true)){
        $where[]="EXISTS(
            SELECT 1
            FROM stage_rotation_supervisors srs
            WHERE srs.rotation_id=r.id
              AND srs.user_id=?
              AND srs.actif=1
        )";
        $params[]=$userId;
    }

    $stmt=$pdo->prepare("
        SELECT
            e.id,e.date_journal,e.resume_activites,e.apprentissages,
            e.difficultes,e.observation_etudiant,e.statut,
            e.commentaire_encadreur,e.submitted_at,e.validated_at,

            sp.stagia_code,sp.nom,sp.postnom,sp.prenom,

            r.sequence_no,r.date_debut AS rotation_debut,
            r.date_fin AS rotation_fin,r.statut AS rotation_statut,

            hu.code AS unit_code,hu.nom AS unit_name,

            c.code AS campaign_code,c.titre AS campaign_title,
            uni.nom AS university_name,

            EXISTS(
                SELECT 1
                FROM stage_rotation_supervisors srx
                WHERE srx.rotation_id=r.id
                  AND srx.user_id=?
                  AND srx.actif=1
            ) AS is_rotation_supervisor,

            (
                SELECT COUNT(*)
                FROM stage_logbook_activities la
                WHERE la.logbook_entry_id=e.id
            ) AS activities_count

        FROM stage_logbook_entries e
        INNER JOIN student_profiles sp ON sp.id=e.student_id
        INNER JOIN stage_rotations r ON r.id=e.rotation_id
        INNER JOIN host_units hu ON hu.id=r.host_unit_id
        INNER JOIN stage_assignments ass ON ass.id=e.assignment_id
        INNER JOIN stage_admissions ad ON ad.id=ass.admission_id
        INNER JOIN stage_reservations sr ON sr.id=ad.reservation_id
        INNER JOIN stage_applications sa ON sa.id=sr.application_id
        INNER JOIN stage_campaigns c ON c.id=sa.campaign_id
        INNER JOIN etablissements uni ON uni.id=c.owner_etablissement_id

        WHERE ".implode(' AND ',$where)."

        ORDER BY e.date_journal DESC,e.id DESC
    ");

    $stmt->execute(array_merge([$userId],$params));
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Les activités détaillées sont chargées pour chaque journal retourné. */
    $activityStmt=$pdo->prepare("
        SELECT id,type_activite,intitule,description,
               niveau_implication,quantite,observation
        FROM stage_logbook_activities
        WHERE logbook_entry_id=?
        ORDER BY id
    ");

    /* Comme on filtre uniquement SOUMIS :
       total = journaux à traiter,
       soumis = journaux à valider,
       valides/rejetes restent à 0 sur cette page. */
    $stats=[
        'total'=>count($items),
        'soumis'=>count($items),
        'valides'=>0,
        'rejetes'=>0
    ];

    /* Conversion des types et calcul du droit de revue à partir de la période et de l'encadreur. */
    foreach($items as &$x){
        $x['id']=(int)$x['id'];
        $x['sequence_no']=(int)$x['sequence_no'];
        $x['activities_count']=(int)$x['activities_count'];
        $x['is_rotation_supervisor']=(int)$x['is_rotation_supervisor'];

        /* Journal dans la période de rotation */
        $dateOk=!empty($x['date_journal'])
            && !empty($x['rotation_debut'])
            && !empty($x['rotation_fin'])
            && $x['date_journal']>=$x['rotation_debut']
            && $x['date_journal']<=$x['rotation_fin'];

        /* En test, on garde PLANIFIEE pour permettre la validation avant la date réelle. */
        $rotationOk=in_array($x['rotation_statut'],['PLANIFIEE','ACTIVE','TERMINEE'],true);

        /* Validation seulement par l’encadreur affecté à la rotation. */
        $x['can_review']=(
            $canReviewPermission
            && $x['is_rotation_supervisor']===1
            && $dateOk
            && $rotationOk
        )?1:0;

        $activityStmt->execute([$x['id']]);
        $x['activities']=$activityStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($x);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>$stats
    ]);

}catch(Throwable $e){
    error_log('[HOST LOGBOOK LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur journaux : '.$e->getMessage(),[],500);
}

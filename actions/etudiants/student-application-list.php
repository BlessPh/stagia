<?php

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);

try{

    $userId=(int)($_SESSION['user_id']??0);

    if(!$userId)
        jsonResponse(false,'Session étudiant invalide.',[],401);


    /* PROFIL */
    $stmt=$pdo->prepare("
        SELECT id
        FROM student_profiles
        WHERE user_id=?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    $studentId=(int)$stmt->fetchColumn();

    if(!$studentId)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);


    /* CANDIDATURES */
    $stmt=$pdo->prepare("
        SELECT
            sa.id,
            sa.uuid,
            sa.statut,
            sa.motivation,
            sa.motif_refus,
            sa.submitted_at,
            sa.responded_at,

            c.id AS campaign_id,
            c.code AS campaign_code,
            c.titre AS campaign_title,
            c.date_debut,
            c.date_fin,

            h.id AS host_id,
            h.code AS host_code,
            h.nom AS host_name,
            h.ville,
            h.province,

            sr.id AS reservation_id,
            sr.uuid AS reservation_uuid,
            sr.statut AS reservation_status,
            sr.expires_at,
            sr.confirmed_at,

            ad.id AS admission_id,

            ass.id AS assignment_id,
            ass.statut AS assignment_status,

            comp.id AS completion_id,
            comp.statut AS completion_status

        FROM stage_applications sa

        INNER JOIN student_academic_enrollments ae
            ON ae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        INNER JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        INNER JOIN etablissements h
            ON h.id=sa.host_etablissement_id

        LEFT JOIN stage_reservations sr
            ON sr.application_id=sa.id

        LEFT JOIN stage_admissions ad
            ON ad.reservation_id=sr.id

        LEFT JOIN stage_assignments ass
            ON ass.admission_id=ad.id

        LEFT JOIN stage_completions comp
            ON comp.assignment_id=ass.id

        WHERE se.student_id=?

        ORDER BY
            sa.submitted_at DESC,
            sa.id DESC
    ");

    $stmt->execute([$studentId]);

    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);


    foreach($items as &$x){

        $x['id']=(int)$x['id'];

        $x['campaign_id']=(int)$x['campaign_id'];

        $x['host_id']=(int)$x['host_id'];

        $x['reservation_id']=
            $x['reservation_id']!==null
                ?(int)$x['reservation_id']
                :null;

        $x['admission_id']=
            $x['admission_id']!==null
                ?(int)$x['admission_id']
                :null;

        $x['assignment_id']=
            $x['assignment_id']!==null
                ?(int)$x['assignment_id']
                :null;

        $x['completion_id']=
            $x['completion_id']!==null
                ?(int)$x['completion_id']
                :null;
    }

    unset($x);


    jsonResponse(
        true,
        '',
        [
            'items'=>$items
        ]
    );


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur candidatures : '.$e->getMessage(),
        [],
        500
    );
}
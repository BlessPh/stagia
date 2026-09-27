<?php

require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');

$student=requireApiStudent($pdo);

$studentId=(int)$student['student_id'];


try{

    /* =====================================================
       1. CANDIDATURES
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT COUNT(*)

        FROM stage_applications sa

        INNER JOIN student_academic_enrollments ae
            ON ae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        WHERE se.student_id=?
    ");

    $stmt->execute([
        $studentId
    ]);

    $applications=(int)$stmt->fetchColumn();


    /* =====================================================
       2. RÉSERVATIONS ACTIVES
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT COUNT(*)

        FROM stage_reservations sr

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments ae
            ON ae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        WHERE se.student_id=?

          AND sr.statut IN(
              'RESERVEE_TEMPORAIREMENT',
              'EN_ATTENTE_PAIEMENT',
              'CONFIRMEE'
          )

          AND (
              sr.statut<>'RESERVEE_TEMPORAIREMENT'
              OR sr.expires_at IS NULL
              OR sr.expires_at>NOW()
          )
    ");

    $stmt->execute([
        $studentId
    ]);

    $reservations=(int)$stmt->fetchColumn();


    /* =====================================================
       3. STAGES
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT

            COUNT(*) AS total,

            SUM(
                CASE
                    WHEN a.statut='PLANIFIEE'
                    THEN 1
                    ELSE 0
                END
            ) AS planned,

            SUM(
                CASE
                    WHEN a.statut='ACTIVE'
                    THEN 1
                    ELSE 0
                END
            ) AS active,

            SUM(
                CASE
                    WHEN a.statut='TERMINEE'
                    THEN 1
                    ELSE 0
                END
            ) AS completed

        FROM stage_assignments a

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments ae
            ON ae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        WHERE se.student_id=?

          AND a.statut<>'ANNULEE'
    ");

    $stmt->execute([
        $studentId
    ]);

    $stageStats=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    /* =====================================================
       4. STAGES VALIDÉS
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT COUNT(*)

        FROM stage_completions

        WHERE student_id=?
          AND statut='VALIDE'
    ");

    $stmt->execute([
        $studentId
    ]);

    $validatedStages=(int)$stmt->fetchColumn();


    /* =====================================================
       5. DOCUMENTS OFFICIELS
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT COUNT(*)

        FROM stage_certificates

        WHERE student_id=?
          AND statut='GENERE'
    ");

    $stmt->execute([
        $studentId
    ]);

    $documents=(int)$stmt->fetchColumn();


    /* =====================================================
       6. STAGE ACTUEL / DERNIER STAGE
    ====================================================== */

    $stmt=$pdo->prepare("
        SELECT

            a.uuid,
            a.statut,
            a.date_debut,
            a.date_fin,

            c.code AS campaign_code,
            c.titre AS campaign_title,

            h.code AS hospital_code,
            h.nom AS hospital_name,

            hu.code AS unit_code,
            hu.nom AS unit_name,

            comp.statut AS completion_status,
            comp.taux_presence,
            comp.note_finale,

            cert.uuid AS certificate_uuid,
            cert.reference AS certificate_reference

        FROM stage_assignments a

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments ae
            ON ae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=ae.enrollment_id

        INNER JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        INNER JOIN etablissements h
            ON h.id=a.host_etablissement_id

        LEFT JOIN host_units hu
            ON hu.id=a.host_unit_id

        LEFT JOIN stage_completions comp
            ON comp.assignment_id=a.id

        LEFT JOIN stage_certificates cert
            ON cert.completion_id=comp.id
           AND cert.statut='GENERE'

        WHERE se.student_id=?

          AND a.statut<>'ANNULEE'

        ORDER BY

            CASE a.statut

                WHEN 'ACTIVE'
                THEN 1

                WHEN 'PLANIFIEE'
                THEN 2

                WHEN 'TERMINEE'
                THEN 3

                ELSE 4

            END,

            a.date_debut DESC

        LIMIT 1
    ");

    $stmt->execute([
        $studentId
    ]);

    $currentStage=$stmt->fetch(
        PDO::FETCH_ASSOC
    );


    if($currentStage){

        if(
            $currentStage['taux_presence']
            !==null
        ){
            $currentStage['taux_presence']=
                (float)$currentStage['taux_presence'];
        }


        if(
            $currentStage['note_finale']
            !==null
        ){
            $currentStage['note_finale']=
                (float)$currentStage['note_finale'];
        }
    }


    /* =====================================================
       7. RÉPONSE
    ====================================================== */

    apiResponse(
        true,
        '',
        [

            'student'=>[

                'stagia_code'=>
                    $student['stagia_code'],

                'nom'=>
                    $student['nom'],

                'postnom'=>
                    $student['postnom'],

                'prenom'=>
                    $student['prenom'],

                'email'=>
                    $student['email'],

                'identifiant'=>
                    $student['identifiant']

            ],


            'stats'=>[

                'applications'=>
                    $applications,

                'active_reservations'=>
                    $reservations,

                'stages'=>
                    (int)($stageStats['total']??0),

                'planned_stages'=>
                    (int)($stageStats['planned']??0),

                'active_stages'=>
                    (int)($stageStats['active']??0),

                'completed_stages'=>
                    (int)($stageStats['completed']??0),

                'validated_stages'=>
                    $validatedStages,

                'documents'=>
                    $documents

            ],


            'current_stage'=>
                $currentStage?:null

        ]
    );


}catch(Throwable $e){

    apiResponse(
        false,
        'Erreur dashboard : '.$e->getMessage(),
        [],
        500
    );
}
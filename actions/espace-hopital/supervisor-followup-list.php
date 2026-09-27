<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-execution.php';
require_once __DIR__.'/../../includes/supervisor-access.php';

requirePermission($pdo,'supervision.hosting.view');

try{
    $eid=(int)($_SESSION['etablissement_id']??0);
    $userId=(int)($_SESSION['user_id']??0);

    if(!$eid||!$userId){
        jsonResponse(false,'Contexte établissement invalide.',[],403);
    }

    syncStageExecution(
        $pdo,
        ['host_etablissement_id'=>$eid]
    );

    $admin=stagiaIsHospitalAdmin();

    $sql="
        SELECT DISTINCT
            r.id rotation_id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut,
            hu.code unit_code,
            hu.nom unit_name,
            hu.type unit_type,
            parent.nom parent_name,
            c.code campaign_code,
            c.titre campaign_title,
            pr.nom promotion_name,
            al.code level_code,
            g.nom group_name
        FROM stage_rotations r
        JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN host_units parent ON parent.id=hu.parent_id

        JOIN stage_assignments sa ON sa.id=r.assignment_id
        JOIN stage_admissions ad ON ad.id=sa.admission_id
        JOIN stage_placements pl ON pl.id=ad.placement_id
        JOIN stage_campaigns c ON c.id=pl.campaign_id

        JOIN student_academic_enrollments ae
          ON ae.id=pl.academic_enrollment_id
        JOIN promotions pr ON pr.id=ae.promotion_id
        LEFT JOIN academic_levels al ON al.id=pr.academic_level_id

        LEFT JOIN stage_group_students gs
          ON gs.campaign_id=pl.campaign_id
         AND gs.academic_enrollment_id=pl.academic_enrollment_id
        LEFT JOIN stage_groups g ON g.id=gs.group_id

        WHERE r.host_etablissement_id=?
          AND r.statut<>'ANNULEE'
    ";

    $params=[$eid];

    if(!$admin){
        $sql.="
          AND EXISTS(
              SELECT 1
              FROM stage_rotation_supervisors rs
              WHERE rs.rotation_id=r.id
                AND rs.user_id=?
                AND rs.actif=1
          )
        ";
        $params[]=$userId;
    }

    $sql.="
        ORDER BY
            CASE r.statut
                WHEN 'ACTIVE' THEN 1
                WHEN 'PLANIFIEE' THEN 2
                WHEN 'TERMINEE' THEN 3
                ELSE 4
            END,
            r.date_debut DESC,
            r.sequence_no
    ";

    $s=$pdo->prepare($sql);
    $s->execute($params);
    $rotations=$s->fetchAll(PDO::FETCH_ASSOC);

    $rotationId=(int)($_GET['rotation_id']??0);

    if(!$rotationId && $rotations){
        $active=array_values(
            array_filter(
                $rotations,
                fn($r)=>$r['statut']==='ACTIVE'
            )
        );

        $rotationId=(int)(
            ($active[0]['rotation_id']??null)
            ?: $rotations[0]['rotation_id']
        );
    }

    $selected=null;

    foreach($rotations as $r){
        if((int)$r['rotation_id']===$rotationId){
            $selected=$r;
            break;
        }
    }

    $today=date('Y-m-d');
    $selectedDate=trim((string)($_GET['date']??$today));

    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$selectedDate)){
        $selectedDate=$today;
    }

    $students=[];
    $journals=[];

    $stats=[
        'students'=>0,
        'pointed'=>0,
        'not_pointed'=>0,
        'journals_pending'=>0
    ];

    if($selected){

        /*
         * Stagiaires de la rotation :
         * une rotation individuelle correspond à une affectation/stagiaire.
         * Comme les groupes publient une rotation par étudiant, on récupère
         * ici toutes les rotations ayant le même plan collectif.
         *
         * Fallback : si group_rotation_plan_id est NULL, on reste sur la
         * rotation sélectionnée.
         */
        $s=$pdo->prepare("
            SELECT group_rotation_plan_id
            FROM stage_rotations
            WHERE id=?
            LIMIT 1
        ");
        $s->execute([$rotationId]);
        $planId=(int)$s->fetchColumn();

        if($planId){
            $whereRotation="r.group_rotation_plan_id=?";
            $rotationParams=[$planId];
        }else{
            $whereRotation="r.id=?";
            $rotationParams=[$rotationId];
        }

        $sqlStudents="
            SELECT
                r.id rotation_id,
                r.assignment_id,
                sp.id student_id,
                sp.stagia_code,
                sp.nom,
                sp.postnom,
                sp.prenom,

                att.id attendance_id,
                att.heure_arrivee,
                att.heure_depart,
                att.statut attendance_status,
                att.minutes_retard,
                att.source,
                att.validated_at

            FROM stage_rotations r
            JOIN stage_assignments sa ON sa.id=r.assignment_id
            JOIN stage_admissions ad ON ad.id=sa.admission_id
            JOIN stage_placements pl ON pl.id=ad.placement_id
            JOIN student_profiles sp ON sp.id=pl.student_id

            LEFT JOIN stage_attendances att
              ON att.rotation_id=r.id
             AND att.student_id=sp.id
             AND att.date_presence=?

            WHERE $whereRotation
              AND r.host_etablissement_id=?

            ORDER BY sp.nom,sp.postnom,sp.prenom
        ";

        $s=$pdo->prepare($sqlStudents);
        $s->execute(
            array_merge(
                [$selectedDate],
                $rotationParams,
                [$eid]
            )
        );
        $students=$s->fetchAll(PDO::FETCH_ASSOC);

        $stats['students']=count($students);
        $stats['pointed']=count(
            array_filter(
                $students,
                fn($x)=>!empty($x['attendance_id'])
            )
        );
        $stats['not_pointed']=$stats['students']-$stats['pointed'];

        $sqlJournals="
            SELECT
                e.id,
                e.rotation_id,
                e.date_journal,
                e.resume_activites,
                e.apprentissages,
                e.difficultes,
                e.observation_etudiant,
                e.statut,
                e.commentaire_encadreur,
                e.submitted_at,
                e.validated_at,

                sp.stagia_code,
                sp.nom,
                sp.postnom,
                sp.prenom,

                (
                    SELECT COUNT(*)
                    FROM stage_logbook_activities la
                    WHERE la.logbook_entry_id=e.id
                ) activities_count

            FROM stage_logbook_entries e
            JOIN stage_rotations r ON r.id=e.rotation_id
            JOIN student_profiles sp ON sp.id=e.student_id

            WHERE $whereRotation
              AND r.host_etablissement_id=?
              AND e.statut IN('SOUMIS','VALIDE','REJETE')

            ORDER BY
                CASE e.statut
                    WHEN 'SOUMIS' THEN 1
                    WHEN 'REJETE' THEN 2
                    WHEN 'VALIDE' THEN 3
                    ELSE 4
                END,
                e.date_journal DESC,
                e.id DESC
        ";

        $s=$pdo->prepare($sqlJournals);
        $s->execute(
            array_merge(
                $rotationParams,
                [$eid]
            )
        );
        $journals=$s->fetchAll(PDO::FETCH_ASSOC);

        $stats['journals_pending']=count(
            array_filter(
                $journals,
                fn($x)=>$x['statut']==='SOUMIS'
            )
        );
    }

    jsonResponse(true,'',[
        'today'=>$today,
        'is_admin'=>$admin,
        'rotations'=>$rotations,
        'selected'=>$selected,
        'selected_date'=>$selectedDate,
        'students'=>$students,
        'journals'=>$journals,
        'stats'=>$stats,
        'permissions'=>[
            'attendance_review'=>hasPermission(
                $pdo,
                'attendance.hosting.review'
            ),
            'logbook_review'=>hasPermission(
                $pdo,
                'logbook.hosting.review'
            )
        ]
    ]);

}catch(Throwable $e){
    jsonResponse(
        false,
        'Erreur suivi encadreur : '.$e->getMessage(),
        [],
        500
    );
}

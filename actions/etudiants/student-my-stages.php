<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);

try{
    $userId=(int)($_SESSION['user_id']??0);

    if(!$userId)
        jsonResponse(false,'Session étudiant invalide.',[],401);

    /* =========================================================
       PROFIL ÉTUDIANT COURANT
    ========================================================= */
    $s=$pdo->prepare("
        SELECT id,stagia_code,nom,postnom,prenom
        FROM student_profiles
        WHERE user_id=?
        LIMIT 1
    ");
    $s->execute([$userId]);
    $student=$s->fetch(PDO::FETCH_ASSOC);

    if(!$student)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $studentId=(int)$student['id'];

    /* =========================================================
       STAGES RÉELLEMENT ADMIS / AFFECTÉS

       Flux actuel :
       student_profiles
       -> student_enrollments
       -> student_academic_enrollments
       -> stage_applications
       -> stage_reservations CONFIRMEE
       -> stage_admissions
       -> stage_assignments

       Aucun stage_placements.
    ========================================================= */
    $s=$pdo->prepare("
        SELECT DISTINCT
            sa.id AS assignment_id,
            sa.statut AS assignment_status,
            sa.date_debut,
            sa.date_fin,

            ad.id AS admission_id,
            ad.statut AS admission_status,

            app.id AS application_id,
            app.campaign_id,
            app.host_etablissement_id,

            c.code AS campaign_code,
            c.titre AS campaign_title,

            st.code AS stage_type_code,

            h.nom AS host_name,

            hu.id AS initial_unit_id,
            hu.code AS initial_unit_code,
            hu.nom AS initial_unit_name,
            hu.type AS initial_unit_type,

            ae.id AS academic_enrollment_id,
            p.id AS promotion_id,
            p.nom AS promotion_name,

            g.id AS group_id,
            g.code AS group_code,
            g.nom AS group_name,
            g.statut AS group_status,

            comp.id AS completion_id,
            comp.statut AS completion_status,
            comp.taux_presence,
            comp.note_finale

        FROM student_profiles sp

        JOIN student_enrollments se
          ON se.student_id=sp.id

        JOIN student_academic_enrollments ae
          ON ae.enrollment_id=se.id

        JOIN stage_applications app
          ON app.academic_enrollment_id=ae.id

        JOIN stage_reservations sr
          ON sr.application_id=app.id
         AND sr.statut='CONFIRMEE'

        JOIN stage_admissions ad
          ON ad.reservation_id=sr.id
         AND ad.statut IN('ADMIS','EN_COURS','TERMINE')

        JOIN stage_assignments sa
          ON sa.admission_id=ad.id
         AND sa.host_etablissement_id=app.host_etablissement_id
         AND sa.statut<>'ANNULEE'

        JOIN stage_campaigns c
          ON c.id=app.campaign_id

        LEFT JOIN stage_types st
          ON st.id=c.stage_type_id

        JOIN etablissements h
          ON h.id=app.host_etablissement_id

        LEFT JOIN host_units hu
          ON hu.id=sa.host_unit_id

        LEFT JOIN promotions p
          ON p.id=ae.promotion_id

        LEFT JOIN stage_group_students gs
          ON gs.campaign_id=app.campaign_id
         AND gs.academic_enrollment_id=ae.id

        LEFT JOIN stage_groups g
          ON g.id=gs.group_id
         AND g.host_etablissement_id=app.host_etablissement_id
         AND g.statut<>'ANNULE'

        LEFT JOIN stage_completions comp
          ON comp.assignment_id=sa.id

        WHERE sp.id=?

        ORDER BY sa.date_debut DESC,sa.id DESC
    ");
    $s->execute([$studentId]);
    $stages=$s->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================================
       ROTATIONS / ENCADREURS
    ========================================================= */
    $rotationStmt=$pdo->prepare("
        SELECT
            r.id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut,

            hu.code AS unit_code,
            hu.nom AS unit_name,
            hu.type AS unit_type,

            CONCAT_WS(' ',u.prenom,u.nom,u.postnom) AS supervisor_name,

            COALESCE(
                NULLIF(eu.fonction,''),
                (
                    SELECT ro.nom
                    FROM role_assignments ra
                    JOIN roles ro ON ro.id=ra.role_id
                    WHERE ra.user_id=u.id
                      AND ra.etablissement_id=r.host_etablissement_id
                      AND ra.actif=1
                      AND ra.revoked_at IS NULL
                    ORDER BY ra.principal DESC,ra.id
                    LIMIT 1
                ),
                'Encadreur'
            ) AS supervisor_function

        FROM stage_rotations r

        JOIN host_units hu
          ON hu.id=r.host_unit_id

        LEFT JOIN stage_rotation_supervisors rs
          ON rs.rotation_id=r.id
         AND rs.principal=1
         AND rs.actif=1

        LEFT JOIN users u
          ON u.id=rs.user_id

        LEFT JOIN etablissement_users eu
          ON eu.user_id=u.id
         AND eu.etablissement_id=r.host_etablissement_id

        WHERE r.assignment_id=?
          AND r.statut<>'ANNULEE'

        ORDER BY r.sequence_no,r.date_debut,r.id
    ");

    $today=date('Y-m-d');

    foreach($stages as &$stage){
        $rotationStmt->execute([(int)$stage['assignment_id']]);
        $rotations=$rotationStmt->fetchAll(PDO::FETCH_ASSOC);

        $current=null;
        $next=null;
        $hasEnded=false;

        foreach($rotations as $rotation){
            if($rotation['statut']==='TERMINEE')
                $hasEnded=true;

            if(
                $current===null &&
                (
                    $rotation['statut']==='ACTIVE' ||
                    (
                        $rotation['statut']==='PLANIFIEE' &&
                        $rotation['date_debut']<=$today &&
                        $rotation['date_fin']>=$today
                    )
                )
            ){
                $current=$rotation;
            }

            if(
                $next===null &&
                $rotation['statut']==='PLANIFIEE' &&
                $rotation['date_debut']>$today
            ){
                $next=$rotation;
            }
        }

        $assignmentStatus=(string)$stage['assignment_status'];
        $completionStatus=(string)($stage['completion_status']??'');

        if($completionStatus==='VALIDE'){
            $effective='VALIDE';
        }elseif($assignmentStatus==='TERMINEE'){
            $effective='A_CLOTURER';
        }elseif($assignmentStatus==='ACTIVE'){
            $effective='EN_COURS';
        }elseif($assignmentStatus==='PLANIFIEE'){
            $effective='PLANIFIE';
        }else{
            $effective=$assignmentStatus?:'PLANIFIE';
        }

        $canExecution=$current!==null;

        $reason=$canExecution
            ?''
            :(
                $next
                    ?'Le suivi sera disponible à partir du '.$next['date_debut'].'.'
                    :(
                        $hasEnded
                            ?'Les rotations planifiées sont terminées.'
                            :'Aucune rotation active aujourd’hui.'
                    )
            );

        $stage['assignment_id']=(int)$stage['assignment_id'];
        $stage['admission_id']=(int)$stage['admission_id'];
        $stage['application_id']=(int)$stage['application_id'];
        $stage['campaign_id']=(int)$stage['campaign_id'];
        $stage['host_etablissement_id']=(int)$stage['host_etablissement_id'];
        $stage['academic_enrollment_id']=(int)$stage['academic_enrollment_id'];
        $stage['promotion_id']=$stage['promotion_id']!==null?(int)$stage['promotion_id']:null;
        $stage['group_id']=$stage['group_id']!==null?(int)$stage['group_id']:null;
        $stage['completion_id']=$stage['completion_id']!==null?(int)$stage['completion_id']:null;

        /* Ces deux champs sont optionnels dans l'UI. */
        $stage['host_city']=null;
        $stage['host_province']=null;

        $stage['effective_status']=$effective;
        $stage['rotations']=$rotations;

        $stage['execution_access']=[
            'can_logbook'=>$canExecution,
            'can_attendance'=>$canExecution,
            'can_evaluation'=>$canExecution || $hasEnded,
            'reason'=>$reason,
            'current_rotation'=>$current,
            'next_rotation'=>$next
        ];
    }
    unset($stage);

    /* =========================================================
       KPI
    ========================================================= */
    $summary=[
        'total'=>count($stages),
        'planned'=>0,
        'active'=>0,
        'validated'=>0
    ];

    foreach($stages as $stage){
        if($stage['effective_status']==='PLANIFIE')
            $summary['planned']++;

        if($stage['effective_status']==='EN_COURS')
            $summary['active']++;

        if($stage['effective_status']==='VALIDE')
            $summary['validated']++;
    }

    jsonResponse(true,'',[
        'student'=>[
            'id'=>$studentId,
            'stagia_code'=>$student['stagia_code']
        ],
        'summary'=>$summary,
        'stages'=>$stages
    ]);

}catch(Throwable $e){
    error_log(
        '[STUDENT MY STAGES] '.
        $e->getMessage().
        ' | '.
        $e->getFile().
        ':'.
        $e->getLine()
    );

    jsonResponse(
        false,
        'Erreur chargement de vos stages : '.$e->getMessage(),
        [],
        500
    );
}

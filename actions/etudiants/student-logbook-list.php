<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);

try{
    $userId=(int)($_SESSION['user_id']??0);
    $assignmentFilter=(int)($_GET['assignment_id']??0);
    $today=date('Y-m-d');

    if(!$userId)
        jsonResponse(false,'Session étudiant invalide.',[],401);

    $s=$pdo->prepare("
        SELECT id,stagia_code
        FROM student_profiles
        WHERE user_id=?
        LIMIT 1
    ");
    $s->execute([$userId]);
    $student=$s->fetch(PDO::FETCH_ASSOC);

    if(!$student)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $studentId=(int)$student['id'];

    /*
     * Rotations appartenant réellement à l'étudiant.
     * Flux canonique :
     * student -> enrollment -> application -> reservation
     * -> admission -> assignment -> rotation.
     */
    $whereAssignment=$assignmentFilter?' AND sa.id=? ':'';
    $params=[$studentId];

    if($assignmentFilter)
        $params[]=$assignmentFilter;

    $s=$pdo->prepare("
        SELECT
            r.id AS rotation_id,
            r.assignment_id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut,
            r.statut AS rotation_statut,

            hu.id AS unit_id,
            hu.code AS unit_code,
            hu.nom AS unit_name,
            hu.type AS unit_type,
            parent.nom AS parent_name,

            sa.host_etablissement_id,

            c.code AS campaign_code,
            c.titre AS campaign_title

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
         AND sa.statut<>'ANNULEE'

        JOIN stage_rotations r
          ON r.assignment_id=sa.id
         AND r.statut<>'ANNULEE'

        JOIN host_units hu
          ON hu.id=r.host_unit_id

        LEFT JOIN host_units parent
          ON parent.id=hu.parent_id

        JOIN stage_campaigns c
          ON c.id=app.campaign_id

        WHERE sp.id=?
          $whereAssignment

        ORDER BY r.date_debut DESC,r.sequence_no,r.id
    ");
    $s->execute($params);
    $rotations=$s->fetchAll(PDO::FETCH_ASSOC);

    $currentRotation=null;
    $nextRotation=null;

    foreach($rotations as $r){
        $isToday=
            $r['date_debut']<=$today &&
            $r['date_fin']>=$today;

        if(
            $currentRotation===null &&
            $isToday &&
            in_array($r['statut'],['ACTIVE','PLANIFIEE'],true)
        ){
            $currentRotation=$r;
        }

        if(
            $nextRotation===null &&
            $r['statut']==='PLANIFIEE' &&
            $r['date_debut']>$today
        ){
            $nextRotation=$r;
        }
    }

    /*
     * Journaux du stagiaire.
     * Si on vient de "Mes stages", on limite à l'affectation sélectionnée.
     */
    $entryWhere=$assignmentFilter?' AND e.assignment_id=? ':'';
    $entryParams=[$studentId];

    if($assignmentFilter)
        $entryParams[]=$assignmentFilter;

    $s=$pdo->prepare("
        SELECT
            e.id,
            e.uuid,
            e.rotation_id,
            e.assignment_id,
            e.date_journal,
            e.resume_activites,
            e.apprentissages,
            e.difficultes,
            e.observation_etudiant,
            e.statut,
            e.commentaire_encadreur,
            e.submitted_at,
            e.validated_at,

            r.sequence_no,
            r.statut AS rotation_statut,

            hu.code AS unit_code,
            hu.nom AS unit_name,
            hu.type AS unit_type,
            parent.nom AS parent_name,

            (
                SELECT COUNT(*)
                FROM stage_logbook_activities a
                WHERE a.logbook_entry_id=e.id
            ) AS activities_count

        FROM stage_logbook_entries e

        JOIN stage_rotations r
          ON r.id=e.rotation_id

        JOIN host_units hu
          ON hu.id=r.host_unit_id

        LEFT JOIN host_units parent
          ON parent.id=hu.parent_id

        WHERE e.student_id=?
          $entryWhere

        ORDER BY e.date_journal DESC,e.id DESC
    ");
    $s->execute($entryParams);
    $entries=$s->fetchAll(PDO::FETCH_ASSOC);

    $activityStmt=$pdo->prepare("
        SELECT
            id,
            type_activite,
            intitule,
            description,
            niveau_implication,
            quantite,
            observation
        FROM stage_logbook_activities
        WHERE logbook_entry_id=?
        ORDER BY id
    ");

    $stats=[
        'total'=>0,
        'brouillons'=>0,
        'soumis'=>0,
        'valides'=>0
    ];

    foreach($entries as &$entry){
        $activityStmt->execute([(int)$entry['id']]);
        $entry['activities']=$activityStmt->fetchAll(PDO::FETCH_ASSOC);

        $entry['id']=(int)$entry['id'];
        $entry['rotation_id']=(int)$entry['rotation_id'];
        $entry['assignment_id']=(int)$entry['assignment_id'];
        $entry['sequence_no']=(int)$entry['sequence_no'];
        $entry['activities_count']=(int)$entry['activities_count'];

        $entry['can_edit']=in_array(
            $entry['statut'],
            ['BROUILLON','REJETE'],
            true
        );

        $stats['total']++;

        if($entry['statut']==='BROUILLON')
            $stats['brouillons']++;

        if($entry['statut']==='SOUMIS')
            $stats['soumis']++;

        if($entry['statut']==='VALIDE')
            $stats['valides']++;
    }
    unset($entry);

    jsonResponse(true,'',[
        'student_id'=>$studentId,
        'today'=>$today,
        'rotations'=>$rotations,
        'current_rotation'=>$currentRotation,
        'next_rotation'=>$nextRotation,
        'can_create'=>$currentRotation!==null,
        'entries'=>$entries,
        'stats'=>$stats
    ]);

}catch(Throwable $e){
    error_log(
        '[STUDENT LOGBOOK LIST] '.$e->getMessage().
        ' | '.$e->getFile().':'.$e->getLine()
    );

    jsonResponse(
        false,
        'Erreur chargement du journal : '.$e->getMessage(),
        [],
        500
    );
}

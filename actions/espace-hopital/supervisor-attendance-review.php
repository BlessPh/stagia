<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/supervisor-access.php';

requirePermission($pdo,'attendance.hosting.review');

$csrf=(string)($_POST['csrf']??'');

if(
    empty($_SESSION['csrf']) ||
    !$csrf ||
    !hash_equals((string)$_SESSION['csrf'],$csrf)
){
    jsonResponse(false,'Jeton CSRF invalide.',[],419);
}

$eid=(int)($_SESSION['etablissement_id']??0);
$userId=(int)($_SESSION['user_id']??0);

$rotationId=(int)($_POST['rotation_id']??0);
$studentId=(int)($_POST['student_id']??0);
$date=trim((string)($_POST['date_presence']??''));
$status=strtoupper(trim((string)($_POST['statut']??'')));
$minutes=max(0,(int)($_POST['minutes_retard']??0));
$observation=trim((string)($_POST['observation']??''));

$allowed=[
    'PRESENT',
    'RETARD',
    'ABSENT',
    'JUSTIFIE',
    'GARDE'
];

if(
    !$rotationId ||
    !$studentId ||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) ||
    !in_array($status,$allowed,true)
){
    jsonResponse(false,'Données de présence invalides.',[],422);
}

if($date>date('Y-m-d')){
    jsonResponse(false,'Impossible de corriger une présence future.',[],422);
}

if($status==='RETARD' && $minutes<1){
    jsonResponse(
        false,
        'Indiquez le nombre de minutes de retard.',
        [],
        422
    );
}

if($status!=='RETARD'){
    $minutes=0;
}

try{
    $rotation=requireSupervisorRotation(
        $pdo,
        $rotationId,
        $eid,
        $userId
    );

    if(
        $date<$rotation['date_debut'] ||
        $date>$rotation['date_fin']
    ){
        throw new RuntimeException(
            'La date doit appartenir à la période de rotation.'
        );
    }

    /*
     * Vérifier que l'étudiant appartient au même plan de rotation.
     * Le rotation_id affiché à l'encadreur peut représenter l'une
     * des rotations individuelles du groupe.
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
        $studentSql="
            SELECT
                r.id rotation_id,
                r.assignment_id,
                r.host_etablissement_id
            FROM stage_rotations r
            JOIN stage_assignments sa ON sa.id=r.assignment_id
            JOIN stage_admissions ad ON ad.id=sa.admission_id
            JOIN stage_placements pl ON pl.id=ad.placement_id
            WHERE r.group_rotation_plan_id=?
              AND r.host_etablissement_id=?
              AND pl.student_id=?
            LIMIT 1
        ";
        $studentParams=[$planId,$eid,$studentId];
    }else{
        $studentSql="
            SELECT
                r.id rotation_id,
                r.assignment_id,
                r.host_etablissement_id
            FROM stage_rotations r
            JOIN stage_assignments sa ON sa.id=r.assignment_id
            JOIN stage_admissions ad ON ad.id=sa.admission_id
            JOIN stage_placements pl ON pl.id=ad.placement_id
            WHERE r.id=?
              AND r.host_etablissement_id=?
              AND pl.student_id=?
            LIMIT 1
        ";
        $studentParams=[$rotationId,$eid,$studentId];
    }

    $s=$pdo->prepare($studentSql);
    $s->execute($studentParams);
    $studentRotation=$s->fetch(PDO::FETCH_ASSOC);

    if(!$studentRotation){
        throw new RuntimeException(
            "Ce stagiaire n'appartient pas à cette rotation."
        );
    }

    $realRotationId=(int)$studentRotation['rotation_id'];
    $assignmentId=(int)$studentRotation['assignment_id'];

    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT
            id,statut,minutes_retard,
            heure_arrivee,heure_depart
        FROM stage_attendances
        WHERE rotation_id=?
          AND student_id=?
          AND date_presence=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([
        $realRotationId,
        $studentId,
        $date
    ]);
    $existing=$s->fetch(PDO::FETCH_ASSOC);

    if(!$existing){
        /*
         * Sans pointage étudiant, l'encadreur peut uniquement
         * constater une absence ou une absence justifiée.
         * On évite de fabriquer une présence sans heure d'arrivée.
         */
        if(!in_array($status,['ABSENT','JUSTIFIE'],true)){
            throw new RuntimeException(
                "Sans pointage d'arrivée, vous pouvez uniquement enregistrer ABSENT ou JUSTIFIÉ."
            );
        }

        $uuid=sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0,0xffff),random_int(0,0xffff),
            random_int(0,0xffff),
            random_int(0,0x0fff)|0x4000,
            random_int(0,0x3fff)|0x8000,
            random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff)
        );

        $s=$pdo->prepare("
            INSERT INTO stage_attendances(
                uuid,
                rotation_id,
                assignment_id,
                student_id,
                host_etablissement_id,
                date_presence,
                heure_arrivee,
                heure_depart,
                statut,
                minutes_retard,
                source,
                recorded_by,
                validated_at
            ) VALUES(
                ?,?,?,?,?,?,
                NULL,NULL,?,0,
                'MANUEL',?,NOW()
            )
        ");
        $s->execute([
            $uuid,
            $realRotationId,
            $assignmentId,
            $studentId,
            $eid,
            $date,
            $status,
            $userId
        ]);

        $attendanceId=(int)$pdo->lastInsertId();
        $previousStatus=null;
        $previousMinutes=0;

    }else{
        $attendanceId=(int)$existing['id'];
        $previousStatus=$existing['statut'];
        $previousMinutes=(int)$existing['minutes_retard'];

        $s=$pdo->prepare("
            UPDATE stage_attendances
            SET
                statut=?,
                minutes_retard=?,
                validated_at=NOW()
            WHERE id=?
        ");
        $s->execute([
            $status,
            $minutes,
            $attendanceId
        ]);
    }

    $s=$pdo->prepare("
        INSERT INTO stage_attendance_review_history(
            attendance_id,
            previous_status,
            new_status,
            previous_minutes_retard,
            new_minutes_retard,
            observation,
            reviewer_user_id,
            reviewed_at
        ) VALUES(?,?,?,?,?,?,?,NOW())
    ");
    $s->execute([
        $attendanceId,
        $previousStatus,
        $status,
        $previousMinutes,
        $minutes,
        $observation?:null,
        $userId
    ]);

    $pdo->commit();

    jsonResponse(
        true,
        'Présence enregistrée / validée.',
        ['attendance_id'=>$attendanceId]
    );

}catch(Throwable $e){
    if($pdo->inTransaction()){
        $pdo->rollBack();
    }

    jsonResponse(false,$e->getMessage(),[],422);
}

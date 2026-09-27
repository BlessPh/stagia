<?php
require_once __DIR__.'/stage-execution.php';

function stagiaUuidV4():string
{
    $data=random_bytes(16);
    $data[6]=chr((ord($data[6])&0x0f)|0x40);
    $data[8]=chr((ord($data[8])&0x3f)|0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($data),4)
    );
}

function studentProfileForAttendance(PDO $pdo,int $userId):array
{
    $s=$pdo->prepare("
        SELECT id,stagia_code
        FROM student_profiles
        WHERE user_id=?
          AND statut='ACTIF'
        LIMIT 1
    ");
    $s->execute([$userId]);

    $row=$s->fetch(PDO::FETCH_ASSOC);

    if(!$row){
        throw new RuntimeException('Profil stagiaire introuvable.');
    }

    return $row;
}

function studentAttendanceAssignmentToday(
    PDO $pdo,
    int $studentId,
    string $today
):?int{
    /*
     * On ne prend jamais rotation_id depuis le navigateur.
     * La rotation courante est résolue depuis le dossier réel
     * du stagiaire et la date du serveur.
     */
    $s=$pdo->prepare("
        SELECT DISTINCT sa.id
        FROM student_enrollments se
        JOIN student_academic_enrollments ae
          ON ae.enrollment_id=se.id
        JOIN stage_applications app
          ON app.academic_enrollment_id=ae.id
        JOIN stage_reservations sr
          ON sr.application_id=app.id
         AND sr.statut='CONFIRMEE'
        JOIN stage_admissions a
          ON a.reservation_id=sr.id
         AND a.statut IN('ADMIS','EN_COURS')
        JOIN stage_assignments sa
          ON sa.admission_id=a.id
         AND sa.statut<>'ANNULEE'
        JOIN stage_rotations r
          ON r.assignment_id=sa.id
         AND r.statut<>'ANNULEE'
        WHERE se.student_id=?
          AND ? BETWEEN r.date_debut AND r.date_fin
        ORDER BY sa.id DESC
        LIMIT 1
    ");
    $s->execute([$studentId,$today]);

    $id=$s->fetchColumn();

    return $id ? (int)$id : null;
}

function studentNextRotationForAttendance(
    PDO $pdo,
    int $studentId,
    string $today
):?array{
    $s=$pdo->prepare("
        SELECT
            r.id rotation_id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            u.nom unit_name,
            u.code unit_code
        FROM student_enrollments se
        JOIN student_academic_enrollments ae
          ON ae.enrollment_id=se.id
        JOIN stage_applications app
          ON app.academic_enrollment_id=ae.id
        JOIN stage_reservations sr
          ON sr.application_id=app.id
         AND sr.statut='CONFIRMEE'
        JOIN stage_admissions a
          ON a.reservation_id=sr.id
         AND a.statut IN('ADMIS','EN_COURS')
        JOIN stage_assignments sa
          ON sa.admission_id=a.id
         AND sa.statut<>'ANNULEE'
        JOIN stage_rotations r
          ON r.assignment_id=sa.id
         AND r.statut='PLANIFIEE'
        JOIN host_units u
          ON u.id=r.host_unit_id
        WHERE se.student_id=?
          AND r.date_debut>?
        ORDER BY r.date_debut,r.sequence_no
        LIMIT 1
    ");
    $s->execute([$studentId,$today]);

    $row=$s->fetch(PDO::FETCH_ASSOC);

    return $row?:null;
}

function studentAttendancePunchContext(
    PDO $pdo,
    int $userId,
    ?string $date=null
):array{
    $today=stageExecutionDate($date);
    $profile=studentProfileForAttendance($pdo,$userId);
    $studentId=(int)$profile['id'];

    $assignmentId=studentAttendanceAssignmentToday(
        $pdo,
        $studentId,
        $today
    );

    if(!$assignmentId){
        $next=studentNextRotationForAttendance(
            $pdo,
            $studentId,
            $today
        );

        return [
            'date'=>$today,
            'student_id'=>$studentId,
            'stagia_code'=>$profile['stagia_code'],
            'assignment_id'=>null,
            'rotation'=>null,
            'attendance'=>null,
            'can_punch'=>false,
            'next_action'=>null,
            'next_rotation'=>$next,
            'reason'=>$next
                ?'La pointeuse sera disponible au début de votre prochaine rotation.'
                :'Aucune rotation active aujourd’hui.'
        ];
    }

    $rotation=stageExecutionCurrentRotation(
        $pdo,
        $assignmentId,
        $today
    );

    if(!$rotation){
        return [
            'date'=>$today,
            'student_id'=>$studentId,
            'stagia_code'=>$profile['stagia_code'],
            'assignment_id'=>$assignmentId,
            'rotation'=>null,
            'attendance'=>null,
            'can_punch'=>false,
            'next_action'=>null,
            'next_rotation'=>null,
            'reason'=>'Aucune rotation active aujourd’hui.'
        ];
    }

    $s=$pdo->prepare("
        SELECT
            id,uuid,date_presence,
            heure_arrivee,heure_depart,
            statut,minutes_retard,source,
            validated_at
        FROM stage_attendances
        WHERE rotation_id=?
          AND student_id=?
          AND date_presence=?
        LIMIT 1
    ");
    $s->execute([
        (int)$rotation['rotation_id'],
        $studentId,
        $today
    ]);

    $attendance=$s->fetch(PDO::FETCH_ASSOC)?:null;

    $nextAction='ARRIVEE';

    if($attendance && !empty($attendance['heure_arrivee'])){
        $nextAction=empty($attendance['heure_depart'])
            ?'DEPART'
            :'TERMINE';
    }

    return [
        'date'=>$today,
        'student_id'=>$studentId,
        'stagia_code'=>$profile['stagia_code'],
        'assignment_id'=>$assignmentId,
        'rotation'=>$rotation,
        'attendance'=>$attendance,
        'can_punch'=>$nextAction!=='TERMINE',
        'next_action'=>$nextAction,
        'next_rotation'=>null,
        'reason'=>$nextAction==='TERMINE'
            ?'Votre pointage du jour est terminé.'
            :null
    ];
}

/** Enregistre l'arrivee ou le depart avec les memes verrous pour le Web et l'API. */
function studentAttendancePunch(PDO $pdo,int $userId,string $action):array
{
    $action=strtoupper(trim($action));
    if(!in_array($action,['ARRIVEE','DEPART'],true)){
        throw new RuntimeException('Action de pointage invalide.');
    }

    $context=studentAttendancePunchContext($pdo,$userId);
    if(!$context['rotation']||!$context['assignment_id']){
        throw new RuntimeException($context['reason']?:'Aucune rotation active.');
    }

    $rotation=$context['rotation'];
    $studentId=(int)$context['student_id'];
    $assignmentId=(int)$context['assignment_id'];
    $rotationId=(int)$rotation['rotation_id'];

    $s=$pdo->prepare('SELECT host_etablissement_id FROM stage_rotations WHERE id=? LIMIT 1');
    $s->execute([$rotationId]);
    $hostId=(int)$s->fetchColumn();
    if(!$hostId)throw new RuntimeException("Etablissement d'accueil introuvable.");

    $today=stageExecutionDate();
    $now=date('H:i:s');
    $ownTransaction=!$pdo->inTransaction();
    if($ownTransaction)$pdo->beginTransaction();

    try{
        $s=$pdo->prepare("SELECT id,heure_arrivee,heure_depart FROM stage_attendances WHERE rotation_id=? AND student_id=? AND date_presence=? LIMIT 1 FOR UPDATE");
        $s->execute([$rotationId,$studentId,$today]);
        $existing=$s->fetch(PDO::FETCH_ASSOC);

        if($action==='ARRIVEE'){
            if($existing&&!empty($existing['heure_arrivee']))throw new RuntimeException("Votre arrivee est deja pointee aujourd'hui.");
            if($existing){
                $s=$pdo->prepare("UPDATE stage_attendances SET heure_arrivee=?,source='MOBILE',recorded_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=?");
                $s->execute([$now,$userId,(int)$existing['id']]);
            }else{
                $s=$pdo->prepare("INSERT INTO stage_attendances(uuid,rotation_id,assignment_id,student_id,host_etablissement_id,date_presence,heure_arrivee,heure_depart,statut,minutes_retard,source,recorded_by) VALUES(?,?,?,?,?,?,?,NULL,'PRESENT',0,'MOBILE',?)");
                $s->execute([stagiaUuidV4(),$rotationId,$assignmentId,$studentId,$hostId,$today,$now,$userId]);
            }
            $message='Arrivee pointee a '.substr($now,0,5).'.';
        }else{
            if(!$existing||empty($existing['heure_arrivee']))throw new RuntimeException("Vous devez d'abord pointer votre arrivee.");
            if(!empty($existing['heure_depart']))throw new RuntimeException("Votre depart est deja pointe aujourd'hui.");
            $s=$pdo->prepare('UPDATE stage_attendances SET heure_depart=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $s->execute([$now,(int)$existing['id']]);
            $message='Depart pointe a '.substr($now,0,5).'.';
        }

        if($ownTransaction)$pdo->commit();
        return ['message'=>$message,'punch'=>studentAttendancePunchContext($pdo,$userId)];
    }catch(Throwable $e){
        if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

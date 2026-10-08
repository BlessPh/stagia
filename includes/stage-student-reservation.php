<?php

require_once __DIR__.'/stage-d4-reservation.php';

function findStudentReservationForUpdate(PDO $pdo,int $studentId,string $uuid):array{
    $stmt=$pdo->prepare("
        SELECT r.id,r.uuid,r.statut,r.expires_at,r.confirmed_at,r.cancelled_at,
               a.id application_id,a.statut application_status,a.campaign_id,
               p.frais_requis,
               st.code stage_type_code,
               pl.id placement_id,pl.statut placement_status,
               ad.id admission_id,ad.statut admission_status
        FROM stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN stage_types st ON st.id=c.stage_type_id
        LEFT JOIN stage_campaign_participations p ON p.id=r.participation_id
        LEFT JOIN stage_placements pl ON pl.reservation_id=r.id AND pl.statut<>'ANNULE'
        LEFT JOIN stage_admissions ad ON ad.reservation_id=r.id AND ad.statut<>'ANNULE'
        WHERE r.uuid=? AND se.student_id=?
        LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([$uuid,$studentId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new OutOfBoundsException('Réservation introuvable.');
    return $row;
}

/** Annule uniquement le choix temporaire, avant toute décision universitaire. */
function cancelStudentReservation(PDO $pdo,int $studentId,int $actorUserId,string $uuid):array{
    $pdo->beginTransaction();
    try{
        $row=findStudentReservationForUpdate($pdo,$studentId,$uuid);
        if($row['statut']==='ANNULEE'||$row['application_status']==='ANNULEE'){
            $pdo->commit();
            return ['cancelled'=>true,'idempotent'=>true,'reservation_uuid'=>$uuid,'reservation_status'=>'ANNULEE'];
        }

        if($row['statut']==='RESERVEE_TEMPORAIREMENT'&&!empty($row['expires_at'])&&strtotime((string)$row['expires_at'])<=time()){
            $pdo->prepare("UPDATE stage_reservations SET statut='EXPIREE' WHERE id=? AND statut='RESERVEE_TEMPORAIREMENT'")
                ->execute([(int)$row['id']]);
            stageNotifyStudentReservation($pdo,(int)$row['id'],'stage.reservation.expired',[
                'reservation_status'=>'EXPIREE','workflow_status'=>'RESERVATION_EXPIREE'
            ]);
            $pdo->commit();
            throw new DomainException('Cette réservation temporaire a déjà expiré. Vous pouvez choisir un autre hôpital.');
        }

        if($row['statut']!=='RESERVEE_TEMPORAIREMENT'||!in_array($row['application_status'],['SOUMISE','EN_ETUDE'],true)){
            throw new DomainException("La réservation a déjà été traitée par l'université et ne peut plus être annulée par l'étudiant.");
        }
        if($row['placement_id']||$row['admission_id']){
            throw new DomainException("La réservation a déjà été traitée et ne peut plus être annulée par l'étudiant.");
        }

        $pdo->prepare("UPDATE stage_reservations SET statut='ANNULEE',expires_at=NULL,confirmed_at=NULL,cancelled_at=COALESCE(cancelled_at,NOW()) WHERE id=?")
            ->execute([(int)$row['id']]);
        $pdo->prepare("UPDATE stage_applications SET statut='ANNULEE' WHERE id=? AND statut IN('SOUMISE','EN_ETUDE')")
            ->execute([(int)$row['application_id']]);
        d4ReservationHistory($pdo,(int)$row['id'],'STUDENT_CANCELLED',$row['statut'],'ANNULEE',[
            'source'=>'MOBILE','application_status_before'=>$row['application_status']
        ],$actorUserId?:null);
        $pdo->commit();
        return ['cancelled'=>true,'idempotent'=>false,'reservation_uuid'=>$uuid,'reservation_status'=>'ANNULEE'];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

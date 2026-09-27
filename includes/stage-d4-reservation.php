<?php
/** STAGIA-RDC - C2 : helpers réservation D4. */
require_once __DIR__.'/stage-campaign.php';

function d4UuidV4():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

function d4StudentProfile(PDO $pdo,int $userId):array{
    $s=$pdo->prepare("SELECT id,uuid,stagia_code,nom,postnom,prenom FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");
    $s->execute([$userId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Profil étudiant actif introuvable.');
    return $row;
}

function d4ExpireReservations(PDO $pdo,int $participationId):int{
    $s=$pdo->prepare("
        UPDATE stage_reservations
        SET statut='EXPIREE'
        WHERE participation_id=?
          AND statut='RESERVEE_TEMPORAIREMENT'
          AND expires_at IS NOT NULL
          AND expires_at<=NOW()
    ");
    $s->execute([$participationId]);
    return $s->rowCount();
}

function d4ActiveReservationCount(PDO $pdo,int $participationId):int{
    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM stage_reservations
        WHERE participation_id=?
          AND (
            statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
            OR (statut='RESERVEE_TEMPORAIREMENT' AND (expires_at IS NULL OR expires_at>NOW()))
          )
    ");
    $s->execute([$participationId]);
    return (int)$s->fetchColumn();
}

function d4ReservationHistory(PDO $pdo,int $reservationId,string $event,?string $previous,?string $new,array $details,?int $actorId):void{
    $pdo->prepare("
        INSERT INTO stage_reservation_history(
            reservation_id,event_code,previous_status,new_status,details,actor_user_id,created_at
        ) VALUES(?,?,?,?,?,?,NOW())
    ")->execute([
        $reservationId,$event,$previous,$new,
        json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$actorId
    ]);
}

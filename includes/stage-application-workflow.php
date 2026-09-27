<?php

require_once __DIR__.'/stage-d4-reservation.php';

/**
 * Crée le choix D4 canonique utilisé par le Web et l'API mobile.
 * La candidature reste SOUMISE et la réservation temporaire jusqu'à la
 * décision explicite de l'université.
 */
function submitD4Application(
    PDO $pdo,
    int $studentId,
    int $actorUserId,
    int $campaignId,
    int $academicEnrollmentId,
    int $participationId,
    string $motivation=''
):array{
    if($studentId<1||$campaignId<1||$academicEnrollmentId<1||$participationId<1){
        throw new InvalidArgumentException('Sélection D4 invalide.');
    }
    if(mb_strlen($motivation)>1000){
        throw new InvalidArgumentException('La motivation ne peut pas dépasser 1000 caractères.');
    }

    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("
            SELECT ae.id,ae.promotion_id,ae.annee_academique_id,se.etablissement_id
            FROM student_academic_enrollments ae
            INNER JOIN student_enrollments se ON se.id=ae.enrollment_id
            WHERE ae.id=? AND se.student_id=? AND se.statut='ACTIF' AND ae.statut='EN_COURS'
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$academicEnrollmentId,$studentId]);
        $enrollment=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$enrollment)throw new RuntimeException('Inscription académique active introuvable.');

        $stmt=$pdo->prepare("
            SELECT c.id,c.stage_type_id,c.code,c.titre,c.date_debut,c.date_fin
            FROM stage_campaigns c
            INNER JOIN stage_types st ON st.id=c.stage_type_id AND st.code='MEDICAL_D4'
            INNER JOIN stage_campaign_promotions cp ON cp.campaign_id=c.id AND cp.promotion_id=?
            WHERE c.id=? AND c.owner_etablissement_id=? AND c.annee_academique_id=?
              AND c.type_campagne='UNIVERSITAIRE' AND c.statut='OUVERTE'
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([
            $enrollment['promotion_id'],$campaignId,$enrollment['etablissement_id'],$enrollment['annee_academique_id']
        ]);
        $campaign=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$campaign)throw new RuntimeException("Vous n'êtes pas éligible à cette campagne D4 ou elle n'est plus ouverte.");

        $stmt=$pdo->prepare("
            SELECT p.id,p.host_etablissement_id,p.capacite_acceptee,p.frais_requis,
                   p.montant_frais,p.devise,h.code host_code,h.nom host_name
            FROM stage_campaign_participations p
            INNER JOIN etablissements h ON h.id=p.host_etablissement_id
                AND h.type_etablissement='HOPITAL' AND h.statut IN('VALIDE','ACTIF')
            INNER JOIN stage_campaigns hc ON hc.id=p.host_campaign_id AND hc.type_campagne='ACCUEIL'
                AND hc.statut NOT IN('ANNULEE','TERMINEE')
            INNER JOIN stage_capacity_pools cp ON cp.host_campaign_id=hc.id
            WHERE p.id=? AND p.university_campaign_id=? AND p.statut='ACCEPTEE'
              AND p.capacite_acceptee IS NOT NULL AND p.capacite_acceptee>0
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$participationId,$campaignId]);
        $participation=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$participation)throw new RuntimeException("Cet hôpital ne possède aucune offre finalisée pour cette campagne.");

        d4ExpireReservations($pdo,$participationId);

        $stmt=$pdo->prepare("
            SELECT r.id,r.uuid,r.participation_id,r.statut,r.expires_at,
                   a.id application_id,a.uuid application_uuid,a.statut application_status,e.nom host_name
            FROM stage_reservations r
            INNER JOIN stage_applications a ON a.id=r.application_id
            INNER JOIN etablissements e ON e.id=a.host_etablissement_id
            WHERE a.campaign_id=? AND a.academic_enrollment_id=?
              AND (r.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                   OR (r.statut='RESERVEE_TEMPORAIREMENT' AND r.expires_at>NOW()))
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$campaignId,$academicEnrollmentId]);
        $active=$stmt->fetch(PDO::FETCH_ASSOC);
        if($active){
            if((int)$active['participation_id']!==$participationId){
                throw new RuntimeException("Vous avez déjà une réservation active pour cette campagne auprès de « {$active['host_name']} ».");
            }
            $pdo->commit();
            return [
                'created'=>false,
                'application_id'=>(int)$active['application_id'],
                'application_uuid'=>$active['application_uuid'],
                'application_status'=>$active['application_status'],
                'reservation_id'=>(int)$active['id'],
                'reservation_uuid'=>$active['uuid'],
                'reservation_status'=>$active['statut'],
                'expires_at'=>$active['expires_at'],
                'reservation_duration_minutes'=>(int)stagePolicyValue($pdo,(int)$campaign['stage_type_id'],'reservation_hold_minutes',30),
                'hospital'=>['code'=>$participation['host_code'],'name'=>$participation['host_name']],
                'fees'=>[
                    'required'=>(bool)$participation['frais_requis'],
                    'amount'=>$participation['montant_frais']!==null?(float)$participation['montant_frais']:null,
                    'currency'=>$participation['devise']
                ]
            ];
        }

        $stmt=$pdo->prepare("
            SELECT 1
            FROM stage_applications a
            LEFT JOIN stage_reservations r ON r.application_id=a.id
            LEFT JOIN stage_placements p ON p.reservation_id=r.id AND p.statut='CONFIRME'
            LEFT JOIN stage_admissions ad ON ad.reservation_id=r.id AND ad.statut<>'ANNULE'
            WHERE a.campaign_id=? AND a.academic_enrollment_id=? AND (p.id IS NOT NULL OR ad.id IS NOT NULL)
            LIMIT 1
        ");
        $stmt->execute([$campaignId,$academicEnrollmentId]);
        if($stmt->fetchColumn())throw new RuntimeException('Vous êtes déjà engagé dans cette campagne.');

        $reserved=d4ActiveReservationCount($pdo,$participationId);
        $capacity=(int)$participation['capacite_acceptee'];
        if($reserved>=$capacity)throw new RuntimeException("Les {$capacity} place(s) retenues par votre université sont actuellement occupées.");

        $stmt=$pdo->prepare("
            SELECT id,uuid FROM stage_applications
            WHERE campaign_id=? AND academic_enrollment_id=? AND host_etablissement_id=?
            LIMIT 1 FOR UPDATE
        ");
        $stmt->execute([$campaignId,$academicEnrollmentId,$participation['host_etablissement_id']]);
        $application=$stmt->fetch(PDO::FETCH_ASSOC);
        if($application){
            $applicationId=(int)$application['id'];
            $applicationUuid=$application['uuid'];
            $pdo->prepare("
                UPDATE stage_applications
                SET participation_id=?,statut='SOUMISE',motivation=?,motif_refus=NULL,
                    submitted_at=NOW(),responded_at=NULL
                WHERE id=?
            ")->execute([$participationId,$motivation!==''?$motivation:null,$applicationId]);
        }else{
            $applicationUuid=d4UuidV4();
            $pdo->prepare("
                INSERT INTO stage_applications(
                    uuid,campaign_id,academic_enrollment_id,host_etablissement_id,
                    participation_id,statut,motivation,submitted_at
                ) VALUES(?,?,?,?,?,'SOUMISE',?,NOW())
            ")->execute([
                $applicationUuid,$campaignId,$academicEnrollmentId,$participation['host_etablissement_id'],
                $participationId,$motivation!==''?$motivation:null
            ]);
            $applicationId=(int)$pdo->lastInsertId();
        }

        $holdMinutes=max(1,(int)stagePolicyValue($pdo,(int)$campaign['stage_type_id'],'reservation_hold_minutes',30));
        $expiresAt=date('Y-m-d H:i:s',time()+$holdMinutes*60);
        $stmt=$pdo->prepare('SELECT id,uuid,statut FROM stage_reservations WHERE application_id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$applicationId]);
        $oldReservation=$stmt->fetch(PDO::FETCH_ASSOC);
        if($oldReservation){
            $reservationId=(int)$oldReservation['id'];
            $reservationUuid=$oldReservation['uuid'];
            $previous=$oldReservation['statut'];
            $pdo->prepare("
                UPDATE stage_reservations
                SET participation_id=?,statut='RESERVEE_TEMPORAIREMENT',expires_at=?,
                    confirmed_at=NULL,cancelled_at=NULL
                WHERE id=?
            ")->execute([$participationId,$expiresAt,$reservationId]);
        }else{
            $reservationUuid=d4UuidV4();
            $previous=null;
            $pdo->prepare("
                INSERT INTO stage_reservations(uuid,application_id,participation_id,statut,expires_at)
                VALUES(?,?,?,'RESERVEE_TEMPORAIREMENT',?)
            ")->execute([$reservationUuid,$applicationId,$participationId,$expiresAt]);
            $reservationId=(int)$pdo->lastInsertId();
        }

        d4ReservationHistory($pdo,$reservationId,'APPLICATION_SUBMITTED',$previous,'RESERVEE_TEMPORAIREMENT',[
            'campaign_id'=>$campaignId,
            'participation_id'=>$participationId,
            'capacity_accepted'=>$capacity,
            'reserved_before'=>$reserved,
            'expires_at'=>$expiresAt
        ],$actorUserId?:null);

        $pdo->commit();
        return [
            'created'=>true,
            'application_id'=>$applicationId,
            'application_uuid'=>$applicationUuid,
            'application_status'=>'SOUMISE',
            'reservation_id'=>$reservationId,
            'reservation_uuid'=>$reservationUuid,
            'reservation_status'=>'RESERVEE_TEMPORAIREMENT',
            'expires_at'=>$expiresAt,
            'reservation_duration_minutes'=>$holdMinutes,
            'hospital'=>['code'=>$participation['host_code'],'name'=>$participation['host_name']],
            'fees'=>[
                'required'=>(bool)$participation['frais_requis'],
                'amount'=>$participation['montant_frais']!==null?(float)$participation['montant_frais']:null,
                'currency'=>$participation['devise']
            ],
            'places_remaining'=>max(0,$capacity-$reserved-1)
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

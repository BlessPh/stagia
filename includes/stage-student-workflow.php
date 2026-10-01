<?php

/** Expire uniquement les réservations temporaires du stagiaire concerné. */
function expireStudentTemporaryReservations(PDO $pdo,int $studentId):int{
    $stmt=$pdo->prepare("
        UPDATE stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        SET r.statut='EXPIREE'
        WHERE se.student_id=?
          AND r.statut='RESERVEE_TEMPORAIREMENT'
          AND r.expires_at IS NOT NULL
          AND r.expires_at<=NOW()
    ");
    $stmt->execute([$studentId]);
    return $stmt->rowCount();
}

/** État mobile commun aux listes de candidatures, réservations et admissions. */
function studentStageWorkflowStatus(array $row):string{
    $application=(string)($row['application_status']??$row['statut_application']??'');
    $reservation=(string)($row['reservation_status']??$row['statut_reservation']??'');
    $placement=(string)($row['placement_status']??'');
    $admission=(string)($row['admission_status']??'');
    $assignment=(string)($row['assignment_status']??'');
    $completion=(string)($row['completion_status']??'');

    if($completion==='VALIDE')return 'STAGE_VALIDE';
    if($assignment==='TERMINEE')return 'STAGE_TERMINE';
    if($assignment==='ACTIVE')return 'STAGE_EN_COURS';
    if($assignment==='PLANIFIEE')return 'STAGE_PLANIFIE';
    if($admission==='EN_COURS')return 'STAGE_EN_COURS';
    if($admission==='ADMIS')return 'AFFECTATION_EN_ATTENTE';
    if($admission==='ATTENDU')return 'ADMISSION_HOSPITALIERE_EN_ATTENTE';
    if($placement==='CONFIRME')return 'ADMISSION_HOSPITALIERE_EN_ATTENTE';
    if($application==='REFUSEE')return 'CANDIDATURE_REFUSEE';
    if($application==='ANNULEE'||$reservation==='ANNULEE')return 'ANNULEE';
    if($reservation==='EXPIREE')return 'RESERVATION_EXPIREE';
    if($reservation==='EN_ATTENTE_PAIEMENT')return 'EN_ATTENTE_PAIEMENT';
    if($reservation==='CONFIRMEE')return 'PLACEMENT_UNIVERSITAIRE_EN_ATTENTE';
    if($reservation==='RESERVEE_TEMPORAIREMENT')return 'DECISION_UNIVERSITAIRE_EN_ATTENTE';
    return $application!==''?$application:($reservation!==''?$reservation:'INCONNU');
}

/** Libellé directement affichable par le client mobile pour l'état du parcours. */
function studentStageWorkflowMessage(string $status):string{
    $messages=[
        'DECISION_UNIVERSITAIRE_EN_ATTENTE'=>"Réservation envoyée, en attente de l'approbation de l'université.",
        'EN_ATTENTE_PAIEMENT'=>'Réservation approuvée, en attente du paiement.',
        'PLACEMENT_UNIVERSITAIRE_EN_ATTENTE'=>"Réservation approuvée, en attente de l'affectation par l'université.",
        'ADMISSION_HOSPITALIERE_EN_ATTENTE'=>"Affectation confirmée, en attente de l'admission par l'hôpital.",
        'AFFECTATION_EN_ATTENTE'=>"Admission enregistrée, en attente de l'affectation à un service.",
        'STAGE_PLANIFIE'=>'Stage planifié.',
        'STAGE_EN_COURS'=>'Stage en cours.',
        'STAGE_TERMINE'=>'Stage terminé.',
        'STAGE_VALIDE'=>'Stage validé.',
        'CANDIDATURE_REFUSEE'=>"Réservation refusée par l'université.",
        'ANNULEE'=>'Réservation annulée.',
        'RESERVATION_EXPIREE'=>'Réservation temporaire expirée.'
    ];
    return $messages[$status]??'Statut du stage mis à jour.';
}

function studentStageTypeMode(string $stageTypeCode):array{
    $isD4=$stageTypeCode==='MEDICAL_D4';
    return [
        'is_d4'=>$isD4,
        'self_reservation_allowed'=>true,
        'reservation_mode'=>$isD4
            ?'STUDENT_D4_CHOICE'
            :'STUDENT_CHOICE_UNIVERSITY_CONFIRMATION'
    ];
}

/** Services et unités actifs qu'un hôpital peut présenter aux étudiants. */
function studentHospitalAvailableServices(PDO $pdo,int $hospitalId):array{
    static $cache=[];
    $cacheKey=spl_object_id($pdo).':'.$hospitalId;
    if(array_key_exists($cacheKey,$cache))return $cache[$cacheKey];

    $stmt=$pdo->prepare("
        SELECT unit.id,unit.code,unit.nom,unit.type,unit.description,unit.capacite,
               parent.code parent_code,parent.nom parent_name
        FROM host_units unit
        LEFT JOIN host_units parent
          ON parent.id=unit.parent_id
         AND parent.host_etablissement_id=unit.host_etablissement_id
        WHERE unit.host_etablissement_id=?
          AND unit.actif=1
          AND UPPER(TRIM(unit.type)) IN('SERVICE','UNITE','UNITÉ')
        ORDER BY COALESCE(parent.nom,''),unit.nom,unit.id
    ");
    $stmt->execute([$hospitalId]);

    $services=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $service){
        $services[]=[
            'id'=>(int)$service['id'],
            'code'=>$service['code'],
            'name'=>$service['nom'],
            'type'=>$service['type'],
            'description'=>$service['description'],
            'capacity'=>$service['capacite']!==null?(int)$service['capacite']:null,
            'parent'=>$service['parent_code']!==null||$service['parent_name']!==null
                ?['code'=>$service['parent_code'],'name'=>$service['parent_name']]
                :null
        ];
    }
    $cache[$cacheKey]=$services;
    return $services;
}

/**
 * Retourne les hôpitaux réellement retenus pour une campagne universitaire.
 *
 * Les anciens flux finalisent la capacité dans capacite_acceptee, tandis que
 * le flux générique d'accueil utilise capacite_allouee. Une simple proposition
 * n'est jamais exposée aux étudiants tant qu'elle n'a pas été retenue/allouée.
 */
function studentCampaignAcceptedHospitals(PDO $pdo,int $campaignId):array{
    $stmt=$pdo->prepare("
        SELECT
            part.id AS participation_id,
            part.host_etablissement_id,
            COALESCE(
                NULLIF(part.capacite_acceptee,0),
                NULLIF(part.capacite_allouee,0)
            ) AS capacity,
            part.frais_requis,
            part.montant_frais,
            part.devise,
            part.conditions,
            h.code AS hospital_code,
            h.nom AS hospital_name,
            h.telephone AS hospital_phone,
            h.ville,
            h.province,
            (
                SELECT COUNT(*)
                FROM stage_reservations reservation
                WHERE reservation.participation_id=part.id
                  AND (
                        reservation.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                        OR (
                            reservation.statut='RESERVEE_TEMPORAIREMENT'
                            AND (
                                reservation.expires_at IS NULL
                                OR reservation.expires_at>NOW()
                            )
                        )
                  )
            ) AS used_places
        FROM stage_campaign_participations part
        INNER JOIN stage_campaigns host_campaign
            ON host_campaign.id=part.host_campaign_id
           AND host_campaign.type_campagne='ACCUEIL'
           AND host_campaign.statut NOT IN('ANNULEE','TERMINEE')
        INNER JOIN stage_capacity_pools capacity_pool
            ON capacity_pool.host_campaign_id=host_campaign.id
        INNER JOIN etablissements h
            ON h.id=part.host_etablissement_id
           AND h.type_etablissement='HOPITAL'
           AND h.statut IN('VALIDE','ACTIF')
        WHERE part.university_campaign_id=?
          AND part.statut='ACCEPTEE'
          AND COALESCE(
                NULLIF(part.capacite_acceptee,0),
                NULLIF(part.capacite_allouee,0),
                0
              )>0
        ORDER BY h.nom,part.id
    ");
    $stmt->execute([$campaignId]);

    $hospitals=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $participation){
        $capacity=(int)$participation['capacity'];
        $used=(int)$participation['used_places'];
        $available=max(0,$capacity-$used);

        $hospitals[]=[
            'participation_id'=>(int)$participation['participation_id'],
            'hospital'=>[
                'code'=>$participation['hospital_code'],
                'name'=>$participation['hospital_name'],
                'phone'=>$participation['hospital_phone'],
                'city'=>$participation['ville'],
                'province'=>$participation['province'],
                'services'=>studentHospitalAvailableServices(
                    $pdo,
                    (int)$participation['host_etablissement_id']
                )
            ],
            'capacity'=>$capacity,
            'used_places'=>$used,
            'available_places'=>$available,
            'available'=>$available>0,
            'fees_required'=>(bool)$participation['frais_requis'],
            'amount'=>$participation['montant_frais']!==null
                ?(float)$participation['montant_frais']
                :null,
            'currency'=>$participation['devise'],
            'conditions'=>$participation['conditions']
        ];
    }

    return $hospitals;
}

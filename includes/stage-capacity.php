<?php
/** STAGIA-RDC - Etape C1 : capacité globale et allocations D4. */

/** Total des capacités déjà acceptées, avec exclusion possible d'une participation en cours d'édition. */
function capacityAllocatedTotal(PDO $pdo,int $hostCampaignId,?int $excludeParticipationId=null):int{
    $sql="
        SELECT COALESCE(SUM(COALESCE(capacite_acceptee,capacite_allouee)),0)
        FROM stage_campaign_participations
        WHERE host_campaign_id=?
          AND statut='ACCEPTEE'
          AND (capacite_acceptee IS NOT NULL OR capacite_allouee IS NOT NULL)
    ";
    $params=[$hostCampaignId];
    if($excludeParticipationId){
        $sql.=' AND id<>?';
        $params[]=$excludeParticipationId;
    }
    $s=$pdo->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
}

/** Compte les réservations encore actives afin de ne pas dépasser le pool de capacité. */
function capacityActiveReservations(PDO $pdo,int $hostCampaignId):int{
    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM stage_reservations r
        JOIN stage_campaign_participations p ON p.id=r.participation_id
        WHERE p.host_campaign_id=?
          AND (
            r.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
            OR (
                r.statut='RESERVEE_TEMPORAIREMENT'
                AND (r.expires_at IS NULL OR r.expires_at>NOW())
            )
          )
    ");
    $s->execute([$hostCampaignId]);
    return (int)$s->fetchColumn();
}

/** Charge le pool de capacité d'une campagne d'accueil et peut le verrouiller dans une transaction. */
function capacityPool(PDO $pdo,int $hostCampaignId,bool $forUpdate=false):?array{
    $s=$pdo->prepare("
        SELECT *
        FROM stage_capacity_pools
        WHERE host_campaign_id=?
        LIMIT 1
        ".($forUpdate?'FOR UPDATE':'')."
    ");
    $s->execute([$hostCampaignId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}

/** Journalise tout changement de capacité pour conserver la traçabilité des allocations. */
function capacityHistory(PDO $pdo,int $hostCampaignId,?int $poolId,string $event,
    ?int $oldTotal,?int $newTotal,?int $oldReserve,?int $newReserve,
    int $allocated,array $details,?int $actorId):void{
    $pdo->prepare("
        INSERT INTO stage_capacity_history(
            host_campaign_id,capacity_pool_id,event_code,
            old_total,new_total,old_reserve,new_reserve,
            allocated_total,details,actor_user_id,created_at
        ) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())
    ")->execute([
        $hostCampaignId,$poolId,$event,
        $oldTotal,$newTotal,$oldReserve,$newReserve,
        $allocated,
        json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $actorId
    ]);
}

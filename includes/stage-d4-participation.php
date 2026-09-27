<?php
/**
 * STAGIA-RDC
 * Participations université <-> hôpital
 * Ancien nom D4 gardé pour compatibilité des fichiers existants.
 */

require_once __DIR__.'/stage-campaign.php';

function d4ParticipationHistory(PDO $pdo,int $participationId,string $eventCode,?string $previous,?string $new,array $details,?int $actorId):void{
    $pdo->prepare("
        INSERT INTO stage_campaign_participation_history(
            participation_id,event_code,previous_status,new_status,details,actor_user_id,created_at
        ) VALUES(?,?,?,?,?,?,NOW())
    ")->execute([
        $participationId,$eventCode,$previous,$new,
        json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        $actorId
    ]);
}

function universityD4Campaign(PDO $pdo,int $campaignId,int $eid,bool $forUpdate=false):array{
    $s=$pdo->prepare("
        SELECT c.*,st.code stage_type_code,st.libelle stage_type_libelle
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        WHERE c.id=? AND c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE'
        LIMIT 1 ".($forUpdate?'FOR UPDATE':'')."
    ");
    $s->execute([$campaignId,$eid]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Session universitaire introuvable.');
    return $row;
}

function hostD4Campaign(PDO $pdo,int $campaignId,int $eid,bool $forUpdate=false):array{
    $s=$pdo->prepare("
        SELECT c.*,st.code stage_type_code,st.libelle stage_type_libelle
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        WHERE c.id=? AND c.owner_etablissement_id=? AND c.type_campagne='ACCUEIL'
        LIMIT 1 ".($forUpdate?'FOR UPDATE':'')."
    ");
    $s->execute([$campaignId,$eid]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException("Session d'accueil introuvable.");
    return $row;
}

function d4MinimumHospitals(PDO $pdo,int $stageTypeId):int{
    return max(1,(int)stagePolicyValue($pdo,$stageTypeId,'minimum_hospitals_to_solicit',1));
}

function normalizedMoney(?string $value):?float{
    if($value===null||trim($value)==='')return null;
    $value=str_replace(',','.',trim($value));
    if(!is_numeric($value))throw new RuntimeException('Montant invalide.');
    $n=(float)$value;
    if($n<0)throw new RuntimeException('Le montant ne peut pas être négatif.');
    return round($n,2);
}
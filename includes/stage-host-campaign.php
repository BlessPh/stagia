<?php
/**
 * STAGIA-RDC - Helpers Campagnes d'accueil génériques.
 * Le périmètre établissement est toujours dérivé côté serveur.
 */
function hostCampaign(PDO $pdo,int $id,int $eid,bool $forUpdate=false):array{
    $sql="
        SELECT c.*,st.code stage_type_code,st.libelle stage_type_libelle,
               st.owner_etablissement_id stage_type_owner_id
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        WHERE c.id=?
          AND c.owner_etablissement_id=?
          AND c.type_campagne='ACCUEIL'
        LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);
    $s->execute([$id,$eid]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException("Campagne d'accueil introuvable.");
    return $row;
}

function validateHostCampaignPayload(PDO $pdo,int $eid,array $data):array{
    $title=trim((string)($data['titre']??''));
    $description=trim((string)($data['description']??''));
    $objective=trim((string)($data['objectif_stage']??''));
    $stageTypeId=(int)($data['stage_type_id']??0);
    $dateStart=trim((string)($data['date_debut']??''));
    $dateEnd=trim((string)($data['date_fin']??''));

    if($title===''||mb_strlen($title)>200)
        throw new RuntimeException('Le titre est obligatoire et limité à 200 caractères.');
    if(!$stageTypeId)throw new RuntimeException('Sélectionnez un type de stage.');
    if($dateStart===''||$dateEnd==='')throw new RuntimeException('La période est obligatoire.');

    $tsStart=strtotime($dateStart);$tsEnd=strtotime($dateEnd);
    if(!$tsStart||!$tsEnd)throw new RuntimeException('Période invalide.');
    if($tsStart>$tsEnd)throw new RuntimeException('La date de début doit précéder la date de fin.');

    /* Type historique/global OU type propre à l'établissement connecté. */
    $s=$pdo->prepare("
        SELECT id,code,libelle,owner_etablissement_id
        FROM stage_types
        WHERE id=? AND actif=1
          AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?)
        LIMIT 1
    ");
    $s->execute([$stageTypeId,$eid]);
    $type=$s->fetch(PDO::FETCH_ASSOC);
    if(!$type)throw new RuntimeException('Type de stage invalide, inactif ou hors de votre établissement.');

    return [
        'titre'=>$title,
        'description'=>$description?:null,
        'objectif_stage'=>$objective?:null,
        'stage_type_id'=>$stageTypeId,
        'stage_type'=>$type,
        'date_debut'=>date('Y-m-d',$tsStart),
        'date_fin'=>date('Y-m-d',$tsEnd),
        'financial'=>stageTypeCampaignFinancialSnapshot($pdo,$type)
    ];
}

function hostCampaignMergeFinancialConfig(?string $json,array $financial):array{
    $config=campaignConfig($json);
    $config['payment_expected']=(bool)$financial['required'];
    $config['financial']=$financial;
    return $config;
}

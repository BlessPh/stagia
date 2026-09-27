<?php
function trainingEtablissementId(PDO $pdo):int{
    $eid=function_exists('currentEtablissementId')?(int)currentEtablissementId($pdo):(int)($_SESSION['etablissement_id']??0);
    if(!$eid)throw new RuntimeException('Aucun établissement associé à votre compte.');
    return $eid;
}
function trainingHasAny(PDO $pdo,array $permissions):bool{
    foreach($permissions as $p)if(hasPermission($pdo,$p))return true;
    return false;
}
function trainingAllowedCampaignTypes(PDO $pdo,string $mode='view'):array{
    $types=[];
    if(contextAcademicEnabled()){
        $p=$mode==='create'?['campaign.university.create']:
           ($mode==='update'?['campaign.university.update']:
           ($mode==='publish'?['campaign.university.publish','campaign.university.update']:['campaign.university.view','campaign.university.create','campaign.university.update','campaign.university.publish']));
        if(trainingHasAny($pdo,$p))$types[]='UNIVERSITAIRE';
    }
    if(contextHostEnabled()){
        $p=$mode==='create'?['campaign.hosting.create']:
           ($mode==='update'?['campaign.hosting.update']:
           ($mode==='publish'?['campaign.hosting.publish','campaign.hosting.update']:['campaign.hosting.view','campaign.hosting.create','campaign.hosting.update','campaign.hosting.publish']));
        if(trainingHasAny($pdo,$p))$types[]='ACCUEIL';
    }
    return array_values(array_unique($types));
}
function requireTrainingPlanAccess(PDO $pdo,string $mode='view'):void{
    if(!trainingAllowedCampaignTypes($pdo,$mode))throw new RuntimeException("Vous n'avez pas l'autorisation requise pour les plans de formation.");
}
function trainingUuidV4():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);$h=bin2hex($d);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function trainingCampaign(PDO $pdo,int $id,int $eid,array $allowedTypes=[],bool $lock=false):array{
    if(!$id)throw new RuntimeException('Campagne invalide.');
    $sql="SELECT c.id,c.stage_type_id,c.owner_etablissement_id,c.type_campagne,c.titre,c.code,c.date_debut,c.date_fin,c.statut,st.libelle stage_type_libelle FROM stage_campaigns c JOIN stage_types st ON st.id=c.stage_type_id WHERE c.id=? AND c.owner_etablissement_id=?";
    $params=[$id,$eid];
    if($allowedTypes){$sql.=' AND c.type_campagne IN('.implode(',',array_fill(0,count($allowedTypes),'?')).')';array_push($params,...$allowedTypes);}
    $sql.=' LIMIT 1'.($lock?' FOR UPDATE':'');
    $s=$pdo->prepare($sql);$s->execute($params);$c=$s->fetch(PDO::FETCH_ASSOC);
    if(!$c)throw new RuntimeException('Campagne introuvable ou hors de votre établissement.');
    return $c;
}
function trainingPlan(PDO $pdo,int $id,int $eid,bool $lock=false):array{
    if(!$id)throw new RuntimeException('Plan de formation invalide.');
    $s=$pdo->prepare("SELECT p.*,c.owner_etablissement_id,c.type_campagne,c.stage_type_id,c.date_debut campaign_start,c.date_fin campaign_end,c.titre campaign_title FROM stage_training_plans p JOIN stage_campaigns c ON c.id=p.campaign_id WHERE p.id=? AND c.owner_etablissement_id=? LIMIT 1".($lock?' FOR UPDATE':''));
    $s->execute([$id,$eid]);$p=$s->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new RuntimeException('Plan de formation introuvable ou hors de votre établissement.');
    return $p;
}
function trainingItem(PDO $pdo,int $id,int $eid,bool $lock=false):array{
    if(!$id)throw new RuntimeException('Élément invalide.');
    $s=$pdo->prepare("SELECT i.*,p.statut plan_statut,p.referential_id,p.campaign_id,c.owner_etablissement_id,c.date_debut campaign_start,c.date_fin campaign_end FROM stage_training_plan_items i JOIN stage_training_plans p ON p.id=i.plan_id JOIN stage_campaigns c ON c.id=p.campaign_id WHERE i.id=? AND c.owner_etablissement_id=? LIMIT 1".($lock?' FOR UPDATE':''));
    $s->execute([$id,$eid]);$i=$s->fetch(PDO::FETCH_ASSOC);
    if(!$i)throw new RuntimeException('Élément du plan introuvable ou hors de votre établissement.');
    return $i;
}
function trainingValidateReferential(PDO $pdo,int $referentialId,int $eid,int $stageTypeId):?array{
    if(!$referentialId)return null;
    $s=$pdo->prepare("SELECT id,code,libelle,version,stage_type_id FROM stage_referentials WHERE id=? AND etablissement_id=? AND stage_type_id=? AND statut='ACTIF' LIMIT 1");
    $s->execute([$referentialId,$eid,$stageTypeId]);$r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new RuntimeException("Le référentiel choisi n'est pas actif, compatible ou n'appartient pas à votre établissement.");
    return $r;
}
function trainingValidateObjective(PDO $pdo,int $objectiveId,?int $referentialId):?array{
    if(!$objectiveId)return null;
    if(!$referentialId)throw new RuntimeException("Sélectionnez d'abord un référentiel avant de lier un objectif.");
    $s=$pdo->prepare("SELECT id,referential_id,code,libelle,description FROM stage_referential_objectives WHERE id=? AND referential_id=? LIMIT 1");
    $s->execute([$objectiveId,$referentialId]);$o=$s->fetch(PDO::FETCH_ASSOC);
    if(!$o)throw new RuntimeException("L'objectif choisi n'appartient pas au référentiel du plan.");
    return $o;
}
function trainingValidateHostUnit(PDO $pdo,int $unitId,int $eid):?array{
    if(!$unitId)return null;
    if(!contextHostEnabled())throw new RuntimeException("Une unité d'accueil ne peut être affectée ici que depuis l'établissement d'accueil concerné.");
    $s=$pdo->prepare("SELECT id,code,nom FROM host_units WHERE id=? AND host_etablissement_id=? AND actif=1 LIMIT 1");$s->execute([$unitId,$eid]);$u=$s->fetch(PDO::FETCH_ASSOC);
    if(!$u)throw new RuntimeException("Service / unité d'accueil invalide.");
    return $u;
}
function trainingValidateResponsible(PDO $pdo,int $userId,int $eid):?array{
    if(!$userId)return null;
    $s=$pdo->prepare("SELECT u.id,u.nom,u.postnom,u.prenom FROM users u WHERE u.id=? AND u.statut_compte='ACTIF' AND EXISTS(SELECT 1 FROM etablissement_users eu WHERE eu.user_id=u.id AND eu.etablissement_id=?) LIMIT 1");
    $s->execute([$userId,$eid]);$u=$s->fetch(PDO::FETCH_ASSOC);
    if(!$u)throw new RuntimeException("Le responsable choisi n'est pas un utilisateur actif de votre établissement.");
    return $u;
}
function trainingValidateItemDates(?string $start,?string $end,string $campaignStart,string $campaignEnd):array{
    $start=trim((string)$start);$end=trim((string)$end);
    if($start!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start))throw new RuntimeException('Date de début prévue invalide.');
    if($end!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end))throw new RuntimeException('Date de fin prévue invalide.');
    if($start!==''&&$end!==''&&$start>$end)throw new RuntimeException('La date de début prévue doit précéder la date de fin prévue.');
    foreach([$start,$end] as $d)if($d!==''&&($d<$campaignStart||$d>$campaignEnd))throw new RuntimeException('Les dates prévues doivent rester dans la période de la campagne.');
    return [$start?:null,$end?:null];
}

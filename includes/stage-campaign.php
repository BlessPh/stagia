<?php
require_once __DIR__.'/stage-type.php';
/**
 * STAGIA-RDC - Helpers Campagnes universitaires
 * Moteur générique piloté par stage_types + stage_type_policies.
 */
/** Génère un UUID v4 pour les entités du cycle de campagne. */
function stageUuidV4():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

/** Lit une politique JSON d'un type de stage avec valeur de repli. */
function stagePolicyValue(PDO $pdo,int $stageTypeId,string $key,mixed $default=null):mixed{
    $s=$pdo->prepare("SELECT policy_value FROM stage_type_policies WHERE stage_type_id=? AND policy_key=? LIMIT 1");
    $s->execute([$stageTypeId,$key]);
    $raw=$s->fetchColumn();
    if($raw===false||$raw===null)return $default;

    $decoded=json_decode((string)$raw,true);
    if(json_last_error()!==JSON_ERROR_NONE)return $raw;

    // Compatibilité avec les anciennes valeurs stockées comme JSON_ARRAY(value).
    if(is_array($decoded)&&array_is_list($decoded)&&count($decoded)===1)return $decoded[0];
    return $decoded;
}

/** Retourne le snapshot complet des politiques du type de stage. */
function stagePolicySnapshot(PDO $pdo,int $stageTypeId):array{
    $s=$pdo->prepare("SELECT policy_key,policy_value FROM stage_type_policies WHERE stage_type_id=? ORDER BY policy_key");
    $s->execute([$stageTypeId]);
    $out=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
        $v=json_decode((string)$row['policy_value'],true);
        if(json_last_error()!==JSON_ERROR_NONE)$v=$row['policy_value'];
        if(is_array($v)&&array_is_list($v)&&count($v)===1)$v=$v[0];
        $out[$row['policy_key']]=$v;
    }
    return $out;
}

/** Lit une politique sous forme booléenne. */
function stagePolicyBool(PDO $pdo,int $stageTypeId,string $key,bool $default=false):bool{
    $v=stagePolicyValue($pdo,$stageTypeId,$key,$default);
    if(is_bool($v))return $v;
    if(is_numeric($v))return (int)$v===1;
    return in_array(strtolower(trim((string)$v)),['1','true','yes','oui','on'],true);
}

/** Décode la configuration JSON d'une campagne en tableau sûr. */
function campaignConfig(?string $json):array{
    if(!$json)return [];
    $v=json_decode($json,true);
    return is_array($v)?$v:[];
}

/** Extrait et normalise la partie financière de la configuration campagne. */
function campaignFinancialConfig(array $config):array{
    $f=is_array($config['financial']??null)?$config['financial']:[];
    $required=(bool)($f['required']??$config['payment_expected']??false);
    $mode=strtoupper((string)($f['mode']??($required?'PAYANT':'GRATUIT')));
    if(!in_array($mode,['GRATUIT','PAYANT'],true))$mode=$required?'PAYANT':'GRATUIT';
    $amount=$required?(string)($f['amount']??'0'):null;
    $currency=$required?strtoupper(trim((string)($f['currency']??''))):null;
    return [
        'required'=>$required,
        'mode'=>$mode,
        'amount'=>$amount,
        'currency'=>$currency!==''?$currency:null
    ];
}

/** Journalise une transition de statut de campagne. */
function campaignStatusHistory(PDO $pdo,int $campaignId,?string $previous,string $new,?string $reason,?int $userId):void{
    $pdo->prepare("INSERT INTO stage_campaign_status_history(campaign_id,previous_status,new_status,reason,changed_by_user_id,changed_at) VALUES(?,?,?,?,?,NOW())")
        ->execute([$campaignId,$previous,$new,$reason,$userId]);
}

/** Vérifie qu'une campagne possède au moins un accueil hospitalier retenu et utilisable. */
function campaignHasAcceptedHostCapacity(PDO $pdo,int $campaignId):bool{
    $stmt=$pdo->prepare("
        SELECT 1
        FROM stage_campaign_participations participation
        INNER JOIN stage_campaigns host_campaign
            ON host_campaign.id=participation.host_campaign_id
           AND host_campaign.type_campagne='ACCUEIL'
           AND host_campaign.statut NOT IN('ANNULEE','TERMINEE')
        INNER JOIN stage_capacity_pools capacity_pool
            ON capacity_pool.host_campaign_id=host_campaign.id
        INNER JOIN etablissements hospital
            ON hospital.id=participation.host_etablissement_id
           AND hospital.type_etablissement='HOPITAL'
           AND hospital.statut IN('VALIDE','ACTIF')
        WHERE participation.university_campaign_id=?
          AND participation.statut='ACCEPTEE'
          AND COALESCE(
                NULLIF(participation.capacite_acceptee,0),
                NULLIF(participation.capacite_allouee,0),
                0
              )>0
        LIMIT 1
    ");
    $stmt->execute([$campaignId]);
    return (bool)$stmt->fetchColumn();
}

/** Valide que les promotions choisies sont actives et compatibles avec la campagne. */
function campaignValidatedPromotions(PDO $pdo,int $etablissementId,int $anneeId,int $stageTypeId,array $ids):array{
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
    if(!$ids)throw new RuntimeException('Sélectionnez au moins une promotion.');

    $ph=implode(',',array_fill(0,count($ids),'?'));
    $s=$pdo->prepare("\n        SELECT p.id,p.nom,p.annee_academique_id,p.academic_level_id,\n               l.code level_code,l.libelle level_libelle,f.nom filiere_nom,\n               psc.id promotion_stage_config_id\n        FROM promotions p\n        JOIN filieres f ON f.id=p.filiere_id AND f.etablissement_id=p.etablissement_id\n        JOIN academic_levels l ON l.id=p.academic_level_id AND l.actif=1\n        LEFT JOIN promotion_stage_configs psc\n          ON psc.promotion_id=p.id\n         AND psc.annee_academique_id=?\n         AND psc.stage_type_id=?\n         AND psc.actif=1\n        WHERE p.id IN($ph)\n          AND p.etablissement_id=?\n          AND p.annee_academique_id=?\n          AND p.actif=1\n          AND f.actif=1\n          AND f.validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL')\n    ");
    $s->execute([$anneeId,$stageTypeId,...$ids,$etablissementId,$anneeId]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);

    if(count($rows)!==count($ids))
        throw new RuntimeException("Une ou plusieurs promotions sont invalides, appartiennent à une autre année ou n'ont pas encore de niveau académique normalisé.");

    $allowed=stagePolicyValue($pdo,$stageTypeId,'eligible_level_codes',[]);
    if($allowed!==null&&$allowed!==[]&&$allowed!==''){
        if(!is_array($allowed))$allowed=[$allowed];
        $allowed=array_values(array_filter(array_map(fn($x)=>strtoupper(trim((string)$x)),$allowed)));
        foreach($rows as $row){
            if($allowed&&!in_array(strtoupper((string)$row['level_code']),$allowed,true))
                throw new RuntimeException("Le niveau {$row['level_code']} de « {$row['nom']} » n'est pas autorisé pour ce type de stage.");
        }
    }
    return $rows;
}

/** Valide et normalise le formulaire de création ou mise à jour d'une campagne universitaire. */
function validateUniversityCampaignPayload(PDO $pdo,int $eid,array $data):array{
    $title=trim((string)($data['titre']??''));
    $objective=trim((string)($data['objectif_stage']??''));
    $description=trim((string)($data['description']??''));
    $stageTypeId=(int)($data['stage_type_id']??0);
    $anneeId=(int)($data['annee_academique_id']??0);
    $faculteId=(int)($data['owner_faculte_id']??0)?:null;
    $dateStart=trim((string)($data['date_debut']??''));
    $dateEnd=trim((string)($data['date_fin']??''));
    $openAt=trim((string)($data['ouverture_candidatures']??''));
    $closeAt=trim((string)($data['cloture_candidatures']??''));

    if($title===''||mb_strlen($title)>200)
        throw new RuntimeException('Le titre de la campagne est obligatoire et limité à 200 caractères.');
    if(!$stageTypeId||!$anneeId)throw new RuntimeException("Le type de stage et l'année académique sont obligatoires.");

    /*
     * Le type doit être soit historique/global (owner NULL), soit appartenir
     * à l'établissement connecté. Un client ne peut jamais élargir ce scope.
     */
    $s=$pdo->prepare("
        SELECT id,code,libelle,owner_etablissement_id
        FROM stage_types
        WHERE id=?
          AND actif=1
          AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?)
        LIMIT 1
    ");
    $s->execute([$stageTypeId,$eid]);
    $stageType=$s->fetch(PDO::FETCH_ASSOC);
    if(!$stageType)throw new RuntimeException('Type de stage invalide, inactif ou hors de votre établissement.');

    $s=$pdo->prepare("SELECT id,libelle FROM annees_academiques WHERE id=? AND etablissement_id=? AND actif=1 LIMIT 1");
    $s->execute([$anneeId,$eid]);
    $year=$s->fetch(PDO::FETCH_ASSOC);
    if(!$year)throw new RuntimeException("L'année académique est invalide.");

    if($faculteId){
        $s=$pdo->prepare("\n            SELECT id FROM facultes\n            WHERE id=? AND etablissement_id=? AND actif=1\n              AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL')\n            LIMIT 1\n        ");
        $s->execute([$faculteId,$eid]);
        if(!$s->fetchColumn())throw new RuntimeException("L'unité académique responsable est invalide.");
    }

    foreach([$dateStart,$dateEnd,$openAt,$closeAt] as $value)
        if($value==='')throw new RuntimeException('Toutes les dates de campagne sont obligatoires.');

    $tsStart=strtotime($dateStart);$tsEnd=strtotime($dateEnd);$tsOpen=strtotime($openAt);$tsClose=strtotime($closeAt);
    if(!$tsStart||!$tsEnd||!$tsOpen||!$tsClose)throw new RuntimeException('Une ou plusieurs dates sont invalides.');
    if($tsStart>$tsEnd)throw new RuntimeException('La date de début du stage doit précéder la date de fin.');
    if($tsOpen>=$tsClose)throw new RuntimeException("L'ouverture des candidatures doit précéder leur clôture.");
    if($tsClose>$tsStart+86399)throw new RuntimeException('Les candidatures doivent être clôturées avant ou au plus tard au début du stage.');

    $promotions=campaignValidatedPromotions($pdo,$eid,$anneeId,$stageTypeId,$data['promotion_ids']??[]);
    $policies=stagePolicySnapshot($pdo,$stageTypeId);
    $config=[];

    /*
     * Finance : héritée du type de stage.
     * On stocke un snapshot dans la campagne afin qu'une modification future
     * du type ne modifie jamais rétroactivement les campagnes déjà créées.
     */
    $financial=stageTypeCampaignFinancialSnapshot($pdo,$stageType);
    $config['payment_expected']=(bool)$financial['required'];
    $config['financial']=$financial;

    /* Règles génériques, activées uniquement lorsque la politique existe. */
    if(array_key_exists('max_simultaneous_applications',$policies)){
        $policyMax=max(1,(int)$policies['max_simultaneous_applications']);
        $max=(int)($data['max_simultaneous_applications']??$policyMax);
        if($max<1||$max>$policyMax)
            throw new RuntimeException("Le nombre de demandes simultanées doit être compris entre 1 et $policyMax.");
        $config['max_simultaneous_applications']=$max;
    }

    $external=strtoupper(trim((string)($data['external_stage_mode']??'FOLLOW_POLICY')));
    if(!in_array($external,['FOLLOW_POLICY','ALLOWED','FORBIDDEN'],true))
        throw new RuntimeException('Mode de stage hors plateforme invalide.');
    $config['external_stage_mode']=$external;

    if(array_key_exists('direct_service_choice_default',$policies))
        $config['direct_service_choice']=(string)($data['direct_service_choice']??'0')==='1';

    $requiresHosting=stagePolicyBool($pdo,$stageTypeId,'requires_hosting_participation',false);
    $config['requires_hosting_preparation']=$requiresHosting;

    return [
        'titre'=>$title,'objectif_stage'=>$objective,'description'=>$description?:null,
        'stage_type'=>$stageType,'stage_type_id'=>$stageTypeId,
        'annee'=>$year,'annee_academique_id'=>$anneeId,'owner_faculte_id'=>$faculteId,
        'date_debut'=>date('Y-m-d',$tsStart),'date_fin'=>date('Y-m-d',$tsEnd),
        'ouverture_candidatures'=>date('Y-m-d H:i:s',$tsOpen),
        'cloture_candidatures'=>date('Y-m-d H:i:s',$tsClose),
        'promotions'=>$promotions,'configuration'=>$config
    ];
}

/** Transforme une liste textuelle de prérequis en éléments non vides et dédoublonnés. */
function parseCampaignRequirements(string $text):array{
    $lines=preg_split('/\R/u',$text)?:[];$out=[];
    foreach($lines as $line){
        $line=trim(preg_replace('/\s+/u',' ',$line)??$line);
        if($line==='')continue;
        $lower=mb_strtolower($line);
        $existing=array_map('mb_strtolower',$out);
        if(in_array($lower,$existing,true))continue;
        if(mb_strlen($line)>200)throw new RuntimeException('Chaque pièce demandée doit contenir au maximum 200 caractères.');
        $out[]=$line;
    }
    return array_slice($out,0,30);
}

/** Liste les structures d'accueil disponibles pour un type de stage. */
function campaignSelectedHosts(PDO $pdo,int $stageTypeId):array{
    $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['host_ids']??[])))));

    if($ids){
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare("SELECT id FROM etablissements WHERE id IN($ph) AND type_etablissement='HOPITAL' AND statut IN('VALIDE','ACTIF')");
        $s->execute($ids);
        $valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        sort($ids);sort($valid);

        if($ids!==$valid)
            throw new RuntimeException("Un hôpital sélectionné est invalide ou inactif.");
    }

    $s=$pdo->prepare("SELECT code FROM stage_types WHERE id=? AND actif=1 LIMIT 1");
    $s->execute([$stageTypeId]);
    $code=(string)$s->fetchColumn();

    if($code==='MEDICAL_D4'&&!$ids)
        throw new RuntimeException("Sélectionnez au moins un hôpital à solliciter.");

    return $ids;
}

<?php
/**
 * STAGIA-RDC - Helpers des types de stage locaux.
 * Chaque établissement gère ses propres types. Les anciens types globaux
 * restent disponibles en lecture seule pour compatibilité.
 */
/** Vérifie que le contexte académique ou d'accueil autorise la gestion des types de stage. */
function stageTypeCanManage(PDO $pdo):bool{
    $academic=contextAcademicEnabled();
    $host=contextHostEnabled();

    if($academic && (
        hasPermission($pdo,'campaign.university.create') ||
        hasPermission($pdo,'campaign.university.update')
    )) return true;

    return $host && hasPermission($pdo,'stage.manage');
}

/** Interrompt l'opération si la gestion des types de stage n'est pas autorisée. */
function requireStageTypeManage(PDO $pdo):void{
    if(!stageTypeCanManage($pdo)){
        http_response_code(403);
        throw new RuntimeException('Vous ne pouvez pas gérer les types de stage dans ce contexte.');
    }
}

/** Retourne l'établissement actif obligatoire pour les opérations sur les types locaux. */
function stageTypeEtablissementId(PDO $pdo):int{
    $eid=currentEtablissementId($pdo);
    if(!$eid)$eid=(int)($_SESSION['etablissement_id']??0);
    if(!$eid)throw new RuntimeException('Aucun établissement actif.');
    return (int)$eid;
}

/** Normalise, dédoublonne et filtre les codes de niveaux académiques. */
function stageTypeNormalizeLevels(array $values):array{
    return array_values(array_unique(array_filter(array_map(
        fn($x)=>strtoupper(trim((string)$x)),
        $values
    ))));
}

/** Liste les niveaux académiques réellement disponibles dans les promotions actives. */
function stageTypeAvailableLevels(PDO $pdo,int $eid):array{
    if(!contextAcademicEnabled())return [];

    $s=$pdo->prepare("
        SELECT DISTINCT l.code,l.libelle,l.ordre
        FROM promotions p
        JOIN academic_levels l ON l.id=p.academic_level_id AND l.actif=1
        WHERE p.etablissement_id=? AND p.actif=1
        ORDER BY l.ordre,l.code
    ");
    $s->execute([$eid]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Valide que chaque niveau demandé appartient à la liste disponible dans le contexte. */
function stageTypeValidateLevels(PDO $pdo,int $eid,array $levels):array{
    $levels=stageTypeNormalizeLevels($levels);
    if(!$levels)return [];
    if(!contextAcademicEnabled())
        throw new RuntimeException('Les niveaux académiques ne sont pas disponibles dans ce contexte.');

    $available=array_map(
        fn($x)=>strtoupper((string)$x['code']),
        stageTypeAvailableLevels($pdo,$eid)
    );
    foreach($levels as $level){
        if(!in_array($level,$available,true))
            throw new RuntimeException("Niveau académique invalide : $level.");
    }
    return $levels;
}

/** Transforme un libellé en code local unique, limité à la longueur métier attendue. */
function stageTypeGenerateCode(PDO $pdo,int $eid,string $label):string{
    $ascii=strtr(mb_strtoupper($label),[
        'À'=>'A','Á'=>'A','Â'=>'A','Ä'=>'A','Ã'=>'A','Å'=>'A','Æ'=>'AE','Ç'=>'C',
        'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
        'Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Ö'=>'O','Õ'=>'O','Œ'=>'OE',
        'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y'
    ]);
    $slug=preg_replace('/[^A-Z0-9]+/','_',$ascii)?:'TYPE_STAGE';
    $slug=trim($slug,'_')?:'TYPE_STAGE';
    $prefix='ETB'.$eid.'_';
    $base=$prefix.substr($slug,0,max(1,50-strlen($prefix)));
    $code=$base;$n=2;
    $exists=$pdo->prepare('SELECT 1 FROM stage_types WHERE code=? LIMIT 1');

    while(true){
        $exists->execute([$code]);
        if(!$exists->fetchColumn())return $code;
        $suffix='_'.$n++;
        $code=substr($base,0,50-strlen($suffix)).$suffix;
        if($n>999)throw new RuntimeException('Impossible de générer un code unique.');
    }
}

/** Remplace une politique JSON de type de stage ; peut supprimer la clé si sa valeur devient nulle. */
function stageTypeSetPolicy(PDO $pdo,int $stageTypeId,string $key,mixed $value,bool $deleteWhenNull=false):void{
    $pdo->prepare("DELETE FROM stage_type_policies WHERE stage_type_id=? AND policy_key=?")
        ->execute([$stageTypeId,$key]);

    if($deleteWhenNull && $value===null)return;

    $pdo->prepare("
        INSERT INTO stage_type_policies(stage_type_id,policy_key,policy_value)
        VALUES(?,?,?)
    ")->execute([
        $stageTypeId,
        $key,
        json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ]);
}

/** Enregistre la politique des niveaux académiques admis. */
function stageTypeSaveEligibleLevels(PDO $pdo,int $stageTypeId,array $levels):void{
    stageTypeSetPolicy($pdo,$stageTypeId,'eligible_level_codes',$levels?:null,true);
}

/** Valide et normalise la politique tarifaire gratuite ou payante d'un type de stage. */
function stageTypeValidateFinancial(array $data):array{
    $mode=strtoupper(trim((string)($data['financial_mode']??'')));
    if(!in_array($mode,['GRATUIT','PAYANT'],true))
        throw new RuntimeException('Indiquez si ce type de stage est gratuit ou payant.');

    $required=$mode==='PAYANT';
    $amount=null;
    $currency=null;

    if($required){
        $raw=str_replace(',','.',trim((string)($data['financial_amount']??'')));
        if($raw===''||!is_numeric($raw)||(float)$raw<=0)
            throw new RuntimeException('Le montant du type de stage payant doit être supérieur à zéro.');

        $amount=number_format((float)$raw,2,'.','');
        $currency=strtoupper(trim((string)($data['financial_currency']??'')));
        if(!preg_match('/^[A-Z]{3,10}$/',$currency))
            throw new RuntimeException('Devise invalide.');
    }

    return [
        'configured'=>true,
        'required'=>$required,
        'mode'=>$mode,
        'amount'=>$amount,
        'currency'=>$currency,
        'source'=>'STAGE_TYPE'
    ];
}

/** Persiste les trois clés de politique financière après validation. */
function stageTypeSaveFinancialPolicy(PDO $pdo,int $stageTypeId,array $financial):void{
    stageTypeSetPolicy($pdo,$stageTypeId,'financial_mode',$financial['mode']);
    stageTypeSetPolicy($pdo,$stageTypeId,'financial_amount',$financial['required']?$financial['amount']:null,true);
    stageTypeSetPolicy($pdo,$stageTypeId,'financial_currency',$financial['required']?$financial['currency']:null,true);
}

/** Reconstruit une configuration financière à partir des politiques, avec compatibilité historique optionnelle. */
function stageTypeFinancialFromPolicies(array $policies,bool $legacyFallback=true):array{
    $mode=strtoupper(trim((string)($policies['financial_mode']??'')));
    $configured=in_array($mode,['GRATUIT','PAYANT'],true);

    if(!$configured){
        if(!$legacyFallback){
            return [
                'configured'=>false,'required'=>false,'mode'=>null,
                'amount'=>null,'currency'=>null,'source'=>'NOT_CONFIGURED'
            ];
        }
        return [
            'configured'=>false,'required'=>false,'mode'=>'GRATUIT',
            'amount'=>null,'currency'=>null,'source'=>'LEGACY_DEFAULT'
        ];
    }

    $required=$mode==='PAYANT';
    $amount=$required?trim((string)($policies['financial_amount']??'')):null;
    $currency=$required?strtoupper(trim((string)($policies['financial_currency']??''))):null;

    if($required){
        if($amount===''||!is_numeric($amount)||(float)$amount<=0 || !$currency || !preg_match('/^[A-Z]{3,10}$/',$currency)){
            return [
                'configured'=>false,'required'=>false,'mode'=>null,
                'amount'=>null,'currency'=>null,'source'=>'INVALID_POLICY'
            ];
        }
        $amount=number_format((float)$amount,2,'.','');
    }

    return [
        'configured'=>true,
        'required'=>$required,
        'mode'=>$mode,
        'amount'=>$amount,
        'currency'=>$currency,
        'source'=>'STAGE_TYPE'
    ];
}

/** Charge les politiques financières stockées en JSON et les convertit en tableau métier. */
function stageTypeFinancialPolicy(PDO $pdo,int $stageTypeId,bool $legacyFallback=true):array{
    $s=$pdo->prepare("
        SELECT policy_key,policy_value
        FROM stage_type_policies
        WHERE stage_type_id=?
          AND policy_key IN('financial_mode','financial_amount','financial_currency')
    ");
    $s->execute([$stageTypeId]);

    $policies=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){
        $v=json_decode((string)$row['policy_value'],true);
        if(json_last_error()!==JSON_ERROR_NONE)$v=$row['policy_value'];
        if(is_array($v)&&array_is_list($v)&&count($v)===1)$v=$v[0];
        $policies[$row['policy_key']]=$v;
    }
    return stageTypeFinancialFromPolicies($policies,$legacyFallback);
}

/** Fige les paramètres financiers du type dans une campagne, après vérification de leur validité. */
function stageTypeCampaignFinancialSnapshot(PDO $pdo,array $stageType):array{
    $id=(int)($stageType['id']??0);
    $local=($stageType['owner_etablissement_id']??null)!==null;
    $financial=stageTypeFinancialPolicy($pdo,$id,true);

    if($local&&!$financial['configured'])
        throw new RuntimeException('Ce type de stage n’a pas de configuration financière valide. Modifiez d’abord le type de stage.');

    return [
        'required'=>(bool)$financial['required'],
        'mode'=>$financial['mode']?:'GRATUIT',
        'amount'=>$financial['required']?$financial['amount']:null,
        'currency'=>$financial['required']?$financial['currency']:null,
        'source'=>$financial['source'],
        'stage_type_id'=>$id,
        'snapshot_at'=>date('c')
    ];
}

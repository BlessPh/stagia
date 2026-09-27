<?php
/**
 * Endpoint AJAX d'enregistrement d'une grille d'évaluation par promotion, année et type de stage.
 * Il normalise les critères, crée les compétences locales éventuelles et versionne le référentiel déjà utilisé.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

/** Génère l'UUID d'un référentiel ou d'une configuration nouvellement créée. */
function egUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}
function egCols(PDO $pdo,string $table):array{
    static $cache=[];if(isset($cache[$table]))return $cache[$table];
    $out=[];foreach($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r)$out[$r['Field']]=$r;
    return $cache[$table]=$out;
}
function egHas(PDO $pdo,string $table,string $col):bool{return isset(egCols($pdo,$table)[$col]);}
/** Vérifie qu'un objet appartient à l'établissement ou qu'il est global lorsqu'il peut être partagé. */
function egEnsureOwner(PDO $pdo,string $table,int $id,int $eid):bool{
    $cols=egCols($pdo,$table);
    if(!$cols)return false;
    if(isset($cols['etablissement_id'])){
        $s=$pdo->prepare("SELECT 1 FROM `$table` WHERE id=? AND (etablissement_id=? OR etablissement_id IS NULL) LIMIT 1");
        $s->execute([$id,$eid]);return (bool)$s->fetchColumn();
    }
    return (bool)$pdo->prepare("SELECT 1 FROM `$table` WHERE id=? LIMIT 1")->execute([$id]);
}
/** Normalise une catégorie de compétence sur la liste de valeurs métier acceptées. */
function egCategory(string $v):string{
    $v=strtoupper(trim($v));
    $ok=['CLINIQUE','TECHNIQUE','COMMUNICATION','ETHIQUE','PROFESSIONNALISME','ORGANISATION'];
    return in_array($v,$ok,true)?$v:'CLINIQUE';
}

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* Les identifiants de contexte et la migration minimale de grille sont contrôlés avant l'écriture. */
    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $promotionId=(int)($_POST['promotion_id']??0);
    $yearId=(int)($_POST['annee_academique_id']??0);
    $stageTypeId=(int)($_POST['stage_type_id']??0);
    $label=trim((string)($_POST['libelle']??''));
    $criteria=json_decode((string)($_POST['criteria']??'[]'),true);

    if(!$eid||!$uid||!$promotionId||!$yearId||!$stageTypeId)
        jsonResponse(false,'Promotion, année académique et type de stage sont obligatoires.',[],422);

    if(!egHas($pdo,'stage_referential_competencies','note_max')||
       !egHas($pdo,'stage_referential_competencies','section')||
       !egHas($pdo,'stage_competencies','etablissement_id'))
        jsonResponse(false,'Exécutez d’abord la migration 20260923_grilles_evaluation_promotion.sql.',[],422);

    if(!is_array($criteria)||!$criteria)
        jsonResponse(false,'Sélectionnez au moins un critère.',[],422);

    /* Promotion + filière */
    $pCols=egCols($pdo,'promotions');
    if(!isset($pCols['filiere_id']))jsonResponse(false,'La promotion ne possède pas de filière.',[],422);
    $sql="SELECT id,filiere_id".(isset($pCols['option_specialite_id'])?',option_specialite_id':'')." FROM promotions WHERE id=?";
    $params=[$promotionId];
    if(isset($pCols['etablissement_id'])){$sql.=" AND etablissement_id=?";$params[]=$eid;}
    $s=$pdo->prepare($sql.' LIMIT 1');$s->execute($params);$promotion=$s->fetch(PDO::FETCH_ASSOC);
    if(!$promotion)jsonResponse(false,'Promotion introuvable dans votre établissement.',[],404);
    $filiereId=(int)$promotion['filiere_id'];
    if(!$filiereId)jsonResponse(false,'Associez d’abord cette promotion à une filière.',[],422);
    $optionId=isset($promotion['option_specialite_id'])?(int)$promotion['option_specialite_id']:null;

    if(!egEnsureOwner($pdo,'stage_types',$stageTypeId,$eid))
        jsonResponse(false,'Type de stage invalide.',[],422);

    /* Année académique */
    $aCols=egCols($pdo,'annees_academiques');
    $sql="SELECT 1 FROM annees_academiques WHERE id=?";$params=[$yearId];
    if(isset($aCols['etablissement_id'])){$sql.=" AND (etablissement_id=? OR etablissement_id IS NULL)";$params[]=$eid;}
    $s=$pdo->prepare($sql.' LIMIT 1');$s->execute($params);
    if(!$s->fetchColumn())jsonResponse(false,'Année académique invalide.',[],422);

    /* Normalisation des critères */
    $clean=[];$order=0;
    $allowedCategories=['CLINIQUE','TECHNIQUE','COMMUNICATION','ETHIQUE','PROFESSIONNALISME','ORGANISATION'];

    /* Les critères sélectionnés sont nettoyés ; une compétence locale est créée si aucun identifiant n'est fourni. */
    foreach($criteria as $x){
        $selected=!empty($x['selected']);
        if(!$selected)continue;

        $cid=(int)($x['competency_id']??0);
        $name=trim((string)($x['nom']??''));
        $cat=egCategory((string)($x['categorie']??'CLINIQUE'));
        $section=trim((string)($x['section']??''));
        $max=(float)($x['note_max']??0);
        $required=!empty($x['obligatoire'])?1:0;

        if($max<=0||$max>100)throw new RuntimeException('Chaque note maximale doit être comprise entre 0,01 et 100.');

        if($cid){
            $s=$pdo->prepare("
                SELECT id
                FROM stage_competencies
                WHERE id=? AND actif=1
                  AND (stage_type_id IS NULL OR stage_type_id=?)
                  AND (etablissement_id IS NULL OR etablissement_id=?)
                LIMIT 1
            ");
            $s->execute([$cid,$stageTypeId,$eid]);
            if(!$s->fetchColumn())throw new RuntimeException('Un critère sélectionné est invalide.');
        }else{
            if($name==='')throw new RuntimeException('Le nom du nouveau critère est obligatoire.');
            $code='LOC-'.$eid.'-'.$stageTypeId.'-'.strtoupper(substr(bin2hex(random_bytes(5)),0,10));
            $s=$pdo->prepare("
                INSERT INTO stage_competencies(
                    etablissement_id,code,nom,categorie,description,stage_type_id,poids_default,actif,created_at,updated_at
                ) VALUES(?,?,?,?,NULL,?,1,1,NOW(),NOW())
            ");
            $s->execute([$eid,$code,$name,$cat,$stageTypeId]);
            $cid=(int)$pdo->lastInsertId();
        }

        $clean[]=[
            'id'=>$cid,'max'=>$max,'poids'=>$max,'required'=>$required,
            'section'=>$section!==''?$section:null,'order'=>++$order
        ];
    }

    if(!$clean)jsonResponse(false,'Sélectionnez au moins un critère.',[],422);

    $total=array_sum(array_column($clean,'max'));
    if($total<=0)jsonResponse(false,'Le total du barème doit être supérieur à zéro.',[],422);

    if($label==='')$label='Grille d’évaluation — promotion '.$promotionId;

    /* Référentiel, compétences et configuration de promotion sont enregistrés atomiquement. */
    $pdo->beginTransaction();

    /* Configuration promotion existante */
    $s=$pdo->prepare("
        SELECT *
        FROM promotion_stage_configs
        WHERE etablissement_id=? AND promotion_id=? AND annee_academique_id=? AND stage_type_id=?
        ORDER BY id DESC LIMIT 1 FOR UPDATE
    ");
    $s->execute([$eid,$promotionId,$yearId,$stageTypeId]);
    $cfg=$s->fetch(PDO::FETCH_ASSOC)?:null;
    $oldRef=(int)($cfg['referential_id']??0);

    /* Si le référentiel a déjà servi dans une évaluation, on versionne au lieu de l'écraser. */
    /* Un référentiel déjà utilisé par une évaluation est cloné afin de ne jamais modifier l'historique. */
    $mustClone=false;
    if($oldRef){
        $s=$pdo->prepare("SELECT COUNT(*) FROM stage_evaluations WHERE referential_id=?");
        $s->execute([$oldRef]);$mustClone=(int)$s->fetchColumn()>0;
    }

    $refId=$oldRef;
    $version='v1';

    if($oldRef){
        $s=$pdo->prepare("SELECT version FROM stage_referentials WHERE id=? AND etablissement_id=? LIMIT 1");
        $s->execute([$oldRef,$eid]);$oldVersion=(string)($s->fetchColumn()?:'v1');
        if(preg_match('/(\d+)/',$oldVersion,$m))$version='v'.((int)$m[1]+($mustClone?1:0));
    }

    $configuration=json_encode([
        'mode'=>'POINTS',
        'total_points'=>$total,
        'promotion_id'=>$promotionId,
        'annee_academique_id'=>$yearId,
        'stage_type_id'=>$stageTypeId,
        'generated_from'=>'evaluation_grid_admin'
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    /* Création d'une nouvelle version ou mise à jour du référentiel encore non utilisé. */
    if(!$refId||$mustClone){
        $code='REF-P'.$promotionId.'-T'.$stageTypeId.'-'.date('ymdHis').'-'.strtoupper(substr(bin2hex(random_bytes(2)),0,4));
        $s=$pdo->prepare("
            INSERT INTO stage_referentials(
                uuid,etablissement_id,filiere_id,option_specialite_id,stage_type_id,
                code,libelle,version,description,statut,configuration,created_by_user_id,created_at,updated_at
            ) VALUES(?,?,?,?,?,?,?,?,NULL,'ACTIF',?,?,NOW(),NOW())
        ");
        $s->execute([
            egUuid(),$eid,$filiereId,$optionId?:null,$stageTypeId,
            $code,$label,$version,$configuration,$uid
        ]);
        $refId=(int)$pdo->lastInsertId();
    }else{
        $s=$pdo->prepare("
            UPDATE stage_referentials
            SET filiere_id=?,option_specialite_id=?,stage_type_id=?,libelle=?,statut='ACTIF',
                configuration=?,updated_at=NOW()
            WHERE id=? AND etablissement_id=?
        ");
        $s->execute([$filiereId,$optionId?:null,$stageTypeId,$label,$configuration,$refId,$eid]);
        $pdo->prepare("DELETE FROM stage_referential_competencies WHERE referential_id=?")->execute([$refId]);
    }

    $ins=$pdo->prepare("
        INSERT INTO stage_referential_competencies(
            referential_id,competency_id,poids,note_max,section,obligatoire,ordre,created_at
        ) VALUES(?,?,?,?,?,?,?,NOW())
    ");
    /* Les critères normalisés sont insérés avec leur barème, poids, section et ordre. */
    foreach($clean as $c)
        $ins->execute([$refId,$c['id'],$c['poids'],$c['max'],$c['section'],$c['required'],$c['order']]);

    /* La configuration promotion-année-type est créée ou réorientée vers le référentiel final. */
    if($cfg){
        $s=$pdo->prepare("
            UPDATE promotion_stage_configs
            SET referential_id=?,actif=1,updated_at=NOW()
            WHERE id=? AND etablissement_id=?
        ");
        $s->execute([$refId,(int)$cfg['id'],$eid]);
        $configId=(int)$cfg['id'];
    }else{
        $s=$pdo->prepare("
            INSERT INTO promotion_stage_configs(
                uuid,etablissement_id,promotion_id,annee_academique_id,stage_type_id,
                referential_id,actif,created_by_user_id,created_at,updated_at
            ) VALUES(?,?,?,?,?,?,1,?,NOW(),NOW())
        ");
        $s->execute([egUuid(),$eid,$promotionId,$yearId,$stageTypeId,$refId,$uid]);
        $configId=(int)$pdo->lastInsertId();
    }

    $pdo->commit();

    jsonResponse(true,'Grille enregistrée avec succès.',[
        'config_id'=>$configId,
        'referential_id'=>$refId,
        'total_points'=>$total,
        'criteria_count'=>count($clean),
        'versioned'=>$mustClone?1:0
    ]);
}catch(Throwable $e){
    /* Tout incident annule le référentiel et sa configuration afin d'éviter une grille partielle. */
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],422);
}

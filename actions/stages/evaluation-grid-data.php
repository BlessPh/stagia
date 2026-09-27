<?php
/**
 * Endpoint AJAX de préparation de l'écran d'administration des grilles d'évaluation.
 * Il adapte ses lectures au schéma disponible et retourne promotions, référentiels, compétences et configurations.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

/** Mémorise les colonnes d'une table pour supporter les variantes de migration du schéma. */
function egCols(PDO $pdo,string $table):array{
    static $cache=[];
    if(isset($cache[$table]))return $cache[$table];
    try{
        $rows=$pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        $out=[];foreach($rows as $r)$out[$r['Field']]=$r;
        return $cache[$table]=$out;
    }catch(Throwable $e){return $cache[$table]=[];}
}
function egHas(PDO $pdo,string $table,string $col):bool{return isset(egCols($pdo,$table)[$col]);}
/** Construit une expression SQL de libellé à partir de la première colonne disponible. */
function egLabelExpr(PDO $pdo,string $table,string $alias,array $cols,string $fallback):string{
    $x=[];foreach($cols as $c)if(egHas($pdo,$table,$c))$x[]="NULLIF(TRIM($alias.`$c`),'')";
    return $x?'COALESCE('.implode(',',$x).','.$fallback.')':$fallback;
}
/** Charge un référentiel simple, filtré par activité et par établissement lorsque ces colonnes existent. */
function egRows(PDO $pdo,string $table,string $alias,string $labelExpr,int $eid,string $order='label'):array{
    $w=[];$p=[];
    if(egHas($pdo,$table,'actif'))$w[]="$alias.actif=1";
    if(egHas($pdo,$table,'etablissement_id')){$w[]="($alias.etablissement_id=? OR $alias.etablissement_id IS NULL)";$p[]=$eid;}
    $sql="SELECT $alias.id,$labelExpr label FROM `$table` $alias".($w?' WHERE '.implode(' AND ',$w):'')." ORDER BY $order";
    $s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);
}
/** Vérifie que les colonnes requises par les grilles versionnées ont été migrées. */
function egMigrationReady(PDO $pdo):bool{
    return egHas($pdo,'stage_referential_competencies','note_max')
        && egHas($pdo,'stage_referential_competencies','section')
        && egHas($pdo,'stage_competencies','etablissement_id');
}

try{
    /* Promotions, années et types servent de filtres avant de charger la grille sélectionnée. */
    $eid=(int)currentEtablissementId($pdo);
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    $promoLabel=egLabelExpr($pdo,'promotions','p',['nom','libelle','code','niveau'],"CONCAT('Promotion #',p.id)");
    $yearLabel=egLabelExpr($pdo,'annees_academiques','a',['libelle','nom','code','annee'],"CONCAT('Année #',a.id)");
    $typeLabel=egLabelExpr($pdo,'stage_types','st',['libelle','nom','code'],"CONCAT('Type #',st.id)");

    /* Promotions : priorité au périmètre établissement, sinon filière de l'établissement. */
    $promotions=[];
    $promoCols=egCols($pdo,'promotions');
    if($promoCols){
        $where=[];$params=[];
        $join='';
        if(isset($promoCols['actif']))$where[]='p.actif=1';
        if(isset($promoCols['etablissement_id'])){
            $where[]='p.etablissement_id=?';$params[]=$eid;
        }elseif(isset($promoCols['filiere_id'])&&egHas($pdo,'filieres','etablissement_id')){
            $join=' JOIN filieres f ON f.id=p.filiere_id';
            $where[]='f.etablissement_id=?';$params[]=$eid;
        }
        $select="p.id,$promoLabel label".(isset($promoCols['filiere_id'])?',p.filiere_id':'');
        if(isset($promoCols['option_specialite_id']))$select.=',p.option_specialite_id';
        $s=$pdo->prepare("SELECT $select FROM promotions p$join".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY label");
        $s->execute($params);$promotions=$s->fetchAll(PDO::FETCH_ASSOC);
    }

    $years=egRows($pdo,'annees_academiques','a',$yearLabel,$eid,'a.id DESC');
    $types=egRows($pdo,'stage_types','st',$typeLabel,$eid,'label');

    $promotionId=(int)($_GET['promotion_id']??0);
    $yearId=(int)($_GET['annee_academique_id']??0);
    $stageTypeId=(int)($_GET['stage_type_id']??0);

    $config=null;$referential=null;$criteria=[];

    /* Les critères sont détaillés seulement pour une configuration complète promotion-année-type. */
    if($promotionId&&$yearId&&$stageTypeId){
        $s=$pdo->prepare("
            SELECT *
            FROM promotion_stage_configs
            WHERE etablissement_id=? AND promotion_id=? AND annee_academique_id=? AND stage_type_id=?
            ORDER BY actif DESC,id DESC LIMIT 1
        ");
        $s->execute([$eid,$promotionId,$yearId,$stageTypeId]);
        $config=$s->fetch(PDO::FETCH_ASSOC)?:null;

        $refId=(int)($config['referential_id']??0);
        if($refId){
            $s=$pdo->prepare("SELECT id,code,libelle,version,statut,configuration FROM stage_referentials WHERE id=? AND etablissement_id=? LIMIT 1");
            $s->execute([$refId,$eid]);$referential=$s->fetch(PDO::FETCH_ASSOC)?:null;
        }

        $hasLocal=egHas($pdo,'stage_competencies','etablissement_id');
        $hasNote=egHas($pdo,'stage_referential_competencies','note_max');
        $hasSection=egHas($pdo,'stage_referential_competencies','section');

        $sql="
            SELECT c.id,c.code,c.nom,c.categorie,c.description,c.stage_type_id,
                   ".($hasLocal?'c.etablissement_id':'NULL')." etablissement_id,
                   ".($refId?'IF(rc.competency_id IS NULL,0,1)':'0')." selected,
                   ".($refId?'COALESCE(rc.poids,c.poids_default,1)':'COALESCE(c.poids_default,1)')." poids,
                   ".($refId&&$hasNote?'COALESCE(rc.note_max,5)':'5')." note_max,
                   ".($refId&&$hasSection?'COALESCE(rc.section,\'\')':"''")." section,
                   ".($refId?'COALESCE(rc.obligatoire,1)':'1')." obligatoire,
                   ".($refId?'COALESCE(rc.ordre,0)':'0')." ordre
            FROM stage_competencies c
            ".($refId?"LEFT JOIN stage_referential_competencies rc ON rc.competency_id=c.id AND rc.referential_id=".intval($refId):"")."
            WHERE c.actif=1
              AND (c.stage_type_id IS NULL OR c.stage_type_id=?)
              ".($hasLocal?"AND (c.etablissement_id IS NULL OR c.etablissement_id=?)":"")."
            ORDER BY selected DESC,ordre,c.categorie,c.nom
        ";
        $s=$pdo->prepare($sql);
        $params=[$stageTypeId];if($hasLocal)$params[]=$eid;
        $s->execute($params);$criteria=$s->fetchAll(PDO::FETCH_ASSOC);
    }

    /* Sources possibles pour « Copier depuis ». */
    /* Les configurations existantes avec référentiel servent de sources à la fonction de copie. */
    $s=$pdo->prepare("
        SELECT id,promotion_id,annee_academique_id,stage_type_id,referential_id
        FROM promotion_stage_configs
        WHERE etablissement_id=? AND actif=1 AND referential_id IS NOT NULL
        ORDER BY id DESC LIMIT 100
    ");
    $s->execute([$eid]);$sources=$s->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'migration_ready'=>egMigrationReady($pdo),
        'promotions'=>$promotions,
        'years'=>$years,
        'stage_types'=>$types,
        'config'=>$config,
        'referential'=>$referential,
        'criteria'=>$criteria,
        'sources'=>$sources
    ]);
}catch(Throwable $e){
    error_log('[EVALUATION GRID DATA] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur chargement grilles : '.$e->getMessage(),[],500);
}

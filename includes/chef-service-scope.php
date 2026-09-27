<?php
/** Helpers de périmètre : limitent un chef de service aux unités qui lui sont affectées. */
if(!function_exists('chefServiceRoleCodes')){
/** Liste des codes de rôle assimilés à un responsable de service. */
function chefServiceRoleCodes():array{
    return ['CHEF_SERVICE','ENCADREUR_CHEF_SERVICE','RESPONSABLE_SERVICE'];
}}

if(!function_exists('chefServiceColumnExists')){
/** Vérifie et mémorise l'existence d'une colonne afin de gérer les schémas historiques. */
function chefServiceColumnExists(PDO $pdo,string $table,string $column):bool{
    static $cache=[];$key=$table.'.'.$column;
    if(array_key_exists($key,$cache))return $cache[$key];
    try{$s=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");$s->execute([$column]);return $cache[$key]=(bool)$s->fetchColumn();}
    catch(Throwable $e){return $cache[$key]=false;}
}}

if(!function_exists('chefServiceRoleCodeColumn')){
/** Choisit le nom de colonne de code rôle compatible avec le schéma détecté. */
function chefServiceRoleCodeColumn(PDO $pdo):string{
    return chefServiceColumnExists($pdo,'roles','code')?'code':'role_code';
}}

if(!function_exists('chefServiceDescendantUnitIds')){
/** Parcourt la hiérarchie des unités pour inclure tous les descendants des racines affectées. */
function chefServiceDescendantUnitIds(PDO $pdo,int $hostId,array $rootIds,bool $includeRoots=true):array{
    $rootIds=array_values(array_unique(array_filter(array_map('intval',$rootIds),fn($v)=>$v>0)));
    if(!$rootIds)return [];

    $seen=$includeRoots?array_fill_keys($rootIds,true):[];
    $frontier=$rootIds;

    try{
        while($frontier){
            $in=implode(',',array_fill(0,count($frontier),'?'));
            $s=$pdo->prepare("SELECT id FROM host_units WHERE host_etablissement_id=? AND actif=1 AND parent_id IN($in)");
            $s->execute(array_merge([$hostId],$frontier));
            $next=[];
            foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){
                $id=(int)$id;
                if($id>0 && empty($seen[$id])){$seen[$id]=true;$next[]=$id;}
            }
            $frontier=$next;
        }
    }catch(Throwable $e){error_log('[CHEF SERVICE DESCENDANTS] '.$e->getMessage());}

    return array_keys($seen);
}}

if(!function_exists('chefServiceAllUnitIds')){
/** Retourne toutes les unités opérationnelles actives de la structure d'accueil. */
function chefServiceAllUnitIds(PDO $pdo,int $hostId):array{
    try{
        $s=$pdo->prepare("SELECT id FROM host_units WHERE host_etablissement_id=? AND actif=1 AND UPPER(type) IN('SERVICE','UNITE','UNITÉ','COORDINATION','DEPARTEMENT','DÉPARTEMENT','DEPARTMENT','DIRECTION')");
        $s->execute([$hostId]);
        return array_values(array_unique(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN))));
    }catch(Throwable $e){error_log('[CHEF SERVICE ALL UNITS] '.$e->getMessage());return [];}
}}

if(!function_exists('chefServiceScope')){
/** Construit le périmètre complet : accès global ou liste d'unités autorisées et leurs libellés. */
function chefServiceScope(PDO $pdo,int $hostId,int $userId):array{
    $all=false;$rootIds=[];$labels=[];

    try{
        $codes=chefServiceRoleCodes();$in=implode(',',array_fill(0,count($codes),'?'));
        $codeCol=chefServiceRoleCodeColumn($pdo);
        $revoked=chefServiceColumnExists($pdo,'role_assignments','revoked_at')?' AND ra.revoked_at IS NULL':'';
        $s=$pdo->prepare("SELECT ra.scope_type,ra.scope_entity,ra.scope_id
            FROM role_assignments ra
            JOIN roles r ON r.id=ra.role_id
            WHERE ra.user_id=? AND r.`$codeCol` IN($in) AND ra.actif=1 $revoked
              AND (ra.etablissement_id=? OR ra.etablissement_id IS NULL)
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())");
        $s->execute(array_merge([$userId],$codes,[$hostId]));
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
            if(($r['scope_type']??'')==='ORGANIZATION')$all=true;
            if(($r['scope_type']??'')==='UNIT'&&(int)($r['scope_id']??0)>0)$rootIds[]=(int)$r['scope_id'];
        }
    }catch(Throwable $e){error_log('[CHEF SERVICE SCOPE] '.$e->getMessage());}

    if($all)return ['all'=>true,'ids'=>[],'root_ids'=>[],'labels'=>['Tout l’établissement']];

    $rootIds=array_values(array_unique(array_filter($rootIds,fn($v)=>$v>0)));
    if(!$rootIds)return ['all'=>false,'ids'=>[],'root_ids'=>[],'labels'=>['Aucun département / coordination lié']];

    try{
        $in=implode(',',array_fill(0,count($rootIds),'?'));
        $s=$pdo->prepare("SELECT id,nom,type FROM host_units WHERE host_etablissement_id=? AND actif=1 AND id IN($in)");
        $s->execute(array_merge([$hostId],$rootIds));
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $u)$labels[]=$u['nom'].' · '.$u['type'];
    }catch(Throwable $e){error_log('[CHEF SERVICE ROOT LABELS] '.$e->getMessage());}

    $ids=chefServiceDescendantUnitIds($pdo,$hostId,$rootIds,true);
    return ['all'=>false,'ids'=>$ids,'root_ids'=>$rootIds,'labels'=>$labels?:['Périmètre limité']];
}}

if(!function_exists('chefServiceWhere')){
/** Ajoute la clause SQL de périmètre et ses paramètres, ou bloque toute ligne hors périmètre. */
function chefServiceWhere(string $alias,array $scope,array &$params):string{
    if(!empty($scope['all']))return '';
    $ids=array_values(array_unique(array_map('intval',$scope['ids']??[])));
    if(!$ids)return " AND 1=0";
    $params=array_merge($params,$ids);
    return " AND $alias.host_unit_id IN(".implode(',',array_fill(0,count($ids),'?')).")";
}}

if(!function_exists('chefServiceHasScope')){
/** Indique si le responsable dispose d'un accès global ou d'au moins une unité. */
function chefServiceHasScope(PDO $pdo,int $hostId,int $userId):bool{
    $s=chefServiceScope($pdo,$hostId,$userId);
    return !empty($s['all'])||!empty($s['ids']);
}}

if(!function_exists('chefServiceStagiaireCount')){
/** Compte les stagiaires actifs, avec une dérogation globale pour certains rôles administratifs. */
function chefServiceStagiaireCount(PDO $pdo,int $hostId,int $userId,bool $adminAll=false):int{
    try{
        $role=strtoupper(trim((string)($_SESSION['role_code']??'')));
        if($adminAll&&in_array($role,['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE'],true)){
            $s=$pdo->prepare("SELECT COUNT(*) FROM stage_assignments WHERE host_etablissement_id=? AND statut IN('PLANIFIEE','ACTIVE')");
            $s->execute([$hostId]);return (int)$s->fetchColumn();
        }
        $scope=chefServiceScope($pdo,$hostId,$userId);
        $params=[$hostId];$where=chefServiceWhere('a',$scope,$params);
        $s=$pdo->prepare("SELECT COUNT(DISTINCT a.id)
            FROM stage_assignments a
            LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
            WHERE a.host_etablissement_id=? $where
              AND a.statut IN('PLANIFIEE','ACTIVE')
              AND (ad.id IS NULL OR ad.statut IN('ADMIS','EN_COURS'))");
        $s->execute($params);return (int)$s->fetchColumn();
    }catch(Throwable $e){error_log('[CHEF SERVICE COUNT] '.$e->getMessage());return 0;}
}}

if(!function_exists('chefServiceUnitIds')){
/** Retourne les unités visibles par le responsable connecté. */
function chefServiceUnitIds(PDO $pdo,int $hostId,int $userId,bool $adminAll=false):array{
    try{
        $role=strtoupper(trim((string)($_SESSION['role_code']??'')));
        if($adminAll&&in_array($role,['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE'],true))return chefServiceAllUnitIds($pdo,$hostId);
        $scope=chefServiceScope($pdo,$hostId,$userId);
        if(!empty($scope['all']))return chefServiceAllUnitIds($pdo,$hostId);
        return array_values(array_unique(array_map('intval',$scope['ids']??[])));
    }catch(Throwable $e){error_log('[CHEF SERVICE UNIT IDS] '.$e->getMessage());return [];}
}}

<?php
/**
 * Endpoint AJAX de liste hiérarchique des services et unités d'accueil.
 * Les résultats sont limités au périmètre organisationnel accordé à l'utilisateur connecté.
 */
ini_set('display_errors','0');
if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
ob_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/** Envoie une réponse JSON sans sortie PHP parasite. */
function sendUnits(bool $ok,string $msg='',array $data=[],int $code=200):void{
    while(ob_get_level()>0)ob_end_clean();
    http_response_code($code);
    echo json_encode(['success'=>$ok,'message'=>$msg,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
/** Mémorise les colonnes disponibles afin de rester compatible avec les variantes de schéma. */
function unitCols(PDO $pdo,string $t):array{
    static $c=[]; if(isset($c[$t]))return $c[$t];
    try{$r=$pdo->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);$x=[];foreach($r as $v)$x[$v['Field']]=true;return $c[$t]=$x;}
    catch(Throwable $e){return $c[$t]=[];}
}
/** Construit l'expression de rôle compatible avec les anciennes et nouvelles colonnes. */
function unitRoleExpr(PDO $pdo):string{
    $c=unitCols($pdo,'roles');
    if(isset($c['code'],$c['role_code']))return "COALESCE(NULLIF(r.code,''),NULLIF(r.role_code,''))";
    if(isset($c['code']))return 'r.code';
    if(isset($c['role_code']))return 'r.role_code';
    return "''";
}
/** Retourne les unités accessibles, y compris les descendants hiérarchiques autorisés. */
function unitScopeIds(PDO $pdo,int $hostId,int $userId,string $roleUpper):?array{
    if($roleUpper==='ADMIN_ACCUEIL')return null;
    $codes=[];
    if($roleUpper==='COORDINATEUR_STAGES')$codes=['COORDINATEUR_STAGES'];
    elseif($roleUpper==='CHEF_SERVICE'||preg_match('/ROLE_CHEF_SERVICE$/',$roleUpper))$codes=['CHEF_SERVICE','ENCADREUR_CHEF_SERVICE','RESPONSABLE_SERVICE'];
    else return null;

    $expr=unitRoleExpr($pdo);$in=implode(',',array_fill(0,count($codes),'?'));
    $s=$pdo->prepare("
        SELECT DISTINCT ra.scope_id
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=?
          AND $expr IN($in)
          AND ra.scope_type='UNIT'
          AND ra.scope_id IS NOT NULL
          AND ra.scope_id>0
          AND ra.actif=1
          AND (ra.revoked_at IS NULL)
          AND (ra.etablissement_id=? OR ra.etablissement_id IS NULL)
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
    ");
    $s->execute(array_merge([$userId],$codes,[$hostId]));
    $ids=array_values(array_unique(array_filter(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)))));
    if(!$ids)return [];

    $all=$ids;
    $front=$ids;
    for($i=0;$i<4 && $front;$i++){
        $inIds=implode(',',array_fill(0,count($front),'?'));
        $s=$pdo->prepare("SELECT id FROM host_units WHERE host_etablissement_id=? AND actif=1 AND parent_id IN($inIds)");
        $s->execute(array_merge([$hostId],$front));
        $children=array_values(array_diff(array_unique(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN))),$all));
        $all=array_values(array_unique(array_merge($all,$children)));
        $front=$children;
    }
    return $all;
}

try{
    /* La liste est filtrée par établissement puis, si nécessaire, par unités affectées au rôle. */
    requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','ENCADREUR','EVALUATEUR_CLINIQUE']);
    $hostId=currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);
    if(!$hostId)sendUnits(false,'Aucun établissement associé.',[],403);

    $roleUpper=strtoupper(trim((string)($_SESSION['role_code']??'')));
    $scope=unitScopeIds($pdo,$hostId,$userId,$roleUpper);
    $where=['host_etablissement_id=?'];$params=[$hostId];
    if(is_array($scope)){
        if(!$scope)$where[]='1=0';
        else{$where[]='id IN('.implode(',',array_fill(0,count($scope),'?')).')';$params=array_merge($params,$scope);}    
    }
    /* Le tri préserve l'ordre parent puis enfant pour un affichage hiérarchique côté interface. */
    $s=$pdo->prepare("SELECT id,code,nom,type,parent_id,capacite,actif FROM host_units WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(parent_id,id),parent_id IS NOT NULL,nom");
    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$x){foreach(['id','parent_id','capacite','actif'] as $f)$x[$f]=$x[$f]!==null?(int)$x[$f]:null;}unset($x);
    sendUnits(true,'',['items'=>$items]);
}catch(Throwable $e){
    error_log('[HOST UNIT LIST CHEF] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    sendUnits(false,'Erreur chargement services : '.$e->getMessage(),[],500);
}

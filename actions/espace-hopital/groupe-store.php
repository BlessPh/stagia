<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

function gsUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

function gsCols(PDO $pdo,string $table):array{
    static $cache=[];
    if(isset($cache[$table]))return $cache[$table];
    $cache[$table]=[];
    foreach($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $cache[$table][$r['Field']]=$r;
    return $cache[$table];
}

function gsHas(PDO $pdo,string $table,string $col):bool{
    return isset(gsCols($pdo,$table)[$col]);
}

function gsAdd(PDO $pdo,string $table,string $col,$val,array &$cols,array &$marks,array &$vals):void{
    if(!gsHas($pdo,$table,$col))return;
    $cols[]="`$col`";
    $marks[]='?';
    $vals[]=$val;
}

function gsChefUnits(PDO $pdo,int $uid,int $eid,string $role):?array{
    if($role!=='CHEF_SERVICE')return null;

    $ids=[];$all=false;
    $s=$pdo->prepare("\n        SELECT ra.scope_type,ra.scope_id\n        FROM role_assignments ra\n        JOIN roles r ON r.id=ra.role_id\n        WHERE ra.user_id=? AND ra.etablissement_id=?\n          AND r.code='CHEF_SERVICE'\n          AND ra.actif=1\n          AND ra.revoked_at IS NULL\n          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())\n          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())\n    ");
    $s->execute([$uid,$eid]);

    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
        if($r['scope_type']==='ORGANIZATION')$all=true;
        if($r['scope_type']==='UNIT'&&(int)$r['scope_id']>0)$ids[]=(int)$r['scope_id'];
    }

    if($all)return null;
    if(!$ids)return [-1];

    $allIds=$ids;
    for($i=0;$i<3;$i++){
        $in=implode(',',array_fill(0,count($allIds),'?'));
        $s=$pdo->prepare("SELECT id FROM host_units WHERE host_etablissement_id=? AND actif=1 AND parent_id IN($in)");
        $s->execute(array_merge([$eid],$allIds));
        $new=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        $before=count($allIds);
        $allIds=array_values(array_unique(array_merge($allIds,$new)));
        if(count($allIds)===$before)break;
    }

    return $allIds;
}

function gsCode(PDO $pdo,int $eid,int $campaignId,int $promotionId):string{
    for($i=1;$i<=50;$i++){
        $code='GRP-'.str_pad((string)$campaignId,4,'0',STR_PAD_LEFT).'-'.str_pad((string)$promotionId,3,'0',STR_PAD_LEFT).'-'.str_pad((string)$i,2,'0',STR_PAD_LEFT);
        $s=$pdo->prepare("SELECT 1 FROM stage_groups WHERE host_etablissement_id=? AND campaign_id=? AND promotion_id=? AND code=? LIMIT 1");
        $s->execute([$eid,$campaignId,$promotionId,$code]);
        if(!$s->fetchColumn())return $code;
    }
    return 'GRP-'.date('ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));
}

try{
    $csrf=(string)($_POST['csrf']??'');
    if(empty($_SESSION['csrf'])||!$csrf||!hash_equals((string)$_SESSION['csrf'],$csrf))
        jsonResponse(false,'Jeton de sécurité invalide.',[],419);

    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $role=$_SESSION['role_code']??'';

    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);
    if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

    $can=$role==='CHEF_SERVICE'||(function_exists('hasPermission')&&hasPermission($pdo,'group.hosting.manage'))||(function_exists('contextPermission')&&contextPermission('group.hosting.manage'));
    if(!$can)jsonResponse(false,"Vous n'avez pas l'autorisation de créer un groupe.",[],403);

    $campaignId=(int)($_POST['campaign_id']??0);
    $promotionId=(int)($_POST['promotion_id']??0);
    $nom=trim((string)($_POST['nom']??''));
    $capacite=trim((string)($_POST['capacite']??''));
    $description=trim((string)($_POST['description']??''));

    if(!$campaignId||!$promotionId)jsonResponse(false,'Campagne et promotion obligatoires.',[],422);
    if($nom==='')jsonResponse(false,'Le nom du groupe est obligatoire.',[],422);
    if(mb_strlen($nom)>150)jsonResponse(false,'Le nom du groupe est trop long.',[],422);

    $capacite=$capacite===''?null:(int)$capacite;
    if($capacite!==null&&$capacite<1)jsonResponse(false,'La capacité doit être supérieure à zéro.',[],422);

    $unitIds=gsChefUnits($pdo,$uid,$eid,$role);

    $where=["ad.host_etablissement_id=?","ad.statut IN('ADMIS','EN_COURS')"];
    $params=[$eid];
    if(is_array($unitIds)){
        $where[]='sa.host_unit_id IN('.implode(',',array_fill(0,count($unitIds),'?')).')';
        $params=array_merge($params,$unitIds);
    }

    $s=$pdo->prepare("\n        SELECT COUNT(DISTINCT ae.id)\n        FROM stage_admissions ad\n        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'\n        JOIN stage_applications app ON app.id=sr.application_id AND app.campaign_id=? AND app.host_etablissement_id=?\n        JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id AND ae.promotion_id=?\n        JOIN stage_assignments sa ON sa.admission_id=ad.id AND sa.host_etablissement_id=? AND sa.statut IN('PLANIFIEE','ACTIVE')\n        WHERE ".implode(' AND ',$where)."\n    ");
    $s->execute(array_merge([$campaignId,$eid,$promotionId,$eid],$params));
    $available=(int)$s->fetchColumn();

    if($available<1)jsonResponse(false,'Aucun stagiaire admis/affecté pour cette campagne, cette promotion et votre périmètre.',[],422);
    if($capacite!==null&&$capacite>$available)jsonResponse(false,'La capacité ne peut pas dépasser les stagiaires disponibles dans ce périmètre.',[],422);

    $s=$pdo->prepare("\n        SELECT 1\n        FROM stage_groups\n        WHERE host_etablissement_id=?\n          AND campaign_id=?\n          AND promotion_id=?\n          AND UPPER(TRIM(nom))=UPPER(TRIM(?))\n          AND statut<>'ANNULE'\n        LIMIT 1\n    ");
    $s->execute([$eid,$campaignId,$promotionId,$nom]);
    if($s->fetchColumn())jsonResponse(false,'Un groupe avec ce nom existe déjà pour cette campagne et cette promotion.',[],422);

    $code=gsCode($pdo,$eid,$campaignId,$promotionId);
    $cols=[];$marks=[];$vals=[];$table='stage_groups';

    gsAdd($pdo,$table,'uuid',gsUuid(),$cols,$marks,$vals);
    gsAdd($pdo,$table,'code',$code,$cols,$marks,$vals);
    gsAdd($pdo,$table,'campaign_id',$campaignId,$cols,$marks,$vals);
    gsAdd($pdo,$table,'host_etablissement_id',$eid,$cols,$marks,$vals);
    gsAdd($pdo,$table,'promotion_id',$promotionId,$cols,$marks,$vals);
    gsAdd($pdo,$table,'nom',$nom,$cols,$marks,$vals);
    gsAdd($pdo,$table,'capacite',$capacite,$cols,$marks,$vals);
    gsAdd($pdo,$table,'description',$description?:null,$cols,$marks,$vals);
    gsAdd($pdo,$table,'statut','BROUILLON',$cols,$marks,$vals);
    gsAdd($pdo,$table,'created_by',$uid?:null,$cols,$marks,$vals);
    gsAdd($pdo,$table,'created_at',date('Y-m-d H:i:s'),$cols,$marks,$vals);
    gsAdd($pdo,$table,'updated_at',date('Y-m-d H:i:s'),$cols,$marks,$vals);

    $pdo->prepare("INSERT INTO stage_groups(".implode(',',$cols).") VALUES(".implode(',',$marks).")")->execute($vals);
    $id=(int)$pdo->lastInsertId();

    jsonResponse(true,'Groupe créé.',['id'=>$id,'code'=>$code,'available'=>$available]);
}catch(Throwable $e){
    error_log('[GROUPE STORE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur Groupe : '.$e->getMessage(),[],500);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

function failJson(string $m,int $c=422):void{jsonResponse(false,$m,[],$c);}
function uid():int{return (int)($_SESSION['user_id']??0);}
function roleCode():string{return (string)($_SESSION['role_code']??'');}
function uuidv4():string{return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));}
function cols(PDO $pdo,string $t):array{$s=$pdo->query("SHOW COLUMNS FROM `$t`");return array_fill_keys(array_column($s->fetchAll(PDO::FETCH_ASSOC),'Field'),true);}
function addCol(array &$c,array &$v,array &$p,string $col,$val,bool $raw=false):void{$c[]="`$col`";$v[]=$raw?$val:'?';if(!$raw)$p[]=$val;}
function chefUnits(PDO $pdo,int $uid,int $eid,string $role):?array{
    if($role!=='CHEF_SERVICE')return null;
    $ids=[];$all=false;
    $s=$pdo->prepare("SELECT ra.scope_type,ra.scope_id FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.user_id=? AND ra.etablissement_id=? AND r.code='CHEF_SERVICE' AND ra.actif=1 AND ra.revoked_at IS NULL AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())");
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
        $n=count($allIds);$allIds=array_values(array_unique(array_merge($allIds,$new)));
        if(count($allIds)===$n)break;
    }
    return $allIds;
}

try{
    if(function_exists('requireAjaxRole'))requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE']);
    else requireRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE']);

    if(empty($_SESSION['csrf'])||empty($_POST['csrf'])||!hash_equals($_SESSION['csrf'],(string)$_POST['csrf']))
        failJson('Jeton de sécurité invalide.',419);

    if(function_exists('contextHostEnabled')&&!contextHostEnabled())
        failJson("Cet établissement n'est pas une structure d'accueil.",403);

    $eid=(int)currentEtablissementId($pdo);
    $uid=uid();
    $role=roleCode();
    if(!$eid)failJson('Aucun établissement associé.',403);
    if($role!=='CHEF_SERVICE'&&function_exists('hasPermission')&&!hasPermission($pdo,'group.hosting.manage'))
        failJson('Permission insuffisante.',403);

    $groupId=(int)($_POST['group_id']??0);
    $ids=$_POST['academic_enrollment_ids']??$_POST['academic_enrollment_id']??[];
    if(is_string($ids))$ids=json_decode($ids,true)?:[$ids];
    if(!is_array($ids))$ids=[];
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids))));
    if(!$groupId)failJson('Groupe invalide.');
    if(!$ids)failJson('Aucun stagiaire sélectionné.');

    $unitIds=chefUnits($pdo,$uid,$eid,$role);

    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT id,campaign_id,promotion_id,capacite,statut FROM stage_groups WHERE id=? AND host_etablissement_id=? FOR UPDATE");
    $s->execute([$groupId,$eid]);
    $g=$s->fetch(PDO::FETCH_ASSOC);
    if(!$g)failJson('Groupe introuvable.',404);
    if(($g['statut']??'')!=='BROUILLON')failJson('Ce groupe est déjà publié ou verrouillé.');

    $campaignId=(int)$g['campaign_id'];
    $promotionId=(int)$g['promotion_id'];
    $capacity=(int)($g['capacite']??0);

    $s=$pdo->prepare('SELECT COUNT(*) FROM stage_group_students WHERE group_id=?');
    $s->execute([$groupId]);
    $current=(int)$s->fetchColumn();
    if($capacity>0&&$current+count($ids)>$capacity)
        failJson('Capacité du groupe dépassée.');

    $in=implode(',',array_fill(0,count($ids),'?'));
    $where=["ae.id IN($in)","app.campaign_id=?","app.host_etablissement_id=?","ae.promotion_id=?","ad.host_etablissement_id=?","ad.statut IN('ADMIS','EN_COURS')","sr.statut='CONFIRMEE'","sa.host_etablissement_id=?","sa.statut IN('PLANIFIEE','ACTIVE')","og.id IS NULL"];
    $params=array_merge($ids,[$campaignId,$eid,$promotionId,$eid,$eid]);
    if(is_array($unitIds)){
        $where[]='sa.host_unit_id IN('.implode(',',array_fill(0,count($unitIds),'?')).')';
        $params=array_merge($params,$unitIds);
    }

    $sql="SELECT DISTINCT ae.id
        FROM student_academic_enrollments ae
        JOIN stage_applications app ON app.academic_enrollment_id=ae.id
        JOIN stage_reservations sr ON sr.application_id=app.id
        JOIN stage_admissions ad ON ad.reservation_id=sr.id
        JOIN stage_assignments sa ON sa.admission_id=ad.id
        LEFT JOIN stage_group_students gs ON gs.campaign_id=app.campaign_id AND gs.academic_enrollment_id=ae.id
        LEFT JOIN stage_groups og ON og.id=gs.group_id AND og.host_etablissement_id=? AND og.statut<>'ANNULE' AND og.id<>?
        WHERE ".implode(' AND ',$where);
    $params=array_merge([$eid,$groupId],$params);
    $s=$pdo->prepare($sql);
    $s->execute($params);
    $valid=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    sort($valid);$wanted=$ids;sort($wanted);
    if($valid!==$wanted)failJson('Un ou plusieurs stagiaires ne sont pas disponibles pour ce groupe.');

    $tableCols=cols($pdo,'stage_group_students');
    $added=0;
    foreach($ids as $aeId){
        $s=$pdo->prepare('SELECT id FROM stage_group_students WHERE group_id=? AND academic_enrollment_id=? LIMIT 1');
        $s->execute([$groupId,$aeId]);
        if($s->fetchColumn())continue;

        $c=[];$v=[];$p=[];
        if(isset($tableCols['uuid']))addCol($c,$v,$p,'uuid',uuidv4());
        addCol($c,$v,$p,'group_id',$groupId);
        addCol($c,$v,$p,'campaign_id',$campaignId);
        addCol($c,$v,$p,'academic_enrollment_id',$aeId);
        if(isset($tableCols['host_etablissement_id']))addCol($c,$v,$p,'host_etablissement_id',$eid);
        if(isset($tableCols['promotion_id']))addCol($c,$v,$p,'promotion_id',$promotionId);
        if(isset($tableCols['statut']))addCol($c,$v,$p,'statut','ACTIF');
        if(isset($tableCols['created_by']))addCol($c,$v,$p,'created_by',$uid);
        if(isset($tableCols['added_by']))addCol($c,$v,$p,'added_by',$uid);
        if(isset($tableCols['created_at']))addCol($c,$v,$p,'created_at','NOW()',true);
        if(isset($tableCols['updated_at']))addCol($c,$v,$p,'updated_at','NOW()',true);

        $s=$pdo->prepare('INSERT INTO stage_group_students ('.implode(',',$c).') VALUES ('.implode(',',$v).')');
        $s->execute($p);
        $added++;
    }

    $pdo->commit();
    jsonResponse(true,$added.' stagiaire(s) ajouté(s) au groupe.',['added'=>$added,'group_id'=>$groupId]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[GROUPE STUDENTS SAVE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur ajout membres : '.$e->getMessage(),[],500);
}

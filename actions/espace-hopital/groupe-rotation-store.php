<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/stage-execution.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

function grs_int(string $k):int{return (int)($_POST[$k]??0);}
function grs_text(string $k,int $max=1000):string{return mb_substr(trim((string)($_POST[$k]??'')),0,$max);}
function grs_uuid():string{return function_exists('random_bytes')?vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex(random_bytes(16)),4)):uniqid('',true);}
function grs_cols(PDO $pdo,string $t):array{
    static $c=[];if(isset($c[$t]))return $c[$t];
    $r=[];foreach($pdo->query('SHOW COLUMNS FROM '.$t)->fetchAll(PDO::FETCH_ASSOC) as $x)$r[$x['Field']]=true;
    return $c[$t]=$r;
}
function grs_insert(PDO $pdo,string $t,array $data):int{
    $cols=grs_cols($pdo,$t);$use=[];
    foreach($data as $k=>$v)if(isset($cols[$k]))$use[$k]=$v;
    if(!$use)throw new Exception('Aucune colonne compatible pour '.$t.'.');
    $sql='INSERT INTO '.$t.' (`'.implode('`,`',array_keys($use)).'`) VALUES ('.implode(',',array_fill(0,count($use),'?')).')';
    $s=$pdo->prepare($sql);$s->execute(array_values($use));
    return (int)$pdo->lastInsertId();
}
function grs_chef_units(PDO $pdo,int $uid,int $eid,string $role):?array{
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
        $before=count($allIds);$allIds=array_values(array_unique(array_merge($allIds,$new)));
        if(count($allIds)===$before)break;
    }
    return $allIds;
}
function grs_in_scope(?array $ids,int $id):bool{return !is_array($ids)||in_array($id,$ids,true);}
function grs_add_days(string $date,int $days):string{$d=new DateTime($date);$d->modify(($days>=0?'+':'').$days.' day');return $d->format('Y-m-d');}
function grs_unit_type(string $type):string{return strtoupper(strtr(trim($type),['É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','é'=>'E','è'=>'E','ê'=>'E','ë'=>'E']));}

try{
    header('Content-Type: application/json; charset=utf-8');
    $role=$_SESSION['role_code']??'';
    if($role==='CHEF_SERVICE')requireRole(['CHEF_SERVICE']);
    else requirePermission($pdo,'rotation.hosting.manage');

    if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);
    if(empty($_SESSION['csrf'])||empty($_POST['csrf'])||!hash_equals($_SESSION['csrf'],(string)$_POST['csrf']))jsonResponse(false,'Jeton de sécurité invalide.',[],419);

    $eid=(int)currentEtablissementId($pdo);$uid=(int)($_SESSION['user_id']??0);
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    $groupId=grs_int('group_id');
    $unitId=grs_int('host_unit_id');
    $supervisorId=grs_int('principal_supervisor_user_id');
    $duration=max(0,grs_int('duration_days'));
    $objectifs=grs_text('objectifs',2000);
    $observation=grs_text('observation',1000);

    if(!$groupId)jsonResponse(false,'Groupe invalide.',[],422);
    if(!$unitId)jsonResponse(false,'Service obligatoire.',[],422);
    if(!$supervisorId)jsonResponse(false,'Encadreur / maître de stage obligatoire.',[],422);
    if($duration<1||$duration>366)jsonResponse(false,'Durée de rotation invalide.',[],422);

    try{syncStageExecution($pdo,['host_etablissement_id'=>$eid]);}catch(Throwable $e){error_log('[SYNC STAGE EXECUTION] '.$e->getMessage());}

    $unitIds=grs_chef_units($pdo,$uid,$eid,$role);
    if(!grs_in_scope($unitIds,$unitId))jsonResponse(false,"Ce service n'est pas dans votre périmètre.",[],403);

    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT g.*,c.date_debut campaign_start,c.date_fin campaign_end FROM stage_groups g JOIN stage_campaigns c ON c.id=g.campaign_id WHERE g.id=? AND g.host_etablissement_id=? FOR UPDATE");
    $s->execute([$groupId,$eid]);
    $group=$s->fetch(PDO::FETCH_ASSOC);
    if(!$group)throw new Exception('Groupe introuvable.');
    if(($group['statut']??'')!=='BROUILLON')throw new Exception('Le groupe est déjà publié ou verrouillé.');

    $s=$pdo->prepare("SELECT unit.id,unit.parent_id,unit.nom,unit.type,parent.type parent_type
        FROM host_units unit
        LEFT JOIN host_units parent ON parent.id=unit.parent_id AND parent.host_etablissement_id=unit.host_etablissement_id
        WHERE unit.id=? AND unit.host_etablissement_id=? AND unit.actif=1");
    $s->execute([$unitId,$eid]);
    $unit=$s->fetch(PDO::FETCH_ASSOC);
    if(!$unit)throw new Exception('Service introuvable ou inactif.');
    $unitType=grs_unit_type((string)$unit['type']);
    $parentType=grs_unit_type((string)($unit['parent_type']??''));
    if($unitType!=='SERVICE'&&!($unitType==='UNITE'&&$parentType==='SERVICE'))
        throw new Exception('Une rotation doit cibler un service ou une unité rattachée à un service.');

    $scopeIds=[$unitId];
    if((int)($unit['parent_id']??0)>0)$scopeIds[]=(int)$unit['parent_id'];

    $in=implode(',',array_fill(0,count($scopeIds),'?'));
    $s=$pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.id=? AND u.actif=1 AND u.statut_compte='ACTIF' AND EXISTS(SELECT 1 FROM role_assignments ra JOIN roles r ON r.id=ra.role_id WHERE ra.user_id=u.id AND ra.etablissement_id=? AND ra.actif=1 AND ra.revoked_at IS NULL AND r.code IN('ENCADREUR','EVALUATEUR_CLINIQUE') AND (ra.scope_type='ORGANIZATION' OR (ra.scope_type='UNIT' AND ra.scope_id IN($in))))");
    $s->execute(array_merge([$supervisorId,$eid],$scopeIds));
    if(!(int)$s->fetchColumn())throw new Exception("L'encadreur choisi n'est pas actif dans ce service.");

    $s=$pdo->prepare("SELECT COUNT(*) FROM stage_group_students WHERE group_id=?");
    $s->execute([$groupId]);
    if(!(int)$s->fetchColumn())throw new Exception('Ajoutez au moins un stagiaire dans le groupe avant la rotation.');

    $params=[$groupId];
    $unitFilter='';
    if(is_array($unitIds)){
        $unitFilter=' AND sa.host_unit_id IN('.implode(',',array_fill(0,count($unitIds),'?')).')';
        $params=array_merge($params,$unitIds);
    }

    $s=$pdo->prepare("SELECT MAX(sa.date_debut) common_start,MIN(COALESCE(sa.date_fin,c.date_fin)) common_end FROM stage_group_students gs JOIN stage_groups gg ON gg.id=gs.group_id JOIN stage_campaigns c ON c.id=gg.campaign_id JOIN stage_applications app ON app.campaign_id=gg.campaign_id AND app.academic_enrollment_id=gs.academic_enrollment_id AND app.host_etablissement_id=gg.host_etablissement_id JOIN stage_reservations rs ON rs.application_id=app.id AND rs.statut='CONFIRMEE' JOIN stage_admissions ad ON ad.reservation_id=rs.id AND ad.host_etablissement_id=gg.host_etablissement_id AND ad.statut IN('ADMIS','EN_COURS') JOIN stage_assignments sa ON sa.admission_id=ad.id AND sa.host_etablissement_id=gg.host_etablissement_id AND sa.statut IN('PLANIFIEE','ACTIVE') WHERE gs.group_id=?$unitFilter");
    $s->execute($params);
    $period=$s->fetch(PDO::FETCH_ASSOC)?:[];
    $planningStart=$period['common_start']?:($group['campaign_start']??'');
    $planningEnd=$period['common_end']?:($group['campaign_end']??'');
    if(!$planningStart||!$planningEnd)throw new Exception('Période de stage introuvable pour ce groupe.');

    $s=$pdo->prepare("SELECT sequence_no,date_fin FROM stage_group_rotation_plans WHERE group_id=? AND statut<>'ANNULEE' ORDER BY sequence_no DESC,id DESC LIMIT 1 FOR UPDATE");
    $s->execute([$groupId]);
    $last=$s->fetch(PDO::FETCH_ASSOC);
    $sequence=$last?(int)$last['sequence_no']+1:1;
    $start=$last?grs_add_days($last['date_fin'],1):$planningStart;
    $end=grs_add_days($start,$duration-1);

    if($start>$planningEnd)throw new Exception('La période disponible du groupe est déjà consommée.');
    if($end>$planningEnd)throw new Exception('Durée trop longue. La rotation doit finir au plus tard le '.$planningEnd.'.');

    $now=date('Y-m-d H:i:s');
    $id=grs_insert($pdo,'stage_group_rotation_plans',[
        'uuid'=>grs_uuid(),
        'group_id'=>$groupId,
        'campaign_id'=>(int)($group['campaign_id']??0),
        'host_etablissement_id'=>$eid,
        'host_unit_id'=>$unitId,
        'principal_supervisor_user_id'=>$supervisorId,
        'sequence_no'=>$sequence,
        'date_debut'=>$start,
        'date_fin'=>$end,
        'objectifs'=>$objectifs,
        'observation'=>$observation,
        'statut'=>'PLANIFIEE',
        'created_by'=>$uid,
        'created_at'=>$now,
        'updated_at'=>$now
    ]);

    $pdo->commit();
    jsonResponse(true,'Rotation ajoutée.',[
        'id'=>$id,
        'sequence_no'=>$sequence,
        'date_debut'=>$start,
        'date_fin'=>$end,
        'host_unit_id'=>$unitId,
        'principal_supervisor_user_id'=>$supervisorId
    ]);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[GROUPE ROTATION STORE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    if(function_exists('jsonResponse'))jsonResponse(false,'Erreur : '.$e->getMessage(),[],500);
    echo json_encode(['success'=>false,'message'=>'Erreur : '.$e->getMessage()]);
}

<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/chef-service-scope.php';
require_once __DIR__.'/../includes/stagia-role-detect.php';
header('Content-Type:text/plain; charset=utf-8');
$eid=(int)($_SESSION['etablissement_id']??0);
$uid=(int)($_SESSION['user_id']??0);
$role=(string)($_SESSION['role_code']??'');
$pool=stagiaUserRolePool($pdo,$uid,$eid,[$role,(string)($_SESSION['role_nom']??'')]);
$isChef=stagiaRolePoolContains($pool,['CHEF_SERVICE','CHEF_DE_SERVICE','RESPONSABLE_SERVICE']);
$isEnc=stagiaRolePoolContains($pool,['ENCADREUR','ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','EVALUATEUR_CLINIQUE']);
echo "STAGIA DEBUG NOTIFICATIONS\n";
echo "user_id=$uid\n";
echo "etablissement_id=$eid\n";
echo "role_session=$role\n";
echo "roles_detectes=".implode(', ',$pool)."\n";
echo "est_chef=".($isChef?'OUI':'NON')."\n";
echo "est_maitre_stage=".($isEnc?'OUI':'NON')."\n\n";
try{
    if($isChef){
        $scope=chefServiceScope($pdo,$eid,$uid);
        echo "scope_chef_all=".(!empty($scope['all'])?'OUI':'NON')."\n";
        echo "scope_chef_ids=".implode(',',array_map('intval',$scope['ids']??[]))."\n";
        $ids=array_values(array_unique(array_filter(array_map('intval',$scope['ids']??[]))));
        if(!empty($scope['all'])){
            $w='';$p=[$eid];
        }elseif($ids){
            $in=implode(',',array_fill(0,count($ids),'?'));
            $s=$pdo->prepare("SELECT DISTINCT parent_id FROM host_units WHERE host_etablissement_id=? AND actif=1 AND id IN($in) AND parent_id IS NOT NULL");
            $s->execute(array_merge([$eid],$ids));
            foreach($s->fetchAll(PDO::FETCH_COLUMN) as $pid)$ids[]=(int)$pid;
            $ids=array_values(array_unique(array_filter($ids)));
            $w=' AND a.coordination_unit_id IN('.implode(',',array_fill(0,count($ids),'?')).')';$p=array_merge([$eid],$ids);
        }else{$w=' AND 1=0';$p=[$eid];}
        $sql="SELECT COUNT(*) FROM stage_admissions a JOIN stage_reservations sr ON sr.id=a.reservation_id WHERE a.host_etablissement_id=? AND a.statut IN('ADMIS','EN_COURS') AND sr.statut IN('CONFIRMEE','EN_ATTENTE_PAIEMENT') AND a.coordination_unit_id IS NOT NULL $w AND NOT EXISTS(SELECT 1 FROM stage_assignments ass WHERE ass.admission_id=a.id AND ass.statut<>'ANNULEE')";
        $s=$pdo->prepare($sql);$s->execute($p);echo "chef_a_affecter=".(int)$s->fetchColumn()."\n";
    }
    if($isEnc){
        $s=$pdo->prepare("SELECT COUNT(DISTINCT r.id) FROM stage_rotations r JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE r.host_etablissement_id=? AND r.statut IN('PLANIFIEE','ACTIVE')");
        $s->execute([$uid,$eid]);echo "maitre_rotations=".(int)$s->fetchColumn()."\n";
    }
}catch(Throwable $e){echo "ERREUR_DEBUG=".$e->getMessage()."\n";}

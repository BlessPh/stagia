<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/chef-service-scope.php';

if(function_exists('requireAjaxRole'))requireAjaxRole(['CHEF_SERVICE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);
else requireRole(['CHEF_SERVICE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);

function chefReceivedWhereUnits(array $unitIds,array &$params):string{
    if(!$unitIds)return ' AND 1=0';
    $params=array_merge($params,$unitIds);
    return ' AND a.host_unit_id IN('.implode(',',array_fill(0,count($unitIds),'?')).')';
}

function countAffectations(PDO $pdo,int $eid,?array $unitIds,?string $statut=null):int{
    $w=["a.host_etablissement_id=?","a.statut<>'ANNULEE'"];$p=[$eid];
    if(is_array($unitIds))$w[]=trim(chefReceivedWhereUnits($unitIds,$p),' AND');
    if($statut){$w[]='a.statut=?';$p[]=$statut;}
    $s=$pdo->prepare("SELECT COUNT(DISTINCT a.id) FROM stage_assignments a WHERE ".implode(' AND ',$w));
    $s->execute($p);return (int)$s->fetchColumn();
}

try{
    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $role=strtoupper(trim((string)($_SESSION['role_code']??'')));
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    $status=strtoupper(trim((string)($_GET['status']??$_GET['statut']??'A_SUIVRE')));
    $map=[''=>'A_SUIVRE','A_SUIVRE'=>'A_SUIVRE','SUIVI'=>'A_SUIVRE','ACTIVE'=>'ACTIVE','ACTIF'=>'ACTIVE','ACTIFS'=>'ACTIVE','PLANIFIEE'=>'PLANIFIEE','PLANIFIÉE'=>'PLANIFIEE','PLANIFIES'=>'PLANIFIEE','PLANIFIÉS'=>'PLANIFIEE','TERMINEE'=>'TERMINEE','TERMINÉE'=>'TERMINEE','TERMINES'=>'TERMINEE','TERMINÉS'=>'TERMINEE'];
    $status=$map[$status]??'A_SUIVRE';
    $q=trim((string)($_GET['q']??$_GET['search']??''));

    $adminAll=in_array($role,['ADMIN_ACCUEIL','COORDINATEUR_STAGES'],true);
    $unitIds=$adminAll?null:chefServiceUnitIds($pdo,$eid,$uid,false);

    $where=["a.host_etablissement_id=?","a.statut<>'ANNULEE'"];$params=[$eid];
    if(is_array($unitIds))$where[]=trim(chefReceivedWhereUnits($unitIds,$params),' AND');
    if($status==='A_SUIVRE')$where[]="a.statut IN('ACTIVE','PLANIFIEE')";
    else{$where[]='a.statut=?';$params[]=$status;}

    if($q!==''){
        $where[]="(sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ? OR sp.stagia_code LIKE ? OR se.matricule LIKE ? OR c.code LIKE ? OR c.titre LIKE ? OR univ.nom LIKE ? OR hu.nom LIKE ? OR parent.nom LIKE ?)";
        $like='%'.$q.'%';for($i=0;$i<10;$i++)$params[]=$like;
    }

    $sql="SELECT a.id,a.id assignment_id,a.statut,a.statut status,a.date_debut starts_at,a.date_fin ends_at,
            COALESCE(se.matricule,sp.stagia_code,CONCAT('AFFECT-',a.id)) matricule,sp.stagia_code,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Stagiaire #',a.id)) stagiaire,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Stagiaire #',a.id)) student_name,
            COALESCE(univ.nom,'—') universite,COALESCE(univ.nom,'—') university_name,
            COALESCE(c.code,'—') campaign_code,COALESCE(c.titre,'—') campaign_title,
            CONCAT(COALESCE(c.code,'—'),' — ',COALESCE(c.titre,'—')) session,
            COALESCE(hu.nom,CONCAT('Service #',a.host_unit_id)) service,
            COALESCE(hu.nom,CONCAT('Service #',a.host_unit_id)) service_name,
            COALESCE(hu.code,'') service_code,
            COALESCE(parent.nom,'') coordination_name
        FROM stage_assignments a
        LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
        LEFT JOIN stage_applications app ON app.id=sr.application_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN etablissements univ ON univ.id=c.owner_etablissement_id
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id AND hu.host_etablissement_id=a.host_etablissement_id
        LEFT JOIN host_units parent ON parent.id=hu.parent_id AND parent.host_etablissement_id=a.host_etablissement_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY FIELD(a.statut,'ACTIVE','PLANIFIEE','TERMINEE'),a.date_debut ASC,a.id DESC
        LIMIT 300";

    $s=$pdo->prepare($sql);$s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$x){$x['id']=(int)$x['id'];$x['assignment_id']=(int)$x['assignment_id'];}unset($x);

    jsonResponse(true,'',[
        'items'=>$items,
        'data'=>$items,
        'stats'=>[
            'total'=>countAffectations($pdo,$eid,$unitIds,null),
            'actifs'=>countAffectations($pdo,$eid,$unitIds,'ACTIVE'),
            'planifies'=>countAffectations($pdo,$eid,$unitIds,'PLANIFIEE'),
            'termines'=>countAffectations($pdo,$eid,$unitIds,'TERMINEE')
        ],
        'pagination'=>['page'=>1,'pages'=>1,'total'=>count($items)]
    ]);
}catch(Throwable $e){
    error_log('[CHEF SERVICE STAGIAIRES LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],500);
}

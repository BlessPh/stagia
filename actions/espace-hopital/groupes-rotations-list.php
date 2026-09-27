<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/stage-execution.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    $role=$_SESSION['role_code']??'';

    if($role==='CHEF_SERVICE')requireRole(['CHEF_SERVICE']);
    else requirePermission($pdo,'group.hosting.view');

    if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);

    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    try{syncStageExecution($pdo,['host_etablissement_id'=>$eid]);}
    catch(Throwable $e){error_log('[SYNC STAGE EXECUTION] '.$e->getMessage());}

    function chefUnitScope(PDO $pdo,int $uid,int $eid,string $role):?array{
        if($role!=='CHEF_SERVICE')return null;

        $ids=[];$all=false;

        $s=$pdo->prepare("
            SELECT ra.scope_type,ra.scope_id
            FROM role_assignments ra
            JOIN roles r ON r.id=ra.role_id
            WHERE ra.user_id=?
              AND ra.etablissement_id=?
              AND r.code='CHEF_SERVICE'
              AND ra.actif=1
              AND ra.revoked_at IS NULL
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        ");
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
            $s=$pdo->prepare("
                SELECT id
                FROM host_units
                WHERE host_etablissement_id=?
                  AND actif=1
                  AND parent_id IN($in)
            ");
            $s->execute(array_merge([$eid],$allIds));
            $new=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));

            $before=count($allIds);
            $allIds=array_values(array_unique(array_merge($allIds,$new)));
            if(count($allIds)===$before)break;
        }

        return $allIds;
    }

    function addUnitFilter(string $field,?array $unitIds,array &$where,array &$params):void{
        if(!is_array($unitIds))return;
        $where[]=$field.' IN('.implode(',',array_fill(0,count($unitIds),'?')).')';
        $params=array_merge($params,$unitIds);
    }

    $unitIds=chefUnitScope($pdo,$uid,$eid,$role);
    $campaignId=(int)($_GET['campaign_id']??0);
    $promotionId=(int)($_GET['promotion_id']??0);

    $whereCtx=["ad.host_etablissement_id=?","ad.statut IN('ADMIS','EN_COURS')"];
    $pCtx=[$eid];

    addUnitFilter('sa.host_unit_id',$unitIds,$whereCtx,$pCtx);

    $s=$pdo->prepare("
        SELECT DISTINCT
            c.id campaign_id,
            c.code campaign_code,
            c.titre campaign_title,
            c.created_at campaign_created_at,
            uni.id university_id,
            uni.nom university_name,
            pr.id promotion_id,
            pr.code promotion_code,
            pr.nom promotion_name,
            al.code level_code

        FROM stage_admissions ad
        JOIN stage_reservations sr
          ON sr.id=ad.reservation_id
         AND sr.statut='CONFIRMEE'
        JOIN stage_applications app
          ON app.id=sr.application_id
         AND app.host_etablissement_id=?
        JOIN stage_campaigns c
          ON c.id=app.campaign_id
        JOIN etablissements uni
          ON uni.id=c.owner_etablissement_id
        JOIN student_academic_enrollments ae
          ON ae.id=app.academic_enrollment_id
        JOIN promotions pr
          ON pr.id=ae.promotion_id
        LEFT JOIN academic_levels al
          ON al.id=pr.academic_level_id
        JOIN stage_assignments sa
          ON sa.admission_id=ad.id
         AND sa.host_etablissement_id=?
         AND sa.statut IN('PLANIFIEE','ACTIVE')

        WHERE ".implode(' AND ',$whereCtx)."

        ORDER BY c.created_at DESC,pr.nom
    ");
    $s->execute(array_merge([$eid,$eid],$pCtx));
    $contexts=$s->fetchAll(PDO::FETCH_ASSOC);

    if(!$campaignId&&$contexts)$campaignId=(int)$contexts[0]['campaign_id'];

    if(!$promotionId&&$campaignId){
        foreach($contexts as $ctx){
            if((int)$ctx['campaign_id']===$campaignId){
                $promotionId=(int)$ctx['promotion_id'];
                break;
            }
        }
    }

    $whereUnits=["host_etablissement_id=?","actif=1"];
    $pUnits=[$eid];
    addUnitFilter('id',$unitIds,$whereUnits,$pUnits);

    $s=$pdo->prepare("
        SELECT id,parent_id,code,nom,type,capacite
        FROM host_units
        WHERE ".implode(' AND ',$whereUnits)."
        ORDER BY COALESCE(parent_id,0),nom
    ");
    $s->execute($pUnits);
    $units=$s->fetchAll(PDO::FETCH_ASSOC);

    /* =========================================================
       ENCADREURS DISPONIBLES PAR SERVICE
    ========================================================= */
    $s=$pdo->prepare("
        SELECT id,parent_id
        FROM host_units
        WHERE host_etablissement_id=?
          AND actif=1
    ");
    $s->execute([$eid]);

    $children=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $u){
        $pid=(int)($u['parent_id']??0);
        $children[$pid][]=(int)$u['id'];
    }

    $expandUnits=function(array $ids)use(&$expandUnits,$children):array{
        $all=array_map('intval',$ids);
        foreach($ids as $id){
            foreach($children[(int)$id]??[] as $child){
                $all[]=(int)$child;
                $all=array_merge($all,$expandUnits([(int)$child]));
            }
        }
        return array_values(array_unique($all));
    };

    $s=$pdo->prepare("
        SELECT
            u.id,
            CONCAT_WS(' ',u.prenom,u.nom,u.postnom) full_name,
            COALESCE(NULLIF(eu.fonction,''),'Encadreur / Maître de stage') fonction,
            ra.scope_type,
            ra.scope_id
        FROM users u
        JOIN role_assignments ra
          ON ra.user_id=u.id
         AND ra.etablissement_id=?
         AND ra.actif=1
         AND ra.revoked_at IS NULL
         AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
         AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        JOIN roles r
          ON r.id=ra.role_id
         AND r.code IN('ENCADREUR','EVALUATEUR_CLINIQUE')
        LEFT JOIN etablissement_users eu
          ON eu.user_id=u.id
         AND eu.etablissement_id=?
        WHERE u.actif=1
          AND u.statut_compte='ACTIF'
        ORDER BY full_name
    ");
    $s->execute([$eid,$eid]);

    $supMap=[];

    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
        $id=(int)$r['id'];

        if(!isset($supMap[$id])){
            $supMap[$id]=[
                'id'=>$id,
                'full_name'=>$r['full_name']?:'Utilisateur',
                'fonction'=>$r['fonction']?:'Encadreur / Maître de stage',
                'scope_all'=>false,
                'unit_ids'=>[]
            ];
        }

        if($r['scope_type']==='ORGANIZATION'){
            $supMap[$id]['scope_all']=true;
            continue;
        }

        if($r['scope_type']==='UNIT'&&(int)$r['scope_id']>0){
            $ids=$expandUnits([(int)$r['scope_id']]);
            $supMap[$id]['unit_ids']=array_values(array_unique(array_merge(
                $supMap[$id]['unit_ids'],
                $ids
            )));
        }
    }

    if(is_array($unitIds)){
        foreach($supMap as $id=>$sRow){
            if($sRow['scope_all'])continue;
            if(!array_intersect(array_map('intval',$sRow['unit_ids']),$unitIds))unset($supMap[$id]);
        }
    }

    $supervisors=array_values($supMap);

    $students=[];$groups=[];

    if($campaignId&&$promotionId){
        $whereSt=[
            "ad.host_etablissement_id=?",
            "ad.statut IN('ADMIS','EN_COURS')"
        ];
        $pSt=[$eid];

        addUnitFilter('sa.host_unit_id',$unitIds,$whereSt,$pSt);

        $s=$pdo->prepare("
            SELECT DISTINCT
                ae.id academic_enrollment_id,
                sp.id student_id,
                sp.stagia_code,
                sp.nom,
                sp.postnom,
                sp.prenom,
                ad.id admission_id,
                ad.statut admission_status,
                sa.id assignment_id,
                sa.statut assignment_status,
                sa.date_debut assignment_start,
                sa.date_fin assignment_end,
                hu.id assignment_unit_id,
                hu.nom assignment_unit_name,
                g.id group_id,
                g.code group_code,
                g.nom group_name

            FROM stage_admissions ad
            JOIN stage_reservations sr
              ON sr.id=ad.reservation_id
             AND sr.statut='CONFIRMEE'
            JOIN stage_applications app
              ON app.id=sr.application_id
             AND app.campaign_id=?
             AND app.host_etablissement_id=?
            JOIN student_academic_enrollments ae
              ON ae.id=app.academic_enrollment_id
             AND ae.promotion_id=?
            JOIN student_enrollments se
              ON se.id=ae.enrollment_id
            JOIN student_profiles sp
              ON sp.id=se.student_id
            JOIN stage_assignments sa
              ON sa.admission_id=ad.id
             AND sa.host_etablissement_id=?
             AND sa.statut IN('PLANIFIEE','ACTIVE')
            JOIN host_units hu
              ON hu.id=sa.host_unit_id
            LEFT JOIN stage_group_students gs
              ON gs.campaign_id=app.campaign_id
             AND gs.academic_enrollment_id=ae.id
            LEFT JOIN stage_groups g
              ON g.id=gs.group_id
             AND g.host_etablissement_id=?
             AND g.statut<>'ANNULE'

            WHERE ".implode(' AND ',$whereSt)."

            ORDER BY sp.nom,sp.prenom
        ");
        $s->execute(array_merge([$campaignId,$eid,$promotionId,$eid,$eid],$pSt));
        $students=$s->fetchAll(PDO::FETCH_ASSOC);

        $s=$pdo->prepare("
            SELECT
                g.id,g.uuid,g.code,g.nom,g.capacite,g.description,g.statut,g.promotion_id,
                c.date_debut campaign_start,
                c.date_fin campaign_end,
                COUNT(DISTINCT gs.id) student_count,
                COUNT(DISTINCT rp.id) rotation_count
            FROM stage_groups g
            JOIN stage_campaigns c ON c.id=g.campaign_id
            LEFT JOIN stage_group_students gs ON gs.group_id=g.id
            LEFT JOIN stage_group_rotation_plans rp
              ON rp.group_id=g.id
             AND rp.statut<>'ANNULEE'
            WHERE g.host_etablissement_id=?
              AND g.campaign_id=?
              AND g.promotion_id=?
              AND g.statut<>'ANNULE'
            GROUP BY g.id,g.uuid,g.code,g.nom,g.capacite,g.description,g.statut,
                     g.promotion_id,c.date_debut,c.date_fin
            ORDER BY g.code,g.id
        ");
        $s->execute([$eid,$campaignId,$promotionId]);
        $groups=$s->fetchAll(PDO::FETCH_ASSOC);

        foreach($groups as &$g){
            $whereRot=["rp.group_id=?","rp.statut<>'ANNULEE'"];
            $pRot=[(int)$g['id']];
            addUnitFilter('rp.host_unit_id',$unitIds,$whereRot,$pRot);

            $s=$pdo->prepare("
                SELECT
                    rp.id,
                    rp.sequence_no,
                    rp.host_unit_id,
                    rp.principal_supervisor_user_id,
                    hu.nom unit_name,
                    hu.type unit_type,
                    parent.nom parent_name,
                    CONCAT_WS(' ',su.prenom,su.nom,su.postnom) supervisor_name,
                    COALESCE(NULLIF(seu.fonction,''),'Encadreur / Maître de stage') supervisor_function,
                    rp.date_debut,
                    rp.date_fin,
                    rp.objectifs,
                    rp.observation,
                    rp.statut
                FROM stage_group_rotation_plans rp
                JOIN host_units hu ON hu.id=rp.host_unit_id
                LEFT JOIN host_units parent ON parent.id=hu.parent_id
                LEFT JOIN users su ON su.id=rp.principal_supervisor_user_id
                LEFT JOIN etablissement_users seu
                  ON seu.user_id=su.id
                 AND seu.etablissement_id=rp.host_etablissement_id
                WHERE ".implode(' AND ',$whereRot)."
                ORDER BY rp.sequence_no,rp.date_debut,rp.id
            ");
            $s->execute($pRot);
            $g['rotations']=$s->fetchAll(PDO::FETCH_ASSOC);

            $wherePeriod=["gs.group_id=?"];
            $pPeriod=[(int)$g['id']];
            addUnitFilter('sa.host_unit_id',$unitIds,$wherePeriod,$pPeriod);

            $s=$pdo->prepare("
                SELECT
                    MAX(sa.date_debut) common_start,
                    MIN(COALESCE(sa.date_fin,c.date_fin)) common_end
                FROM stage_group_students gs
                JOIN stage_groups gg ON gg.id=gs.group_id
                JOIN stage_campaigns c ON c.id=gg.campaign_id
                JOIN stage_applications app
                  ON app.campaign_id=gg.campaign_id
                 AND app.academic_enrollment_id=gs.academic_enrollment_id
                 AND app.host_etablissement_id=gg.host_etablissement_id
                JOIN stage_reservations rs
                  ON rs.application_id=app.id
                 AND rs.statut='CONFIRMEE'
                JOIN stage_admissions ad
                  ON ad.reservation_id=rs.id
                 AND ad.host_etablissement_id=gg.host_etablissement_id
                 AND ad.statut IN('ADMIS','EN_COURS')
                JOIN stage_assignments sa
                  ON sa.admission_id=ad.id
                 AND sa.host_etablissement_id=gg.host_etablissement_id
                 AND sa.statut IN('PLANIFIEE','ACTIVE')
                WHERE ".implode(' AND ',$wherePeriod)."
            ");
            $s->execute($pPeriod);
            $period=$s->fetch(PDO::FETCH_ASSOC)?:[];

            $g['planning_start']=$period['common_start']?:$g['campaign_start'];
            $g['planning_end']=$period['common_end']?:$g['campaign_end'];

            $last=end($g['rotations']);
            if($last){
                $g['next_sequence']=(int)$last['sequence_no']+1;
                $g['next_rotation_start']=date('Y-m-d',strtotime($last['date_fin'].' +1 day'));
            }else{
                $g['next_sequence']=1;
                $g['next_rotation_start']=$g['planning_start'];
            }
        }
        unset($g);
    }

    jsonResponse(true,'',[
        'contexts'=>$contexts,
        'selected'=>[
            'campaign_id'=>$campaignId,
            'promotion_id'=>$promotionId
        ],
        'units'=>$units,
        'supervisors'=>$supervisors,
        'students'=>$students,
        'groups'=>$groups,
        'permissions'=>[
            'group_manage'=>$role==='CHEF_SERVICE'||hasPermission($pdo,'group.hosting.manage'),
            'rotation_manage'=>$role==='CHEF_SERVICE'||hasPermission($pdo,'rotation.hosting.manage')
        ]
    ]);
}catch(Throwable $e){
    error_log('[GROUPES ROTATIONS LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur Groupes / rotations : '.$e->getMessage(),[],500);
}
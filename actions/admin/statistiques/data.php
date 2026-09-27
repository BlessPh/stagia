<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL','MINISTERE','ORDRE_MEDECINS']);

$actor=$_SESSION['role_code']??'';
$super=$actor==='SUPER_ADMIN';
$national=in_array($actor,['MINISTERE','ORDRE_MEDECINS'],true);
$platformScope=$super||$national;

/*
 * MINISTERE et ORDRE_MEDECINS sont des rôles nationaux de supervision :
 * ils accèdent aux statistiques de stages en lecture au niveau plateforme,
 * sans exposer les statistiques d'administration des comptes/RBAC.
 */
if(!$super && !$national && function_exists('contextPermission') && !contextPermission('report.view'))
    jsonResponse(false,'Permission insuffisante.',[],403);

$period=$_GET['period']??'30';
if(!in_array($period,['7','30','90','365','all'],true))$period='30';

$campaignId=(int)($_GET['campaign_id']??0);
$stageTypeId=(int)($_GET['stage_type_id']??0);
$hostId=(int)($_GET['host_id']??0);
$universityId=(int)($_GET['university_id']??0);
$stageStatus=strtoupper(trim((string)($_GET['stage_status']??'')));
if(!in_array($stageStatus,['CONFIRME','TERMINE','ANNULE'],true))$stageStatus='';

$since=fn(string $c)=>$period==='all'?'1=1':"$c>=DATE_SUB(NOW(),INTERVAL ".(int)$period." DAY)";
$stagePeriod=fn(string $a='pl')=>$period==='all'?'1=1':"$a.date_debut<=CURDATE() AND COALESCE($a.date_fin,CURDATE())>=DATE_SUB(CURDATE(),INTERVAL ".(int)$period." DAY)";
$datePeriod=fn(string $c)=>$period==='all'?'1=1':"$c>=DATE_SUB(CURDATE(),INTERVAL ".(int)$period." DAY)";

$rows=function(string $sql,array $p=[])use($pdo){
    $s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC);
};
$row=function(string $sql,array $p=[])use($pdo){
    $s=$pdo->prepare($sql);$s->execute($p);return $s->fetch(PDO::FETCH_ASSOC)?:[];
};
$one=function(string $sql,array $p=[])use($pdo){
    $s=$pdo->prepare($sql);$s->execute($p);return (int)$s->fetchColumn();
};
$tableExists=function(string $name)use($pdo):bool{
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$name]);return (int)$s->fetchColumn()>0;
};
$columnExists=function(string $table,string $column)use($pdo):bool{
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$table,$column]);return (int)$s->fetchColumn()>0;
};

try{
    /* =========================================================
       1. STATISTIQUES ADMINISTRATIVES EXISTANTES
       ========================================================= */
    if($super){
        $kpis=[
            'users'=>$one("SELECT COUNT(*) FROM users"),
            'users_active'=>$one("SELECT COUNT(*) FROM users WHERE statut_compte='ACTIF' AND actif=1"),
            'users_pending'=>$one("SELECT COUNT(*) FROM users WHERE statut_compte='A_ACTIVER'"),
            'users_suspended'=>$one("SELECT COUNT(*) FROM users WHERE statut_compte='SUSPENDU' OR actif=0"),
            'roles'=>$one("SELECT COUNT(*) FROM roles WHERE actif=1"),
            'permissions'=>$one("SELECT COUNT(*) FROM permissions WHERE actif=1"),
            'assignments_active'=>$one("SELECT COUNT(*) FROM role_assignments WHERE actif=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW())"),
            'assignments_revoked'=>$one("SELECT COUNT(*) FROM role_assignments WHERE actif=0"),
            'establishments'=>$one("SELECT COUNT(*) FROM etablissements"),
            'adhesion_pending'=>$one("SELECT COUNT(*) FROM demandes_adhesion WHERE statut IN('SOUMISE','EN_EXAMEN','A_COMPLETER')")
        ];
        $activity=[
            'new_users'=>$one("SELECT COUNT(*) FROM users u WHERE ".$since('u.created_at')),
            'new_establishments'=>$one("SELECT COUNT(*) FROM etablissements e WHERE ".$since('e.created_at')),
            'new_assignments'=>$one("SELECT COUNT(*) FROM role_assignments ra WHERE ".$since('ra.created_at')),
            'new_adhesions'=>$one("SELECT COUNT(*) FROM demandes_adhesion d WHERE ".$since('d.created_at'))
        ];
        $accounts=$rows("SELECT statut_compte label,COUNT(*) total FROM users GROUP BY statut_compte ORDER BY total DESC");
        $roles=$rows("SELECT r.code,r.nom,COUNT(DISTINCT ra.user_id) total FROM roles r LEFT JOIN role_assignments ra ON ra.role_id=r.id AND ra.actif=1 AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW()) WHERE r.actif=1 GROUP BY r.id ORDER BY total DESC,r.nom");
        $scopes=$rows("SELECT scope_type label,COUNT(*) total FROM role_assignments WHERE actif=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) GROUP BY scope_type ORDER BY total DESC");
        $types=$rows("SELECT type_etablissement label,COUNT(*) total FROM etablissements GROUP BY type_etablissement ORDER BY total DESC,label");
        $top=$rows("SELECT e.id,e.code,e.nom,e.type_etablissement,COUNT(DISTINCT ra.user_id) users_count,COUNT(DISTINCT ra.id) assignments_count FROM etablissements e LEFT JOIN role_assignments ra ON ra.etablissement_id=e.id AND ra.actif=1 AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW()) GROUP BY e.id ORDER BY users_count DESC,assignments_count DESC,e.nom LIMIT 10");
        $adhesions=$rows("SELECT statut label,COUNT(*) total FROM demandes_adhesion GROUP BY statut ORDER BY total DESC");
        $userTrend=$rows("SELECT DATE(u.created_at) jour,COUNT(*) total FROM users u WHERE ".$since('u.created_at')." GROUP BY DATE(u.created_at) ORDER BY jour");
        $assignmentTrend=$rows("SELECT DATE(ra.created_at) jour,COUNT(*) total FROM role_assignments ra WHERE ".$since('ra.created_at')." GROUP BY DATE(ra.created_at) ORDER BY jour");
        $etablissement=null;$eid=0;
    }elseif($national){
        /*
         * Les acteurs nationaux n'ont pas accès aux données d'administration
         * des comptes. On retourne uniquement des valeurs neutres pour garder
         * le contrat JSON commun avec la page.
         */
        $kpis=[
            'users'=>0,'users_active'=>0,'users_pending'=>0,'users_suspended'=>0,
            'roles'=>0,'permissions'=>0,'assignments_active'=>0,'assignments_revoked'=>0,
            'establishments'=>0,'adhesion_pending'=>0
        ];
        $activity=[
            'new_users'=>0,'new_establishments'=>0,'new_assignments'=>0,'new_adhesions'=>0
        ];
        $accounts=[];$roles=[];$scopes=[];$types=[];$top=[];$adhesions=[];
        $userTrend=[];$assignmentTrend=[];
        $etablissement=null;$eid=0;
    }else{
        $eid=(int)currentEtablissementId($pdo);
        if(!$eid)jsonResponse(false,'Aucun établissement associé à votre compte.',[],403);
        $s=$pdo->prepare("SELECT id,code,nom,type_etablissement FROM etablissements WHERE id=? LIMIT 1");
        $s->execute([$eid]);$etablissement=$s->fetch(PDO::FETCH_ASSOC);
        if(!$etablissement)jsonResponse(false,'Établissement introuvable.',[],404);

        $userScope="EXISTS(SELECT 1 FROM etablissement_users eu WHERE eu.user_id=u.id AND eu.etablissement_id=?)";
        $kpis=[
            'users'=>$one("SELECT COUNT(*) FROM users u WHERE $userScope",[$eid]),
            'users_active'=>$one("SELECT COUNT(*) FROM users u WHERE $userScope AND u.statut_compte='ACTIF' AND u.actif=1",[$eid]),
            'users_pending'=>$one("SELECT COUNT(*) FROM users u WHERE $userScope AND u.statut_compte='A_ACTIVER'",[$eid]),
            'users_suspended'=>$one("SELECT COUNT(*) FROM users u WHERE $userScope AND (u.statut_compte='SUSPENDU' OR u.actif=0)",[$eid]),
            'roles'=>$one("SELECT COUNT(DISTINCT role_id) FROM role_assignments WHERE etablissement_id=? AND actif=1",[$eid]),
            'permissions'=>$one("SELECT COUNT(*) FROM permissions WHERE actif=1"),
            'assignments_active'=>$one("SELECT COUNT(*) FROM role_assignments WHERE etablissement_id=? AND actif=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW())",[$eid]),
            'assignments_revoked'=>$one("SELECT COUNT(*) FROM role_assignments WHERE etablissement_id=? AND actif=0",[$eid]),
            'establishments'=>1,'adhesion_pending'=>0
        ];
        $activity=[
            'new_users'=>$one("SELECT COUNT(*) FROM users u WHERE $userScope AND ".$since('u.created_at'),[$eid]),
            'new_establishments'=>0,
            'new_assignments'=>$one("SELECT COUNT(*) FROM role_assignments ra WHERE ra.etablissement_id=? AND ".$since('ra.created_at'),[$eid]),
            'new_adhesions'=>0
        ];
        $accounts=$rows("SELECT u.statut_compte label,COUNT(*) total FROM users u WHERE $userScope GROUP BY u.statut_compte ORDER BY total DESC",[$eid]);
        $roles=$rows("SELECT r.code,r.nom,COUNT(DISTINCT ra.user_id) total FROM roles r JOIN role_assignments ra ON ra.role_id=r.id WHERE ra.etablissement_id=? AND ra.actif=1 AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW()) GROUP BY r.id ORDER BY total DESC,r.nom",[$eid]);
        $scopes=$rows("SELECT scope_type label,COUNT(*) total FROM role_assignments WHERE etablissement_id=? AND actif=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) GROUP BY scope_type ORDER BY total DESC",[$eid]);
        $types=[['label'=>$etablissement['type_etablissement'],'total'=>1]];
        $top=[[
            'id'=>$eid,'code'=>$etablissement['code'],'nom'=>$etablissement['nom'],
            'type_etablissement'=>$etablissement['type_etablissement'],
            'users_count'=>$kpis['users'],'assignments_count'=>$kpis['assignments_active']
        ]];
        $adhesions=[];
        $userTrend=$rows("SELECT DATE(u.created_at) jour,COUNT(*) total FROM users u WHERE $userScope AND ".$since('u.created_at')." GROUP BY DATE(u.created_at) ORDER BY jour",[$eid]);
        $assignmentTrend=$rows("SELECT DATE(ra.created_at) jour,COUNT(*) total FROM role_assignments ra WHERE ra.etablissement_id=? AND ".$since('ra.created_at')." GROUP BY DATE(ra.created_at) ORDER BY jour",[$eid]);
    }

    /* =========================================================
       2. SCOPE DES STATISTIQUES DE STAGE
       ========================================================= */
    $stageWhere=[];$stageParams=[];
    if($platformScope){
        $stageWhere[]='1=1';
    }elseif($actor==='ADMIN_ACCUEIL'){
        $stageWhere[]='pl.host_etablissement_id=?';$stageParams[]=$eid;
    }else{
        $stageWhere[]='c.owner_etablissement_id=?';$stageParams[]=$eid;
    }

    $stageWhere[]=$stagePeriod('pl');

    if($campaignId){$stageWhere[]='c.id=?';$stageParams[]=$campaignId;}
    if($stageTypeId){$stageWhere[]='c.stage_type_id=?';$stageParams[]=$stageTypeId;}
    if($hostId && ($platformScope||$actor==='ADMIN_ETABLISSEMENT')){
        $stageWhere[]='pl.host_etablissement_id=?';$stageParams[]=$hostId;
    }
    if($universityId && $platformScope){
        $stageWhere[]='c.owner_etablissement_id=?';$stageParams[]=$universityId;
    }
    if($stageStatus!==''){$stageWhere[]='pl.statut=?';$stageParams[]=$stageStatus;}

    $stageWhereSql=implode(' AND ',$stageWhere);

    $baseJoin="
        FROM stage_placements pl
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        INNER JOIN stage_types st ON st.id=c.stage_type_id
        INNER JOIN student_profiles sp ON sp.id=pl.student_id
        INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
        INNER JOIN etablissements uni ON uni.id=c.owner_etablissement_id
    ";

    /* =========================================================
       3. KPI STAGES
       ========================================================= */
    $stageBase=$row("
        SELECT
            COUNT(DISTINCT pl.student_id) students,
            COUNT(DISTINCT pl.id) placements,
            COUNT(DISTINCT c.id) campaigns,
            COUNT(DISTINCT pl.host_etablissement_id) hosts,
            SUM(pl.statut='CONFIRME') confirmed,
            SUM(pl.statut='TERMINE') finished,
            SUM(pl.statut='ANNULE') cancelled,
            SUM(pl.statut='CONFIRME' AND pl.date_debut<=CURDATE() AND (pl.date_fin IS NULL OR pl.date_fin>=CURDATE())) active_now
        $baseJoin
        WHERE $stageWhereSql
    ",$stageParams);

    $attendance=['total'=>0,'present'=>0,'absent'=>0,'rate'=>0];
    if($tableExists('stage_attendances')){
        $aw=$stageWhere;$ap=$stageParams;$aw[]=$datePeriod('a.date_presence');
        $a=$row("
            SELECT
                COUNT(*) total,
                SUM(a.statut IN('PRESENT','RETARD','GARDE')) present,
                SUM(a.statut='ABSENT') absent
            FROM stage_attendances a
            INNER JOIN stage_assignments sa ON sa.id=a.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE ".implode(' AND ',$aw)."
        ",$ap);
        $attendance=[
            'total'=>(int)($a['total']??0),
            'present'=>(int)($a['present']??0),
            'absent'=>(int)($a['absent']??0),
            'rate'=>(int)($a['total']??0)>0?round(((int)$a['present']/(int)$a['total'])*100,1):0
        ];
    }

    $tasks=['total'=>0,'validated'=>0,'review'=>0,'rate'=>0];
    if($tableExists('stage_student_tasks')){
        $t=$row("
            SELECT
                SUM(t.statut<>'ANNULEE') total,
                SUM(t.statut='VALIDEE') validated,
                SUM(t.statut='A_REVOIR') review
            FROM stage_student_tasks t
            INNER JOIN stage_assignments sa ON sa.id=t.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE $stageWhereSql
        ",$stageParams);
        $tasks=[
            'total'=>(int)($t['total']??0),
            'validated'=>(int)($t['validated']??0),
            'review'=>(int)($t['review']??0),
            'rate'=>(int)($t['total']??0)>0?round(((int)$t['validated']/(int)$t['total'])*100,1):0
        ];
    }

    $journalValidated=0;
    if($tableExists('stage_logbook_entries') && $columnExists('stage_logbook_entries','rotation_id')){
        $journalValidated=$one("
            SELECT COUNT(*)
            FROM stage_logbook_entries l
            INNER JOIN stage_rotations r ON r.id=l.rotation_id
            INNER JOIN stage_assignments sa ON sa.id=r.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE l.statut='VALIDE' AND $stageWhereSql
        ",$stageParams);
    }

    $evaluationsFinalized=0;
    if($tableExists('stage_evaluations')){
        if($columnExists('stage_evaluations','rotation_id')){
            $evaluationsFinalized=$one("
                SELECT COUNT(*)
                FROM stage_evaluations ev
                INNER JOIN stage_rotations r ON r.id=ev.rotation_id
                INNER JOIN stage_assignments sa ON sa.id=r.assignment_id
                INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
                INNER JOIN stage_placements pl ON pl.id=ad.placement_id
                INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
                WHERE ev.statut='FINALISEE' AND $stageWhereSql
            ",$stageParams);
        }elseif($columnExists('stage_evaluations','assignment_id')){
            $evaluationsFinalized=$one("
                SELECT COUNT(*)
                FROM stage_evaluations ev
                INNER JOIN stage_assignments sa ON sa.id=ev.assignment_id
                INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
                INNER JOIN stage_placements pl ON pl.id=ad.placement_id
                INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
                WHERE ev.statut='FINALISEE' AND $stageWhereSql
            ",$stageParams);
        }
    }

    $signedConventions=0;
    if($tableExists('stage_conventions')){
        $signedConventions=$one("
            SELECT COUNT(*)
            FROM stage_conventions cv
            INNER JOIN stage_placements pl ON pl.id=cv.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE cv.statut IN('SIGNEE','ARCHIVEE') AND $stageWhereSql
        ",$stageParams);
    }

    $rotations=0;
    if($tableExists('stage_rotations')){
        $rotations=$one("
            SELECT COUNT(*)
            FROM stage_rotations r
            INNER JOIN stage_assignments sa ON sa.id=r.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE r.statut<>'ANNULEE' AND $stageWhereSql
        ",$stageParams);
    }

    $stageKpis=[
        'students'=>(int)($stageBase['students']??0),
        'placements'=>(int)($stageBase['placements']??0),
        'campaigns'=>(int)($stageBase['campaigns']??0),
        'hosts'=>(int)($stageBase['hosts']??0),
        'active_now'=>(int)($stageBase['active_now']??0),
        'finished'=>(int)($stageBase['finished']??0),
        'cancelled'=>(int)($stageBase['cancelled']??0),
        'attendance_rate'=>$attendance['rate'],
        'task_progress'=>$tasks['rate'],
        'tasks_validated'=>$tasks['validated'],
        'journals_validated'=>$journalValidated,
        'evaluations_finalized'=>$evaluationsFinalized,
        'conventions_signed'=>$signedConventions,
        'rotations'=>$rotations
    ];

    /* =========================================================
       4. DÉTAIL PAR STAGIAIRE
       ========================================================= */
    $evalExpr="'—'";
    if($tableExists('stage_evaluations')){
        if($columnExists('stage_evaluations','rotation_id')){
            $evalExpr="IF(EXISTS(
                SELECT 1 FROM stage_evaluations ev
                INNER JOIN stage_rotations rr ON rr.id=ev.rotation_id
                INNER JOIN stage_assignments saa ON saa.id=rr.assignment_id
                INNER JOIN stage_admissions ada ON ada.id=saa.admission_id
                WHERE ada.placement_id=pl.id AND ev.statut='FINALISEE'
            ),'FINALISEE','—')";
        }elseif($columnExists('stage_evaluations','assignment_id')){
            $evalExpr="IF(EXISTS(
                SELECT 1 FROM stage_evaluations ev
                INNER JOIN stage_assignments saa ON saa.id=ev.assignment_id
                INNER JOIN stage_admissions ada ON ada.id=saa.admission_id
                WHERE ada.placement_id=pl.id AND ev.statut='FINALISEE'
            ),'FINALISEE','—')";
        }
    }

    $studentRows=$rows("
        SELECT
            pl.id placement_id,pl.statut,pl.date_debut,pl.date_fin,
            sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
            c.code campaign_code,c.titre campaign_title,
            st.libelle stage_type,
            uni.nom university_name,host.nom host_name,
            COALESCE((
                SELECT ROUND(100*SUM(a.statut IN('PRESENT','RETARD','GARDE'))/NULLIF(COUNT(*),0),1)
                FROM stage_attendances a
                INNER JOIN stage_assignments saa ON saa.id=a.assignment_id
                INNER JOIN stage_admissions ada ON ada.id=saa.admission_id
                WHERE ada.placement_id=pl.id
            ),0) attendance_rate,
            COALESCE((
                SELECT ROUND(100*SUM(t.statut='VALIDEE')/NULLIF(SUM(t.statut<>'ANNULEE'),0),1)
                FROM stage_student_tasks t
                INNER JOIN stage_assignments sat ON sat.id=t.assignment_id
                INNER JOIN stage_admissions adt ON adt.id=sat.admission_id
                WHERE adt.placement_id=pl.id
            ),0) task_progress,
            IF(EXISTS(
                SELECT 1 FROM stage_conventions cv
                WHERE cv.placement_id=pl.id AND cv.statut IN('SIGNEE','ARCHIVEE')
            ),'SIGNEE','—') convention_status,
            $evalExpr evaluation_status
        $baseJoin
        WHERE $stageWhereSql
        ORDER BY (pl.statut='CONFIRME') DESC,pl.date_debut DESC,sp.nom,sp.postnom,sp.prenom
        LIMIT 100
    ",$stageParams);

    foreach($studentRows as &$x)
        $x['student_name']=trim(implode(' ',array_filter([$x['nom'],$x['postnom'],$x['prenom']])));
    unset($x);

    /* =========================================================
       5. AGRÉGATS
       ========================================================= */
    $campaignRows=$rows("
        SELECT c.id,c.code,c.titre,st.libelle stage_type,
               COUNT(DISTINCT pl.id) placements,
               COUNT(DISTINCT pl.student_id) students,
               SUM(pl.statut='CONFIRME') confirmed,
               SUM(pl.statut='TERMINE') finished
        $baseJoin
        WHERE $stageWhereSql
        GROUP BY c.id,c.code,c.titre,st.libelle
        ORDER BY students DESC,c.titre
        LIMIT 20
    ",$stageParams);

    $hostRows=$rows("
        SELECT host.id,host.code,host.nom,
               COUNT(DISTINCT pl.id) placements,
               COUNT(DISTINCT pl.student_id) students,
               SUM(pl.statut='CONFIRME') confirmed,
               SUM(pl.statut='TERMINE') finished
        $baseJoin
        WHERE $stageWhereSql
        GROUP BY host.id,host.code,host.nom
        ORDER BY students DESC,host.nom
        LIMIT 20
    ",$stageParams);

    $stageTypeRows=$rows("
        SELECT st.id,st.libelle label,COUNT(DISTINCT pl.id) total
        $baseJoin
        WHERE $stageWhereSql
        GROUP BY st.id,st.libelle
        ORDER BY total DESC,st.libelle
    ",$stageParams);

    $placementTrend=$rows("
        SELECT DATE(pl.created_at) jour,COUNT(*) total
        $baseJoin
        WHERE $stageWhereSql AND ".$since('pl.created_at')."
        GROUP BY DATE(pl.created_at)
        ORDER BY jour
    ",$stageParams);

    /* =========================================================
       6. OPTIONS DE FILTRE LIMITÉES AU PÉRIMÈTRE
       ========================================================= */
    if($platformScope){$filterBase='1=1';$filterParams=[];}
    elseif($actor==='ADMIN_ACCUEIL'){$filterBase='pl.host_etablissement_id=?';$filterParams=[$eid];}
    else{$filterBase='c.owner_etablissement_id=?';$filterParams=[$eid];}

    $filterCampaigns=$rows("
        SELECT DISTINCT c.id,c.code,c.titre
        FROM stage_placements pl
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        WHERE $filterBase
        ORDER BY c.titre
    ",$filterParams);

    $filterStageTypes=$rows("
        SELECT DISTINCT st.id,st.libelle
        FROM stage_placements pl
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        INNER JOIN stage_types st ON st.id=c.stage_type_id
        WHERE $filterBase
        ORDER BY st.libelle
    ",$filterParams);

    $filterHosts=[];
    if($platformScope||$actor==='ADMIN_ETABLISSEMENT'){
        $filterHosts=$rows("
            SELECT DISTINCT h.id,h.code,h.nom
            FROM stage_placements pl
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            INNER JOIN etablissements h ON h.id=pl.host_etablissement_id
            WHERE $filterBase
            ORDER BY h.nom
        ",$filterParams);
    }

    $filterUniversities=[];
    if($platformScope){
        $filterUniversities=$rows("
            SELECT DISTINCT u.id,u.code,u.nom
            FROM stage_placements pl
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            INNER JOIN etablissements u ON u.id=c.owner_etablissement_id
            ORDER BY u.nom
        ");
    }

    jsonResponse(true,'',[
        'period'=>$period,'scope'=>$platformScope?'PLATFORM':'ORGANIZATION',
        'actor'=>$actor,'national'=>$national,'etablissement'=>$etablissement,
        'kpis'=>$kpis,'activity'=>$activity,'accounts'=>$accounts,'roles'=>$roles,'scopes'=>$scopes,
        'establishment_types'=>$types,'top_establishments'=>$top,'adhesions'=>$adhesions,
        'user_trend'=>$userTrend,'assignment_trend'=>$assignmentTrend,
        'stages'=>[
            'kpis'=>$stageKpis,'attendance'=>$attendance,'tasks'=>$tasks,
            'students'=>$studentRows,'campaigns'=>$campaignRows,'hosts'=>$hostRows,
            'stage_types'=>$stageTypeRows,'placement_trend'=>$placementTrend,
            'filters'=>[
                'campaigns'=>$filterCampaigns,
                'stage_types'=>$filterStageTypes,
                'hosts'=>$filterHosts,
                'universities'=>$filterUniversities
            ]
        ]
    ]);
}catch(Throwable $e){
    error_log('[ADMIN STATS] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

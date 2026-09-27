<?php
/*
 * STAGIA-RDC — Données des exports Rapports & statistiques
 * ---------------------------------------------------------
 * Même périmètre que la page :
 * - SUPER_ADMIN / MINISTERE / ORDRE_MEDECINS : plateforme
 * - ADMIN_ETABLISSEMENT : campagnes de son établissement
 * - ADMIN_ACCUEIL : placements accueillis par son établissement
 */

function stageStatsTableExists(PDO $pdo,string $table):bool{
    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
    ");
    $s->execute([$table]);
    return (int)$s->fetchColumn()>0;
}

function stageStatsColumnExists(PDO $pdo,string $table,string $column):bool{
    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE()
          AND TABLE_NAME=?
          AND COLUMN_NAME=?
    ");
    $s->execute([$table,$column]);
    return (int)$s->fetchColumn()>0;
}

function stageStatsRows(PDO $pdo,string $sql,array $params=[]):array{
    $s=$pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function stageStatsRow(PDO $pdo,string $sql,array $params=[]):array{
    $s=$pdo->prepare($sql);
    $s->execute($params);
    return $s->fetch(PDO::FETCH_ASSOC)?:[];
}

function stageStatsOne(PDO $pdo,string $sql,array $params=[]):int{
    $s=$pdo->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
}

function stageStatsActor(PDO $pdo):array{
    $role=$_SESSION['role_code']??'';
    $allowed=['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL','MINISTERE','ORDRE_MEDECINS'];

    if(!in_array($role,$allowed,true))
        throw new RuntimeException('Accès non autorisé.');

    $super=$role==='SUPER_ADMIN';
    $national=in_array($role,['MINISTERE','ORDRE_MEDECINS'],true);
    $platform=$super||$national;

    if(!$platform && function_exists('contextPermission') && !contextPermission('report.view'))
        throw new RuntimeException('Permission insuffisante.');

    $eid=0;
    $scopeLabel='Plateforme nationale';

    if(!$platform){
        $eid=(int)currentEtablissementId($pdo);
        if(!$eid)throw new RuntimeException('Aucun établissement associé à votre compte.');

        $s=$pdo->prepare("SELECT nom FROM etablissements WHERE id=? LIMIT 1");
        $s->execute([$eid]);
        $name=(string)$s->fetchColumn();
        if($name==='')throw new RuntimeException('Établissement introuvable.');

        $scopeLabel=$name;
    }elseif($role==='MINISTERE'){
        $scopeLabel='Ministère de la Santé — vue nationale';
    }elseif($role==='ORDRE_MEDECINS'){
        $scopeLabel='Ordre des Médecins — supervision nationale';
    }

    return compact('role','super','national','platform','eid','scopeLabel');
}

function stageStatsFilters(PDO $pdo,array $actor):array{
    $period=(string)($_GET['period']??'30');
    if(!in_array($period,['7','30','90','365','all'],true))$period='30';

    $campaignId=(int)($_GET['campaign_id']??0);
    $stageTypeId=(int)($_GET['stage_type_id']??0);
    $hostId=(int)($_GET['host_id']??0);
    $universityId=(int)($_GET['university_id']??0);

    $status=strtoupper(trim((string)($_GET['stage_status']??'')));
    if(!in_array($status,['CONFIRME','TERMINE','ANNULE'],true))$status='';

    $where=[];
    $params=[];

    if($actor['platform']){
        $where[]='1=1';
    }elseif($actor['role']==='ADMIN_ACCUEIL'){
        $where[]='pl.host_etablissement_id=?';
        $params[]=$actor['eid'];
    }else{
        $where[]='c.owner_etablissement_id=?';
        $params[]=$actor['eid'];
    }

    if($period!=='all'){
        $days=(int)$period;
        $where[]="pl.date_debut<=CURDATE()
                  AND COALESCE(pl.date_fin,CURDATE())>=DATE_SUB(CURDATE(),INTERVAL $days DAY)";
    }

    if($campaignId){
        $where[]='c.id=?';$params[]=$campaignId;
    }
    if($stageTypeId){
        $where[]='c.stage_type_id=?';$params[]=$stageTypeId;
    }
    if($hostId && ($actor['platform']||$actor['role']==='ADMIN_ETABLISSEMENT')){
        $where[]='pl.host_etablissement_id=?';$params[]=$hostId;
    }
    if($universityId && $actor['platform']){
        $where[]='c.owner_etablissement_id=?';$params[]=$universityId;
    }
    if($status!==''){
        $where[]='pl.statut=?';$params[]=$status;
    }

    $labels=[
        'Période'=>match($period){
            '7'=>'7 derniers jours',
            '30'=>'30 derniers jours',
            '90'=>'90 derniers jours',
            '365'=>'12 derniers mois',
            default=>'Depuis le début'
        },
        'Périmètre'=>$actor['scopeLabel'],
        'Établissement de formation'=>'Tous',
        'Type de stage'=>'Tous',
        'Campagne'=>'Toutes',
        'Établissement d’accueil'=>'Tous',
        'Statut'=>$status!==''?$status:'Tous'
    ];

    $label=function(string $sql,array $p)use($pdo):string{
        $s=$pdo->prepare($sql);$s->execute($p);
        return (string)($s->fetchColumn()?:'');
    };

    if($universityId && $actor['platform'])
        $labels['Établissement de formation']=$label("SELECT nom FROM etablissements WHERE id=? LIMIT 1",[$universityId]);

    if($stageTypeId)
        $labels['Type de stage']=$label("SELECT libelle FROM stage_types WHERE id=? LIMIT 1",[$stageTypeId]);

    if($campaignId)
        $labels['Campagne']=$label("SELECT titre FROM stage_campaigns WHERE id=? LIMIT 1",[$campaignId]);

    if($hostId && ($actor['platform']||$actor['role']==='ADMIN_ETABLISSEMENT'))
        $labels['Établissement d’accueil']=$label("SELECT nom FROM etablissements WHERE id=? LIMIT 1",[$hostId]);

    return [
        'period'=>$period,
        'campaign_id'=>$campaignId,
        'stage_type_id'=>$stageTypeId,
        'host_id'=>$hostId,
        'university_id'=>$universityId,
        'stage_status'=>$status,
        'where'=>implode(' AND ',$where),
        'params'=>$params,
        'labels'=>$labels
    ];
}

function stageStatsExportData(PDO $pdo):array{
    $actor=stageStatsActor($pdo);
    $f=stageStatsFilters($pdo,$actor);
    $where=$f['where'];$params=$f['params'];

    $base="
        FROM stage_placements pl
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        INNER JOIN stage_types st ON st.id=c.stage_type_id
        INNER JOIN student_profiles sp ON sp.id=pl.student_id
        INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
        INNER JOIN etablissements uni ON uni.id=c.owner_etablissement_id
    ";

    /* Synthèse */
    $summary=stageStatsRow($pdo,"
        SELECT
            COUNT(DISTINCT pl.student_id) students,
            COUNT(DISTINCT pl.id) placements,
            COUNT(DISTINCT c.id) campaigns,
            COUNT(DISTINCT pl.host_etablissement_id) hosts,
            SUM(pl.statut='CONFIRME' AND pl.date_debut<=CURDATE()
                AND (pl.date_fin IS NULL OR pl.date_fin>=CURDATE())) active_now,
            SUM(pl.statut='TERMINE') finished,
            SUM(pl.statut='ANNULE') cancelled
        $base
        WHERE $where
    ",$params);

    $attendanceRate=0;$attendanceCount=0;
    if(stageStatsTableExists($pdo,'stage_attendances')){
        $dateWhere='';
        if($f['period']!=='all')
            $dateWhere=' AND a.date_presence>=DATE_SUB(CURDATE(),INTERVAL '.(int)$f['period'].' DAY)';

        $a=stageStatsRow($pdo,"
            SELECT COUNT(*) total,
                   SUM(a.statut IN('PRESENT','RETARD','GARDE')) present
            FROM stage_attendances a
            INNER JOIN stage_assignments sa ON sa.id=a.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE $where $dateWhere
        ",$params);

        $attendanceCount=(int)($a['total']??0);
        $attendanceRate=$attendanceCount>0
            ?round(((int)($a['present']??0)/$attendanceCount)*100,1)
            :0;
    }

    $taskProgress=0;$tasksValidated=0;
    if(stageStatsTableExists($pdo,'stage_student_tasks')){
        $t=stageStatsRow($pdo,"
            SELECT SUM(t.statut<>'ANNULEE') total,
                   SUM(t.statut='VALIDEE') validated
            FROM stage_student_tasks t
            INNER JOIN stage_assignments sa ON sa.id=t.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE $where
        ",$params);
        $taskTotal=(int)($t['total']??0);
        $tasksValidated=(int)($t['validated']??0);
        $taskProgress=$taskTotal>0?round($tasksValidated/$taskTotal*100,1):0;
    }

    $journalsValidated=0;
    if(stageStatsTableExists($pdo,'stage_logbook_entries')){
        $journalsValidated=stageStatsOne($pdo,"
            SELECT COUNT(*)
            FROM stage_logbook_entries l
            INNER JOIN stage_rotations r ON r.id=l.rotation_id
            INNER JOIN stage_assignments sa ON sa.id=r.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE l.statut='VALIDE' AND $where
        ",$params);
    }

    $evaluationsFinalized=0;
    if(stageStatsTableExists($pdo,'stage_evaluations')){
        $evaluationsFinalized=stageStatsOne($pdo,"
            SELECT COUNT(*)
            FROM stage_evaluations ev
            INNER JOIN stage_assignments sa ON sa.id=ev.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE ev.statut='FINALISEE' AND $where
        ",$params);
    }

    $conventionsSigned=0;
    if(stageStatsTableExists($pdo,'stage_conventions')){
        $conventionsSigned=stageStatsOne($pdo,"
            SELECT COUNT(*)
            FROM stage_conventions cv
            INNER JOIN stage_placements pl ON pl.id=cv.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE cv.statut IN('SIGNEE','ARCHIVEE') AND $where
        ",$params);
    }

    $rotations=0;
    if(stageStatsTableExists($pdo,'stage_rotations')){
        $rotations=stageStatsOne($pdo,"
            SELECT COUNT(*)
            FROM stage_rotations r
            INNER JOIN stage_assignments sa ON sa.id=r.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            WHERE r.statut<>'ANNULEE' AND $where
        ",$params);
    }

    $kpi=[
        'Stagiaires'=>(int)($summary['students']??0),
        'Placements'=>(int)($summary['placements']??0),
        'Stages en cours'=>(int)($summary['active_now']??0),
        'Stages terminés'=>(int)($summary['finished']??0),
        'Présence moyenne'=>$attendanceRate.'%',
        'Progression tâches'=>$taskProgress.'%',
        'Tâches validées'=>$tasksValidated,
        'Journaux validés'=>$journalsValidated,
        'Évaluations finalisées'=>$evaluationsFinalized,
        'Conventions signées'=>$conventionsSigned,
        'Rotations'=>$rotations,
        'Campagnes'=>(int)($summary['campaigns']??0)
    ];

    /* Stagiaires */
    $evalExpr="'—'";
    if(stageStatsTableExists($pdo,'stage_evaluations')){
        $evalExpr="IF(EXISTS(
            SELECT 1
            FROM stage_evaluations ev
            INNER JOIN stage_assignments sae ON sae.id=ev.assignment_id
            INNER JOIN stage_admissions ade ON ade.id=sae.admission_id
            WHERE ade.placement_id=pl.id AND ev.statut='FINALISEE'
        ),'FINALISEE','—')";
    }

    $students=stageStatsRows($pdo,"
        SELECT
            sp.stagia_code,
            TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)) student_name,
            c.code campaign_code,c.titre campaign_title,
            st.libelle stage_type,
            uni.nom university_name,host.nom host_name,
            pl.date_debut,pl.date_fin,pl.statut,
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
            $evalExpr evaluation_status,
            IF(EXISTS(
                SELECT 1 FROM stage_conventions cv
                WHERE cv.placement_id=pl.id AND cv.statut IN('SIGNEE','ARCHIVEE')
            ),'SIGNEE','—') convention_status
        $base
        WHERE $where
        ORDER BY sp.nom,sp.postnom,sp.prenom,pl.date_debut DESC
        LIMIT 2000
    ",$params);

    /* Campagnes / accueils */
    $campaigns=stageStatsRows($pdo,"
        SELECT c.code,c.titre,st.libelle stage_type,uni.nom university_name,
               COUNT(DISTINCT pl.student_id) students,
               SUM(pl.statut='CONFIRME') confirmed,
               SUM(pl.statut='TERMINE') finished,
               SUM(pl.statut='ANNULE') cancelled
        $base
        WHERE $where
        GROUP BY c.id,c.code,c.titre,st.libelle,uni.nom
        ORDER BY students DESC,c.titre
    ",$params);

    $hosts=stageStatsRows($pdo,"
        SELECT host.code,host.nom,
               COUNT(DISTINCT pl.student_id) students,
               COUNT(DISTINCT c.id) campaigns,
               SUM(pl.statut='CONFIRME') confirmed,
               SUM(pl.statut='TERMINE') finished
        $base
        WHERE $where
        GROUP BY host.id,host.code,host.nom
        ORDER BY students DESC,host.nom
    ",$params);

    /* Présences */
    $attendance=[];
    if(stageStatsTableExists($pdo,'stage_attendances')){
        $dateWhere='';
        if($f['period']!=='all')
            $dateWhere=' AND a.date_presence>=DATE_SUB(CURDATE(),INTERVAL '.(int)$f['period'].' DAY)';

        $attendance=stageStatsRows($pdo,"
            SELECT
                sp.stagia_code,
                TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)) student_name,
                c.titre campaign_title,host.nom host_name,
                hu.nom unit_name,
                a.date_presence,a.heure_arrivee,a.heure_depart,
                a.statut,a.minutes_retard,a.source,a.justification,a.observation
            FROM stage_attendances a
            INNER JOIN stage_assignments sa ON sa.id=a.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            INNER JOIN student_profiles sp ON sp.id=pl.student_id
            INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
            LEFT JOIN stage_rotations r ON r.id=a.rotation_id
            LEFT JOIN host_units hu ON hu.id=COALESCE(r.host_unit_id,sa.host_unit_id)
            WHERE $where $dateWhere
            ORDER BY a.date_presence DESC,student_name
            LIMIT 5000
        ",$params);
    }

    /* Tâches */
    $tasks=[];
    if(stageStatsTableExists($pdo,'stage_student_tasks')){
        $tasks=stageStatsRows($pdo,"
            SELECT
                sp.stagia_code,
                TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)) student_name,
                c.titre campaign_title,host.nom host_name,
                hu.nom unit_name,
                t.titre,t.priorite,t.date_echeance,t.statut,
                t.started_at,t.completed_at,t.validated_at,
                t.commentaire_stagiaire,t.commentaire_encadreur
            FROM stage_student_tasks t
            INNER JOIN stage_assignments sa ON sa.id=t.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            INNER JOIN student_profiles sp ON sp.id=pl.student_id
            INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
            LEFT JOIN stage_rotations r ON r.id=t.rotation_id
            LEFT JOIN host_units hu ON hu.id=COALESCE(r.host_unit_id,sa.host_unit_id)
            WHERE $where
            ORDER BY student_name,t.date_echeance,t.id
            LIMIT 5000
        ",$params);
    }

    /* Évaluations */
    $evaluations=[];
    if(stageStatsTableExists($pdo,'stage_evaluations')){
        $finalizedAt=stageStatsColumnExists($pdo,'stage_evaluations','finalized_at')
            ?'ev.finalized_at'
            :'NULL';

        $evaluations=stageStatsRows($pdo,"
            SELECT
                sp.stagia_code,
                TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)) student_name,
                c.titre campaign_title,host.nom host_name,
                hu.nom unit_name,
                ev.type_evaluation,ev.statut,ev.note_finale,
                ev.appreciation,ev.points_forts,ev.axes_amelioration,
                ev.evaluated_at,ev.validated_at,$finalizedAt finalized_at
            FROM stage_evaluations ev
            INNER JOIN stage_assignments sa ON sa.id=ev.assignment_id
            INNER JOIN stage_admissions ad ON ad.id=sa.admission_id
            INNER JOIN stage_placements pl ON pl.id=ad.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            INNER JOIN student_profiles sp ON sp.id=pl.student_id
            INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
            LEFT JOIN stage_rotations r ON r.id=ev.rotation_id
            LEFT JOIN host_units hu ON hu.id=COALESCE(r.host_unit_id,sa.host_unit_id)
            WHERE $where
            ORDER BY student_name,ev.created_at DESC
            LIMIT 5000
        ",$params);
    }

    /* Conventions */
    $conventions=[];
    if(stageStatsTableExists($pdo,'stage_conventions')){
        $conventions=stageStatsRows($pdo,"
            SELECT
                cv.reference,cv.titre,cv.version,cv.statut,
                sp.stagia_code,
                TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)) student_name,
                c.titre campaign_title,uni.nom university_name,host.nom host_name,
                pl.date_debut,pl.date_fin,
                cv.date_emission,cv.date_signature_etudiant,
                cv.date_signature_universite,cv.date_signature_accueil,
                cv.signed_at,cv.archived_at
            FROM stage_conventions cv
            INNER JOIN stage_placements pl ON pl.id=cv.placement_id
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            INNER JOIN student_profiles sp ON sp.id=pl.student_id
            INNER JOIN etablissements uni ON uni.id=c.owner_etablissement_id
            INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
            WHERE $where
            ORDER BY cv.created_at DESC
            LIMIT 5000
        ",$params);
    }

    return [
        'actor'=>$actor,
        'filters'=>$f,
        'kpi'=>$kpi,
        'students'=>$students,
        'campaigns'=>$campaigns,
        'hosts'=>$hosts,
        'attendance'=>$attendance,
        'tasks'=>$tasks,
        'evaluations'=>$evaluations,
        'conventions'=>$conventions
    ];
}

function stageStatsExportFileSuffix(array $data):string{
    $role=strtolower((string)$data['actor']['role']);
    $period=(string)$data['filters']['period'];
    return preg_replace('/[^a-z0-9_-]+/','-',"$role-$period-".date('Ymd-His'));
}

function stageStatsEsc(?string $v):string{
    return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
}

function stageStatsExcelSafe($v){
    if($v===null)return '';
    if(is_bool($v))return $v?'Oui':'Non';
    if(is_numeric($v))return $v;

    $s=(string)$v;
    if($s!=='' && in_array($s[0],['=','+','-','@'],true))
        $s="'".$s;

    return $s;
}

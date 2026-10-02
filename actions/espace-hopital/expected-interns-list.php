<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE']);

function ei_col(PDO $pdo,string $table,string $column):bool{
    static $cache=[];$k=$table.'.'.$column;
    if(array_key_exists($k,$cache))return $cache[$k];
    try{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $s->execute([$table,$column]);
        return $cache[$k]=(int)$s->fetchColumn()>0;
    }catch(Throwable $e){return $cache[$k]=false;}
}

function ei_label_expr(PDO $pdo,string $table,string $alias,array $cols,string $fallback="''"):string{
    $parts=[];
    foreach($cols as $c)if(ei_col($pdo,$table,$c))$parts[]="NULLIF($alias.`$c`,'')";
    return $parts?'COALESCE('.implode(',',$parts).','.$fallback.')':$fallback;
}

try{
    $hostId=(int)currentEtablissementId($pdo);
    if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);

    $campaignId=(int)($_GET['campaign_id']??0);
    $universityId=(int)($_GET['university_id']??0);
    $promotionId=(int)($_GET['promotion_id']??0);
    $status=strtoupper(trim((string)($_GET['status']??'ALL')));
    $q=trim((string)($_GET['q']??''));
    $page=max(1,(int)($_GET['page']??1));
    $perPage=max(20,min(100,(int)($_GET['per_page']??50)));
    $offset=($page-1)*$perPage;

    if(!in_array($status,['ATTENDU','ADMIS','ALL'],true))$status='ALL';

    $promoName=ei_label_expr($pdo,'promotions','p',['nom','code','niveau','libelle'],'NULL');
    $studentCode=ei_label_expr($pdo,'student_profiles','sp',['stagia_code','matricule_academique','matricule','code'],"CONCAT('STG-ETU-',LPAD(COALESCE(sp.id,se.student_id,0),8,'0'))");

    $base="
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE'
        JOIN stage_placements pl ON pl.id=ad.placement_id
            AND pl.reservation_id=sr.id
            AND pl.statut='CONFIRME'
        LEFT JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN etablissements u ON u.id=c.owner_etablissement_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN promotions p ON p.id=ae.promotion_id
        LEFT JOIN host_units cu ON cu.id=ad.coordination_unit_id
        LEFT JOIN stage_assignments ass ON ass.id=(
            SELECT sa2.id
            FROM stage_assignments sa2
            WHERE sa2.admission_id=ad.id
              AND sa2.host_etablissement_id=?
              AND sa2.statut<>'ANNULEE'
            ORDER BY sa2.id DESC
            LIMIT 1
        )
        LEFT JOIN host_units hu ON hu.id=ass.host_unit_id
    ";

    $where=['ad.host_etablissement_id=?',"ad.statut<>'ANNULE'"];
    $params=[$hostId,$hostId]; // 1er hostId pour sous-requête assignment, 2e pour WHERE admission

    if($campaignId){$where[]='app.campaign_id=?';$params[]=$campaignId;}
    if($universityId){$where[]='c.owner_etablissement_id=?';$params[]=$universityId;}
    if($promotionId){$where[]='ae.promotion_id=?';$params[]=$promotionId;}
    if($status==='ATTENDU')$where[]="ad.statut='ATTENDU'";
    elseif($status==='ADMIS')$where[]="ad.statut IN('ADMIS','EN_COURS')";

    if($q!==''){
        $where[]="(sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ? OR c.code LIKE ? OR c.titre LIKE ? OR u.nom LIKE ? OR $studentCode LIKE ?)";
        $like='%'.$q.'%';
        for($i=0;$i<7;$i++)$params[]=$like;
    }

    $whereSql=implode(' AND ',$where);

    /* Liste opérationnelle : on affiche seulement les stagiaires encore à traiter par l'administration.
       Dès qu'ils sont envoyés en coordination ou déjà affectés à un service, ils disparaissent de cette page.
       Les compteurs KPI restent globaux. */
    $listWhere=$where;
    $listWhere[]='ad.coordination_unit_id IS NULL';
    $listWhere[]='ass.id IS NULL';
    $listWhereSql=implode(' AND ',$listWhere);

    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT ad.id) $base WHERE $listWhereSql");
    $stmt->execute($params);
    $total=(int)$stmt->fetchColumn();

    $pages=max(1,(int)ceil($total/$perPage));
    if($page>$pages){$page=$pages;$offset=($page-1)*$perPage;}

    $stmt=$pdo->prepare("
        SELECT
            ad.id admission_id,
            ad.uuid admission_uuid,
            ad.statut admission_status,
            ad.admitted_at,
            ad.created_at admission_created_at,
            ad.coordination_unit_id,
            ad.coordination_sent_at,
            sr.id reservation_id,
            COALESCE(sr.statut,'') reservation_status,
            app.id application_id,
            app.campaign_id,
            app.academic_enrollment_id,
            c.code campaign_code,
            c.titre campaign_title,
            c.date_debut,
            c.date_fin,
            u.id university_id,
            u.nom university_name,
            se.student_id,
            $studentCode stagia_code,
            sp.nom,
            sp.postnom,
            sp.prenom,
            p.id promotion_id,
            $promoName promotion_name,
            cu.nom coordination_name,
            cu.type coordination_type,
            ass.id assignment_id,
            ass.statut assignment_status,
            ass.host_unit_id,
            hu.nom host_unit_name
        $base
        WHERE $listWhereSql
        ORDER BY CASE ad.statut WHEN 'ATTENDU' THEN 0 WHEN 'ADMIS' THEN 1 WHEN 'EN_COURS' THEN 2 ELSE 3 END,ad.id DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $kpiSql="
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE'
        JOIN stage_placements pl ON pl.id=ad.placement_id
          AND pl.reservation_id=sr.id
          AND pl.statut='CONFIRME'
        LEFT JOIN stage_assignments sa
          ON sa.admission_id=ad.id
         AND sa.host_etablissement_id=?
         AND sa.statut<>'ANNULEE'
        WHERE ad.host_etablissement_id=? AND ad.statut<>'ANNULE'
    ";
    $stmt=$pdo->prepare("
        SELECT COUNT(DISTINCT ad.id) total,
               COALESCE(SUM(ad.statut='ATTENDU'),0) attendus,
               COALESCE(SUM(ad.statut IN('ADMIS','EN_COURS')),0) admis,
               COALESCE(SUM(ad.coordination_unit_id IS NOT NULL),0) en_coordination,
               COUNT(DISTINCT sa.id) affectes
        $kpiSql
    ");
    $stmt->execute([$hostId,$hostId]);
    $kpi=$stmt->fetch(PDO::FETCH_ASSOC)?:['total'=>0,'attendus'=>0,'admis'=>0,'en_coordination'=>0,'affectes'=>0];
    foreach($kpi as $k=>$v)$kpi[$k]=(int)$v;

    $expectedWhere=['ad.host_etablissement_id=?',"ad.statut='ATTENDU'",'ad.coordination_unit_id IS NULL','ass.id IS NULL'];
    $expectedParams=[$hostId,$hostId];
    if($campaignId){$expectedWhere[]='app.campaign_id=?';$expectedParams[]=$campaignId;}
    if($universityId){$expectedWhere[]='c.owner_etablissement_id=?';$expectedParams[]=$universityId;}
    if($promotionId){$expectedWhere[]='ae.promotion_id=?';$expectedParams[]=$promotionId;}
    if($q!==''){
        $expectedWhere[]="(sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ? OR c.code LIKE ? OR c.titre LIKE ? OR u.nom LIKE ? OR $studentCode LIKE ?)";
        $like='%'.$q.'%';
        for($i=0;$i<7;$i++)$expectedParams[]=$like;
    }
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT ad.id) $base WHERE ".implode(' AND ',$expectedWhere));
    $stmt->execute($expectedParams);
    $filteredExpected=(int)$stmt->fetchColumn();

    $stmt=$pdo->prepare("
        SELECT DISTINCT c.id,CONCAT(COALESCE(c.code,''),' — ',COALESCE(c.titre,'')) label,c.date_debut
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE'
        JOIN stage_placements pl ON pl.id=ad.placement_id AND pl.reservation_id=sr.id AND pl.statut='CONFIRME'
        LEFT JOIN stage_campaigns c ON c.id=app.campaign_id
        WHERE ad.host_etablissement_id=? AND ad.statut<>'ANNULE' AND c.id IS NOT NULL
        ORDER BY c.date_debut DESC,c.id DESC
    ");
    $stmt->execute([$hostId]);
    $campaigns=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("
        SELECT DISTINCT u.id,u.nom label
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE'
        JOIN stage_placements pl ON pl.id=ad.placement_id AND pl.reservation_id=sr.id AND pl.statut='CONFIRME'
        LEFT JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN etablissements u ON u.id=c.owner_etablissement_id
        WHERE ad.host_etablissement_id=? AND ad.statut<>'ANNULE' AND u.id IS NOT NULL
        ORDER BY u.nom
    ");
    $stmt->execute([$hostId]);
    $universities=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("
        SELECT DISTINCT p.id,$promoName label
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications app ON app.id=sr.application_id AND app.statut='ACCEPTEE'
        JOIN stage_placements pl ON pl.id=ad.placement_id AND pl.reservation_id=sr.id AND pl.statut='CONFIRME'
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN promotions p ON p.id=ae.promotion_id
        WHERE ad.host_etablissement_id=? AND ad.statut<>'ANNULE' AND p.id IS NOT NULL
        ORDER BY label
    ");
    $stmt->execute([$hostId]);
    $promotions=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("
        SELECT id,code,nom,type,parent_id
        FROM host_units
        WHERE host_etablissement_id=? AND actif=1
          AND (parent_id IS NULL OR parent_id=0)
          AND UPPER(type) IN('COORDINATION','DEPARTEMENT','DÉPARTEMENT','DEPARTMENT','DIRECTION','UNITE','UNITÉ')
        ORDER BY nom
    ");
    $stmt->execute([$hostId]);
    $coordinations=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt=$pdo->prepare("
        SELECT id,code,nom,type,capacite,parent_id
        FROM host_units
        WHERE host_etablissement_id=? AND actif=1 AND UPPER(type)='SERVICE'
        ORDER BY nom
    ");
    $stmt->execute([$hostId]);
    $units=$stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'items'=>$items,
        'coordinations'=>$coordinations,
        'units'=>$units,
        'kpi'=>$kpi,
        'filtered_expected'=>$filteredExpected,
        'filters'=>[
            'campaigns'=>$campaigns,
            'universities'=>$universities,
            'promotions'=>$promotions
        ],
        'pagination'=>[
            'page'=>$page,
            'pages'=>$pages,
            'per_page'=>$perPage,
            'total'=>$total
        ],
        'permissions'=>[
            'admit'=>true,
            'send_coordination'=>true,
            'assign'=>true
        ]
    ]);
}catch(Throwable $e){
    error_log('[EXPECTED INTERNS LIST FIX PROMOTION] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur chargement stagiaires : '.$e->getMessage(),[],500);
}

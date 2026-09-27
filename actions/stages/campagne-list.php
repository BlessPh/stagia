<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';

try{
    requirePermission($pdo,'campaign.university.view');
    if(!contextAcademicEnabled())jsonResponse(false,"Cet établissement n'est pas configuré comme établissement de formation.",[],403);

    $eid=(int)($_SESSION['etablissement_id']??0);
    if(!$eid)jsonResponse(false,'Aucun établissement académique actif.',[],403);

    $search=trim((string)($_GET['search']??''));$status=trim((string)($_GET['statut']??''));
    $typeId=(int)($_GET['stage_type_id']??0);$yearId=(int)($_GET['annee_academique_id']??0);
    $where=["c.owner_etablissement_id=?","c.type_campagne='UNIVERSITAIRE'"];$params=[$eid];

    if($search!==''){$like="%$search%";$where[]='(c.code LIKE ? OR c.titre LIKE ? OR c.objectif_stage LIKE ?)';array_push($params,$like,$like,$like);}
    if($status!==''){$where[]='c.statut=?';$params[]=$status;}
    if($typeId){$where[]='c.stage_type_id=?';$params[]=$typeId;}
    if($yearId){$where[]='c.annee_academique_id=?';$params[]=$yearId;}

    $s=$pdo->prepare("
        SELECT c.id,c.uuid,c.code,c.titre,c.description,c.objectif_stage,c.stage_type_id,c.owner_faculte_id,c.annee_academique_id,
               c.date_debut,c.date_fin,c.ouverture_candidatures,c.cloture_candidatures,c.configuration,c.statut,
               c.published_at,c.cancellation_reason,c.created_at,c.updated_at,
               st.code stage_type_code,st.libelle stage_type_libelle,aa.libelle annee_libelle,fa.nom owner_faculte_nom,
               (SELECT COUNT(*) FROM stage_campaign_promotions cp WHERE cp.campaign_id=c.id) promotions_count,
               (SELECT COUNT(DISTINCT sae.enrollment_id)
                FROM stage_campaign_promotions cp
                JOIN student_academic_enrollments sae ON sae.promotion_id=cp.promotion_id AND sae.annee_academique_id=c.annee_academique_id AND sae.statut='EN_COURS'
                WHERE cp.campaign_id=c.id) eligible_students,
               (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.university_campaign_id=c.id) hosting_requests,
               (SELECT COUNT(*) FROM stage_campaign_participations p WHERE p.university_campaign_id=c.id AND p.statut='ACCEPTEE') hosting_accepted
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id
        LEFT JOIN annees_academiques aa ON aa.id=c.annee_academique_id
        LEFT JOIN facultes fa ON fa.id=c.owner_faculte_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    $campaignIds=array_map('intval',array_column($items,'id'));$promotionsByCampaign=[];$requirementsByCampaign=[];
    if($campaignIds){
        $ph=implode(',',array_fill(0,count($campaignIds),'?'));

        $s=$pdo->prepare("
            SELECT cp.campaign_id,p.id,p.code,p.nom,p.annee_academique_id,p.academic_level_id,p.filiere_id,
                   COALESCE(l.code,'—') niveau_code,COALESCE(l.libelle,'Niveau non défini') niveau_libelle,
                   COALESCE(f.nom,'Filière non définie') filiere_nom
            FROM stage_campaign_promotions cp
            JOIN promotions p ON p.id=cp.promotion_id
            LEFT JOIN academic_levels l ON l.id=p.academic_level_id
            LEFT JOIN filieres f ON f.id=p.filiere_id
            WHERE cp.campaign_id IN($ph)
            ORDER BY f.nom,l.ordre,p.nom
        ");
        $s->execute($campaignIds);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$promotionsByCampaign[(int)$r['campaign_id']][]=$r;

        $s=$pdo->prepare("SELECT id,campaign_id,requirement_type,code,libelle,obligatoire,ordre FROM stage_campaign_requirements WHERE campaign_id IN($ph) ORDER BY campaign_id,ordre,id");
        $s->execute($campaignIds);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$requirementsByCampaign[(int)$r['campaign_id']][]=$r;
    }

    foreach($items as &$r){
        $r['configuration']=campaignConfig($r['configuration']);$r['financial']=campaignFinancialConfig($r['configuration']);
        $r['promotions']=$promotionsByCampaign[(int)$r['id']]??[];$r['requirements']=$requirementsByCampaign[(int)$r['id']]??[];
        foreach(['promotions_count','eligible_students','hosting_requests','hosting_accepted'] as $k)$r[$k]=(int)$r[$k];
        $r['d4_requests']=$r['hosting_requests'];$r['d4_accepted']=$r['hosting_accepted'];
    }unset($r);

    $s=$pdo->prepare("SELECT id,code,libelle,description,owner_etablissement_id,created_by_user_id FROM stage_types WHERE actif=1 AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?) ORDER BY owner_etablissement_id IS NULL DESC,libelle");
    $s->execute([$eid]);$types=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($types as &$t){
        $t['id']=(int)$t['id'];$t['owner_etablissement_id']=$t['owner_etablissement_id']!==null?(int)$t['owner_etablissement_id']:null;
        $t['local']=$t['owner_etablissement_id']!==null;$t['legacy']=$t['owner_etablissement_id']===null;
        $t['policies']=stagePolicySnapshot($pdo,$t['id']);$t['financial']=stageTypeFinancialFromPolicies($t['policies'],$t['legacy']);
    }unset($t);

    $s=$pdo->prepare("SELECT id,libelle,date_debut,date_fin,actif FROM annees_academiques WHERE etablissement_id=? ORDER BY COALESCE(date_debut,'1900-01-01') DESC,id DESC");
    $s->execute([$eid]);$years=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("SELECT id,code,nom,type_unite FROM facultes WHERE etablissement_id=? AND actif=1 ORDER BY nom");
    $s->execute([$eid]);$units=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Filières de l'établissement */
    $s=$pdo->prepare("
        SELECT id,code,nom,faculte_id,curriculum_reference_id
        FROM filieres
        WHERE etablissement_id=? AND actif=1
        ORDER BY nom
    ");
    $s->execute([$eid]);$filieres=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Promotions + filière + niveau */
    $s=$pdo->prepare("
        SELECT p.id,p.code,p.nom,p.annee_academique_id,p.filiere_id,p.academic_level_id,
               COALESCE(f.nom,'Filière non définie') filiere_nom,
               COALESCE(l.code,'—') niveau_code,
               COALESCE(l.libelle,'Niveau non défini') niveau_libelle,
               COALESCE(l.ordre,999) niveau_ordre
        FROM promotions p
        LEFT JOIN filieres f ON f.id=p.filiere_id AND f.etablissement_id=p.etablissement_id
        LEFT JOIN academic_levels l ON l.id=p.academic_level_id
        WHERE p.etablissement_id=? AND p.actif=1
          AND p.annee_academique_id IS NOT NULL
        ORDER BY p.annee_academique_id,f.nom,niveau_ordre,p.nom
    ");
    $s->execute([$eid]);$promotions=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("
        SELECT COUNT(*) total,
               COALESCE(SUM(statut='BROUILLON'),0) draft,
               COALESCE(SUM(statut='EN_PREPARATION'),0) preparing,
               COALESCE(SUM(statut='OUVERTE'),0) opened,
               COALESCE(SUM(statut='CLOTUREE'),0) closed
        FROM stage_campaigns
        WHERE owner_etablissement_id=? AND type_campagne='UNIVERSITAIRE'
    ");
    $s->execute([$eid]);$k=$s->fetch(PDO::FETCH_ASSOC)?:[];

    jsonResponse(true,'',[
        'items'=>$items,'types'=>$types,'years'=>$years,'units'=>$units,
        'filieres'=>$filieres,'promotions'=>$promotions,
        'permissions'=>[
            'create'=>hasPermission($pdo,'campaign.university.create'),
            'update'=>hasPermission($pdo,'campaign.university.update'),
            'publish'=>hasPermission($pdo,'campaign.university.publish'),
            'cancel'=>hasPermission($pdo,'campaign.university.cancel')
        ],
        'kpi'=>[
            'total'=>(int)($k['total']??0),'draft'=>(int)($k['draft']??0),
            'preparing'=>(int)($k['preparing']??0),'opened'=>(int)($k['opened']??0),
            'closed'=>(int)($k['closed']??0)
        ]
    ]);
}catch(Throwable $e){
    error_log('[CAMPAIGN LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur Campagnes universitaires : '.$e->getMessage(),[],500);
}
<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-campaign.php';

try{
    requirePermission($pdo,'campaign.supervision.view');

    $search=trim((string)($_GET['search']??''));
    $status=trim((string)($_GET['statut']??''));
    $typeId=(int)($_GET['stage_type_id']??0);

    $where=["c.type_campagne='UNIVERSITAIRE'"];
    $params=[];

    if($search!==''){
        $like='%'.$search.'%';
        $where[]='(c.code LIKE ? OR c.titre LIKE ? OR e.nom LIKE ?)';
        array_push($params,$like,$like,$like);
    }

    if($status!==''){
        $where[]='c.statut=?';
        $params[]=$status;
    }

    if($typeId>0){
        $where[]='c.stage_type_id=?';
        $params[]=$typeId;
    }

    $s=$pdo->prepare("
        SELECT
            c.id,c.code,c.titre,c.statut,c.date_debut,c.date_fin,
            c.ouverture_candidatures,c.cloture_candidatures,
            c.published_at,
            e.code etablissement_code,
            e.nom etablissement_nom,
            e.province,
            st.id stage_type_id,
            st.code stage_type_code,
            st.libelle stage_type_libelle,
            aa.libelle annee_libelle,
            fa.nom unite_nom,

            (SELECT COUNT(*)
             FROM stage_campaign_promotions cp
             WHERE cp.campaign_id=c.id) promotions_count,

            (SELECT COUNT(DISTINCT se.student_id)
             FROM stage_campaign_promotions cp
             JOIN student_academic_enrollments sae
               ON sae.promotion_id=cp.promotion_id
              AND sae.annee_academique_id=c.annee_academique_id
              AND sae.statut='EN_COURS'
             JOIN student_enrollments se
               ON se.id=sae.enrollment_id
              AND se.etablissement_id=c.owner_etablissement_id
              AND se.statut='ACTIF'
             WHERE cp.campaign_id=c.id) eligible_students,

            (SELECT COUNT(*)
             FROM stage_campaign_participations p
             WHERE p.university_campaign_id=c.id) d4_requests,

            (SELECT COUNT(*)
             FROM stage_campaign_participations p
             WHERE p.university_campaign_id=c.id
               AND p.statut='ACCEPTEE') d4_accepted

        FROM stage_campaigns c
        JOIN etablissements e ON e.id=c.owner_etablissement_id
        JOIN stage_types st ON st.id=c.stage_type_id
        LEFT JOIN annees_academiques aa
          ON aa.id=c.annee_academique_id
        LEFT JOIN facultes fa
          ON fa.id=c.owner_faculte_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $s->execute($params);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$i){
        foreach(['promotions_count','eligible_students','d4_requests','d4_accepted'] as $k)
            $i[$k]=(int)$i[$k];
    }
    unset($i);

    $types=$pdo->query("
        SELECT id,code,libelle
        FROM stage_types
        WHERE actif=1
          AND code IN('MEDICAL_STANDARD','MEDICAL_D4')
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $k=$pdo->query("
        SELECT
            COUNT(*) total,
            COALESCE(SUM(statut='BROUILLON'),0) draft,
            COALESCE(SUM(statut='EN_PREPARATION'),0) preparing,
            COALESCE(SUM(statut='OUVERTE'),0) opened,
            COALESCE(SUM(statut IN('CLOTUREE','TERMINEE')),0) closed
        FROM stage_campaigns
        WHERE type_campagne='UNIVERSITAIRE'
    ")->fetch(PDO::FETCH_ASSOC)?:[];

    jsonResponse(true,'',[
        'items'=>$items,
        'types'=>$types,
        'kpi'=>array_map('intval',$k)
    ]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur Supervision campagnes : '.$e->getMessage(),[],500);
}

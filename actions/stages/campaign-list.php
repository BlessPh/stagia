<?php
/**
 * Endpoint AJAX de consultation paginée des campagnes universitaires.
 * Il renvoie les filtres appliqués, les promotions associées et les indicateurs de pilotage.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

/* Les administrateurs et responsables pédagogiques gèrent les campagnes de leur établissement. */
requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) jsonResponse(false,'Aucun établissement associé.',[],403);

/* Normalisation de la pagination et des filtres de recherche côté interface. */
$page=max(1,(int)($_GET['page']??1));
$perPage=min(100,max(5,(int)($_GET['per_page']??10)));

$search=trim($_GET['search']??'');
$statut=$_GET['statut']??'';
$stageTypeId=(int)($_GET['stage_type_id']??0);
$anneeId=(int)($_GET['annee_id']??0);

/* Base de la requête : isolation obligatoire sur l'établissement propriétaire. */
$where="WHERE c.owner_etablissement_id=? AND c.type_campagne='UNIVERSITAIRE'";
$params=[$etablissementId];

if($search!==''){
    $where.=" AND (c.code LIKE ? OR c.titre LIKE ?)";
    $like="%{$search}%";
    array_push($params,$like,$like);
}

$statuts=['BROUILLON','EN_PREPARATION','OUVERTE','CLOTUREE','TERMINEE','ANNULEE'];

if(in_array($statut,$statuts,true)){
    $where.=" AND c.statut=?";
    $params[]=$statut;
}

if($stageTypeId){
    $where.=" AND c.stage_type_id=?";
    $params[]=$stageTypeId;
}

if($anneeId){
    $where.=" AND c.annee_academique_id=?";
    $params[]=$anneeId;
}

/* Total filtré : utilisé pour calculer les pages et leurs bornes. */
$stmt=$pdo->prepare("SELECT COUNT(*) FROM stage_campaigns c $where");
$stmt->execute($params);
$total=(int)$stmt->fetchColumn();

$pages=max(1,(int)ceil($total/$perPage));
$page=min($page,$pages);
$offset=($page-1)*$perPage;

/* Liste paginée avec les libellés du type de stage et de l'année académique. */
$stmt=$pdo->prepare("
    SELECT c.id,c.uuid,c.code,c.stage_type_id,c.annee_academique_id,
           c.titre,c.description,c.date_debut,c.date_fin,
           c.ouverture_candidatures,c.cloture_candidatures,c.statut,
           st.code stage_type_code,st.libelle stage_type,
           aa.libelle annee_academique,
           (
               SELECT COUNT(*)
               FROM stage_campaign_promotions cp
               WHERE cp.campaign_id=c.id
           ) nb_promotions
    FROM stage_campaigns c
    JOIN stage_types st ON st.id=c.stage_type_id
    LEFT JOIN annees_academiques aa
      ON aa.id=c.annee_academique_id
     AND aa.etablissement_id=c.owner_etablissement_id
    $where
    ORDER BY c.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$items=$stmt->fetchAll();

/* Les promotions sont regroupées par campagne pour préremplir les écrans d'édition. */
if($items){
    $ids=array_column($items,'id');
    $placeholders=implode(',',array_fill(0,count($ids),'?'));

    $stmt=$pdo->prepare("
        SELECT campaign_id,promotion_id
        FROM stage_campaign_promotions
        WHERE campaign_id IN ($placeholders)
    ");
    $stmt->execute($ids);

    $map=[];

    foreach($stmt->fetchAll() as $x)
        $map[$x['campaign_id']][]=(int)$x['promotion_id'];

    foreach($items as &$item)
        $item['promotion_ids']=$map[$item['id']]??[];
}

/* Indicateurs globaux de suivi des campagnes universitaires de l'établissement. */
$stmt=$pdo->prepare("
    SELECT COUNT(*) total,
           COALESCE(SUM(statut='BROUILLON'),0) brouillons,
           COALESCE(SUM(statut='OUVERTE'),0) ouvertes,
           COALESCE(SUM(statut='TERMINEE'),0) terminees,
           COALESCE(SUM(statut='CLOTUREE'),0) cloturees
    FROM stage_campaigns
    WHERE owner_etablissement_id=?
      AND type_campagne='UNIVERSITAIRE'
");
$stmt->execute([$etablissementId]);
$s=$stmt->fetch();

/* Réponse finale consommée par la grille et les compteurs du tableau de bord. */
jsonResponse(true,'',[
    'items'=>$items,
    'stats'=>[
        'total'=>(int)$s['total'],
        'brouillons'=>(int)$s['brouillons'],
        'ouvertes'=>(int)$s['ouvertes'],
        'cloturees'=>(int)$s['cloturees'],
        'terminees'=>(int)$s['terminees']
    ],
    'pagination'=>[
        'page'=>$page,
        'per_page'=>$perPage,
        'total'=>$total,
        'pages'=>$pages,
        'from'=>$total?$offset+1:0,
        'to'=>min($offset+$perPage,$total)
    ]
]);

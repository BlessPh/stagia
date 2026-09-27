<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) jsonResponse(false,'Aucun établissement associé.',[],403);

/* Filtres + pagination */
$page=max(1,(int)($_GET['page']??1));
$perPage=min(100,max(5,(int)($_GET['per_page']??10)));
$search=trim($_GET['search']??'');
$statut=$_GET['statut']??'';

$where="WHERE etablissement_id=?";
$params=[$etablissementId];

if($search!==''){
    $where.=" AND libelle LIKE ?";
    $params[]="%{$search}%";
}
if($statut==='0' || $statut==='1'){
    $where.=" AND actif=?";
    $params[]=(int)$statut;
}

/* Total filtré */
$stmt=$pdo->prepare("SELECT COUNT(*) FROM annees_academiques $where");
$stmt->execute($params);
$total=(int)$stmt->fetchColumn();

$pages=max(1,(int)ceil($total/$perPage));
$page=min($page,$pages);
$offset=($page-1)*$perPage;

/* Liste */
$stmt=$pdo->prepare("
    SELECT id,libelle,date_debut,date_fin,actif,
           DATE_FORMAT(created_at,'%d/%m/%Y') date_creation
    FROM annees_academiques $where
    ORDER BY date_debut DESC,id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$items=$stmt->fetchAll();

/* KPI */
$stmt=$pdo->prepare("
    SELECT COUNT(*) total,
           COALESCE(SUM(actif=1),0) actifs,
           COALESCE(SUM(actif=0),0) inactifs
    FROM annees_academiques WHERE etablissement_id=?
");
$stmt->execute([$etablissementId]);
$stats=$stmt->fetch();

/* Dernière année active */
$stmt=$pdo->prepare("
    SELECT libelle FROM annees_academiques
    WHERE etablissement_id=? AND actif=1
    ORDER BY date_debut DESC,id DESC LIMIT 1
");
$stmt->execute([$etablissementId]);
$courante=$stmt->fetchColumn()?:'-';

/* Réponse AJAX */
jsonResponse(true,'',[
    'items'=>$items,
    'stats'=>[
        'total'=>(int)$stats['total'],'actifs'=>(int)$stats['actifs'],
        'inactifs'=>(int)$stats['inactifs'],'courante'=>$courante
    ],
    'pagination'=>[
        'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,
        'from'=>$total?$offset+1:0,'to'=>min($offset+$perPage,$total)
    ]
]);
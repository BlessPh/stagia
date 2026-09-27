<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

$page=max(1,(int)($_GET['page']??1));
$perPage=min(100,max(5,(int)($_GET['per_page']??10)));
$search=trim($_GET['search']??'');
$statut=$_GET['statut']??'';

$where="WHERE type_etablissement='HOPITAL'";
$params=[];

if($search!==''){
    $where.=" AND (
        code LIKE ? OR nom LIKE ? OR numero_agrement LIKE ?
        OR ville LIKE ? OR province LIKE ?
    )";

    $like="%{$search}%";
    array_push($params,$like,$like,$like,$like,$like);
}

$statuts=[
    'EXTERNE','EN_ATTENTE','VALIDE',
    'ACTIF','SUSPENDU','REJETE'
];

if(in_array($statut,$statuts,true)){
    $where.=" AND statut=?";
    $params[]=$statut;
}

/* Total filtré */
$stmt=$pdo->prepare("
    SELECT COUNT(*)
    FROM etablissements
    $where
");
$stmt->execute($params);

$total=(int)$stmt->fetchColumn();

$pages=max(1,(int)ceil($total/$perPage));
$page=min($page,$pages);
$offset=($page-1)*$perPage;

/* Liste */
$stmt=$pdo->prepare("
    SELECT id,code,nom,email,telephone,adresse,
           province,ville,numero_agrement,logo,
           statut,created_at
    FROM etablissements
    $where
    ORDER BY nom
    LIMIT $perPage OFFSET $offset
");

$stmt->execute($params);
$items=$stmt->fetchAll();

/* KPI */
$stmt=$pdo->query("
    SELECT COUNT(*) total,
           COALESCE(SUM(statut='EXTERNE'),0) externes,
           COALESCE(SUM(statut='EN_ATTENTE'),0) attente,
           COALESCE(SUM(statut IN('VALIDE','ACTIF')),0) actifs,
           COALESCE(SUM(statut='SUSPENDU'),0) suspendus
    FROM etablissements
    WHERE type_etablissement='HOPITAL'
");

$s=$stmt->fetch();

jsonResponse(true,'',[
    'items'=>$items,

    'stats'=>[
        'total'=>(int)$s['total'],
        'externes'=>(int)$s['externes'],
        'attente'=>(int)$s['attente'],
        'actifs'=>(int)$s['actifs'],
        'suspendus'=>(int)$s['suspendus']
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
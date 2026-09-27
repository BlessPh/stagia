<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId)
    jsonResponse(false,'Aucun établissement associé.',[],403);

$page=max(1,(int)($_GET['page']??1));
$perPage=min(100,max(5,(int)($_GET['per_page']??10)));

$search=trim($_GET['search']??'');
$statut=$_GET['statut']??'';
$faculteId=(int)($_GET['faculte_id']??0);
$departementId=(int)($_GET['departement_id']??0);
$filiereId=(int)($_GET['filiere_id']??0);
$promotionId=(int)($_GET['promotion_id']??0);

$where="WHERE m.etablissement_id=?";
$params=[$etablissementId];

if($search!==''){
    $where.=" AND (m.code LIKE ? OR m.nom LIKE ?)";
    $like="%{$search}%";
    array_push($params,$like,$like);
}

if($statut==='0' || $statut==='1'){
    $where.=" AND m.actif=?";
    $params[]=(int)$statut;
}

if($faculteId){$where.=" AND fa.id=?";$params[]=$faculteId;}
if($departementId){$where.=" AND d.id=?";$params[]=$departementId;}
if($filiereId){$where.=" AND f.id=?";$params[]=$filiereId;}
if($promotionId){$where.=" AND p.id=?";$params[]=$promotionId;}

$joins="
FROM matieres m
JOIN promotions p ON p.id=m.promotion_id AND p.etablissement_id=m.etablissement_id
JOIN filieres f ON f.id=p.filiere_id AND f.etablissement_id=m.etablissement_id
LEFT JOIN departements d ON d.id=f.departement_id AND d.etablissement_id=m.etablissement_id
LEFT JOIN facultes fa ON fa.id=d.faculte_id AND fa.etablissement_id=m.etablissement_id";

$stmt=$pdo->prepare("SELECT COUNT(*) $joins $where");
$stmt->execute($params);
$total=(int)$stmt->fetchColumn();

$pages=max(1,(int)ceil($total/$perPage));
$page=min($page,$pages);
$offset=($page-1)*$perPage;

$stmt=$pdo->prepare("
    SELECT m.id,m.code,m.nom,m.credits,m.coefficient,m.note_max,m.actif,
           p.id promotion_id,p.nom promotion,
           f.nom filiere,d.nom departement,fa.nom faculte
    $joins
    $where
    ORDER BY m.nom
    LIMIT $perPage OFFSET $offset
");

$stmt->execute($params);
$items=$stmt->fetchAll();

/* KPI */
$stmt=$pdo->prepare("
    SELECT COUNT(*) total,
           COALESCE(SUM(actif=1),0) actifs,
           COALESCE(SUM(actif=0),0) inactifs
    FROM matieres
    WHERE etablissement_id=?
");
$stmt->execute([$etablissementId]);
$s=$stmt->fetch();

$stmt=$pdo->prepare("
    SELECT COUNT(DISTINCT promotion_id)
    FROM matieres
    WHERE etablissement_id=?
");
$stmt->execute([$etablissementId]);
$promotions=(int)$stmt->fetchColumn();

jsonResponse(true,'',[
    'items'=>$items,
    'stats'=>[
        'total'=>(int)$s['total'],
        'actifs'=>(int)$s['actifs'],
        'inactifs'=>(int)$s['inactifs'],
        'promotions'=>$promotions
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
<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) jsonResponse(false,'Aucun établissement associé.',[],403);

/* Filtres */
$page=max(1,(int)($_GET['page']??1));
$perPage=min(100,max(5,(int)($_GET['per_page']??10)));
$search=trim($_GET['search']??'');
$faculteId=(int)($_GET['faculte_id']??0);
$departementId=(int)($_GET['departement_id']??0);
$filiereId=(int)($_GET['filiere_id']??0);
$promotionId=(int)($_GET['promotion_id']??0);
$statut=$_GET['statut']??'';

$where="WHERE se.etablissement_id=?";
$params=[$etablissementId];

if($search!==''){
    $where.=" AND (sp.stagia_code LIKE ? OR se.matricule LIKE ? OR sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ?)";
    $like="%{$search}%"; array_push($params,$like,$like,$like,$like,$like);
}
if($faculteId){$where.=" AND fa.id=?";$params[]=$faculteId;}
if($departementId){$where.=" AND d.id=?";$params[]=$departementId;}
if($filiereId){$where.=" AND f.id=?";$params[]=$filiereId;}
if($promotionId){$where.=" AND p.id=?";$params[]=$promotionId;}
if(in_array($statut,['ACTIF','SUSPENDU','TERMINE','ARCHIVE'],true)){
    $where.=" AND se.statut=?"; $params[]=$statut;
}

/* Parcours académique le plus récent */
$joins="
FROM student_enrollments se
JOIN student_profiles sp ON sp.id=se.student_id
LEFT JOIN student_academic_enrollments ae ON ae.id=(
    SELECT ae2.id FROM student_academic_enrollments ae2
    WHERE ae2.enrollment_id=se.id ORDER BY ae2.id DESC LIMIT 1
)
LEFT JOIN annees_academiques aa ON aa.id=ae.annee_academique_id
LEFT JOIN promotions p ON p.id=ae.promotion_id
LEFT JOIN filieres f ON f.id=p.filiere_id
LEFT JOIN departements d ON d.id=f.departement_id
LEFT JOIN facultes fa ON fa.id=d.faculte_id";

/* Total filtré */
$stmt=$pdo->prepare("SELECT COUNT(*) $joins $where");
$stmt->execute($params); $total=(int)$stmt->fetchColumn();

$pages=max(1,(int)ceil($total/$perPage)); $page=min($page,$pages);
$offset=($page-1)*$perPage;

/* Liste */
$stmt=$pdo->prepare("
    SELECT se.id enrollment_id,se.matricule,se.statut,se.date_inscription,
           sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,sp.sexe,sp.photo,
           aa.libelle annee_academique,p.nom promotion_nom,f.nom filiere_nom,
           d.nom departement_nom,fa.nom faculte_nom
    $joins $where
    ORDER BY sp.nom,sp.postnom,sp.prenom
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params); $items=$stmt->fetchAll();

/* KPI */
$stmt=$pdo->prepare("
    SELECT COUNT(*) total,
           COALESCE(SUM(statut='ACTIF'),0) actifs,
           COALESCE(SUM(statut='SUSPENDU'),0) suspendus,
           COALESCE(SUM(statut='TERMINE'),0) termines,
           COALESCE(SUM(statut='ARCHIVE'),0) archives
    FROM student_enrollments WHERE etablissement_id=?
");
$stmt->execute([$etablissementId]); $s=$stmt->fetch();

jsonResponse(true,'',[
    'items'=>$items,
    'stats'=>[
        'total'=>(int)$s['total'],'actifs'=>(int)$s['actifs'],
        'suspendus'=>(int)$s['suspendus'],'termines'=>(int)$s['termines'],
        'archives'=>(int)$s['archives']
    ],
    'pagination'=>[
        'page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,
        'from'=>$total?$offset+1:0,'to'=>min($offset+$perPage,$total)
    ]
]);
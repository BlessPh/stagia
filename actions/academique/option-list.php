<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.view');

$etabId=(int)($_SESSION['etablissement_id']??0);
if(!$etabId)jsonResponse(false,'Aucun établissement actif.',[],422);

$settings=$_SESSION['academic_settings']??[];
if(empty($settings['option_specialite_active']))
    jsonResponse(false,'Les options / spécialités sont désactivées pour cet établissement.',[],403);

$search=trim((string)($_GET['search']??''));
$filiereId=(int)($_GET['filiere_id']??0);

$where=['o.etablissement_id=?'];
$params=[$etabId];
if($search!==''){
    $where[]='(o.nom LIKE ? OR o.code LIKE ? OR f.nom LIKE ?)';
    $like='%'.$search.'%';array_push($params,$like,$like,$like);
}
if($filiereId){$where[]='o.filiere_id=?';$params[]=$filiereId;}

$s=$pdo->prepare("
    SELECT
        o.id,o.code,o.nom,o.description,o.motif_ajout,o.actif,
        o.ajoute_localement,o.validation_statut,o.review_comment,
        o.source_template_option_id,o.created_at,
        f.id filiere_id,f.code filiere_code,f.nom filiere_nom,
        COUNT(DISTINCT p.id) promotions_count
    FROM options_specialites o
    JOIN filieres f ON f.id=o.filiere_id AND f.etablissement_id=o.etablissement_id
    LEFT JOIN promotions p ON p.option_specialite_id=o.id
    WHERE ".implode(' AND ',$where)."
    GROUP BY o.id,f.id
    ORDER BY f.nom,o.nom
");
$s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT id,code,nom FROM filieres WHERE etablissement_id=? AND actif=1 ORDER BY nom");
$s->execute([$etabId]);$filieres=$s->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true,'',[
    'items'=>$items,'filieres'=>$filieres,
    'can_manage'=>hasPermission($pdo,'academic.manage'),
    'obligatoire'=>(bool)($settings['option_specialite_obligatoire']??false)
]);

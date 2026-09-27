<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/admin-scope.php';

if(!hasPermission($pdo,'analytics.export')){
    http_response_code(403);exit('Accès export refusé.');
}

$estMinisteriel=estResponsableMinisteriel($pdo);
$mode=hasRole('ORDRE_MEDECINS')&&!hasRole('SUPER_ADMIN')&&!$estMinisteriel
    ?'MEDICINE'
    :($estMinisteriel?'SANTE':'ALL');

$year=trim($_GET['year']??'');
$etabId=(int)($_GET['etablissement_id']??0);
$faculteId=(int)($_GET['faculte_id']??0);$departementId=(int)($_GET['departement_id']??0);$promotionId=(int)($_GET['promotion_id']??0);$province=trim($_GET['province']??'');

$sql="
    SELECT aa.libelle annee,e.code,e.nom,e.type_etablissement,
           COUNT(DISTINCT se.student_id) nombre_etudiants
    FROM student_enrollments se
    JOIN student_academic_enrollments sae ON sae.enrollment_id=se.id
    JOIN annees_academiques aa ON aa.id=sae.annee_academique_id
    JOIN promotions pr ON pr.id=sae.promotion_id
    JOIN filieres f ON f.id=pr.filiere_id
    LEFT JOIN curriculum_references cr ON cr.id=f.curriculum_reference_id
    JOIN etablissements e ON e.id=se.etablissement_id
    WHERE se.statut<>'ARCHIVE'
";
$params=[];
$perimetreIds=etablissementsVisiblesAdministration($pdo);
$restreint=($estMinisteriel||hasRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']))&&!hasRole('SUPER_ADMIN');
if($restreint){
    if(!$perimetreIds){http_response_code(403);exit('Aucune organisation autorisée.');}
    if($etabId&&!in_array($etabId,$perimetreIds,true)){http_response_code(403);exit('Établissement hors périmètre.');}
    $sql.=' AND e.id IN('.implode(',',array_fill(0,count($perimetreIds),'?')).')';array_push($params,...$perimetreIds);
}

if($mode==='SANTE')$sql.=" AND cr.domaine='SANTE'";
elseif($mode==='MEDICINE')$sql.=" AND cr.code='MEDICINE'";

if($year!==''){$sql.=" AND aa.libelle=?";$params[]=$year;}
if($etabId>0){$sql.=" AND e.id=?";$params[]=$etabId;}
if($faculteId>0){$sql.=' AND f.faculte_id=?';$params[]=$faculteId;}
if($departementId>0){$sql.=' AND f.departement_id=?';$params[]=$departementId;}
if($promotionId>0){$sql.=' AND pr.id=?';$params[]=$promotionId;}
if($province!==''){$sql.=' AND e.province=?';$params[]=$province;}

$sql.=" GROUP BY aa.libelle,e.id,e.code,e.nom,e.type_etablissement
        ORDER BY aa.libelle DESC,e.nom";

$s=$pdo->prepare($sql);$s->execute($params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="stagia_statistiques_etudiants_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out,['Année académique','Code établissement','Établissement','Type','Nombre étudiants'],';');
while($r=$s->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out,[$r['annee'],$r['code'],$r['nom'],$r['type_etablissement'],$r['nombre_etudiants']],';');
}
fclose($out);
exit;

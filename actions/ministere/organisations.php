<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/admin-scope.php';

requireAjaxAuth();
exigerResponsableMinisterielAjax($pdo);

$ids=etablissementsVisiblesAdministration($pdo);
if(!$ids)jsonResponse(true,'',['items'=>[],'types'=>[],'provinces'=>[]]);
$ph=implode(',',array_fill(0,count($ids),'?'));
$q=trim($_GET['q']??'');$type=trim($_GET['type']??'');$province=trim($_GET['province']??'');
$where=["e.id IN($ph)","e.statut IN('VALIDE','ACTIF')"];$params=$ids;
if($q!==''){$like='%'.$q.'%';$where[]='(e.nom LIKE ? OR e.code LIKE ? OR e.ville LIKE ? OR e.email LIKE ?)';array_push($params,$like,$like,$like,$like);}
if($type!==''){$where[]='e.type_etablissement=?';$params[]=$type;}
if($province!==''){$where[]='e.province=?';$params[]=$province;}

try{
    $s=$pdo->prepare("SELECT e.id,e.code,e.nom,e.type_etablissement,
            COALESCE(t.libelle,e.type_etablissement) type_libelle,
            e.province,e.ville,e.email,e.telephone,e.adresse,e.statut,
            (SELECT COUNT(DISTINCT ra.user_id)
             FROM role_assignments ra JOIN roles r ON r.id=ra.role_id
             JOIN users u ON u.id=ra.user_id AND u.actif=1 AND u.statut_compte='ACTIF'
             WHERE ra.etablissement_id=e.id AND ra.actif=1 AND r.actif=1 AND r.code<>'STAGIAIRE'
               AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
               AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())) responsables,
            (SELECT COUNT(DISTINCT se.student_id)
             FROM student_enrollments se
             JOIN student_academic_enrollments sae ON sae.enrollment_id=se.id
             JOIN promotions pr ON pr.id=sae.promotion_id
             JOIN filieres f ON f.id=pr.filiere_id
             JOIN curriculum_references cr ON cr.id=f.curriculum_reference_id AND cr.domaine='SANTE'
             WHERE se.etablissement_id=e.id AND se.statut<>'ARCHIVE') etudiants
        FROM etablissements e
        LEFT JOIN establishment_types t ON t.code=e.type_etablissement
        WHERE ".implode(' AND ',$where)."
        ORDER BY e.nom");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("SELECT DISTINCT type_etablissement code,COALESCE(t.libelle,e.type_etablissement) label
        FROM etablissements e LEFT JOIN establishment_types t ON t.code=e.type_etablissement
        WHERE e.id IN($ph) ORDER BY label");$s->execute($ids);$types=$s->fetchAll(PDO::FETCH_ASSOC);
    $s=$pdo->prepare("SELECT DISTINCT province FROM etablissements e WHERE e.id IN($ph) AND province IS NOT NULL AND TRIM(province)<>'' ORDER BY province");
    $s->execute($ids);$provinces=$s->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(true,'',['items'=>$items,'types'=>$types,'provinces'=>$provinces]);
}catch(Throwable $e){
    error_log('[MINISTERE ORGANISATIONS] '.$e->getMessage());
    jsonResponse(false,'Impossible de charger les organisations supervisées.',[],500);
}

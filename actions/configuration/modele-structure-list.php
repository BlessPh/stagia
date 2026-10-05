<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);
$id=(int)($_GET['template_id']??0);
if(!$id) jsonResponse(false,'Modèle invalide.',[],422);

$s=$pdo->prepare("SELECT t.*,e.libelle type_libelle FROM academic_structure_templates t
    LEFT JOIN establishment_types e ON e.code=t.type_etablissement WHERE t.id=? LIMIT 1");
$s->execute([$id]);$template=$s->fetch(PDO::FETCH_ASSOC);
if(!$template) jsonResponse(false,'Modèle académique introuvable.',[],404);

$s=$pdo->prepare("SELECT u.*,p.nom parent_nom FROM academic_template_units u
    LEFT JOIN academic_template_units p ON p.id=u.parent_id
    WHERE u.template_id=? AND u.actif=1 ORDER BY u.ordre,u.nom");
$s->execute([$id]);$units=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT d.*,u.nom unit_nom,
    CASE WHEN d.unit_id IS NOT NULL AND u.id IS NULL THEN 1 ELSE 0 END unit_reference_invalid
    FROM academic_template_departments d
    LEFT JOIN academic_template_units u ON u.id=d.unit_id AND u.template_id=d.template_id AND u.actif=1
    WHERE d.template_id=? ORDER BY d.ordre,d.nom");
$s->execute([$id]);$departments=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT p.*,u.nom unit_nom,d.nom department_nom,
    CASE WHEN p.unit_id IS NOT NULL AND u.id IS NULL THEN 1 ELSE 0 END unit_reference_invalid,
    CASE WHEN p.department_id IS NOT NULL AND d.id IS NULL THEN 1 ELSE 0 END department_reference_invalid,
    r.code curriculum_code,r.nom curriculum_nom,
    GROUP_CONCAT(l.code ORDER BY c.ordre,l.ordre SEPARATOR ', ') niveaux
    FROM academic_template_programs p
    LEFT JOIN academic_template_units u ON u.id=p.unit_id AND u.template_id=p.template_id AND u.actif=1
    LEFT JOIN academic_template_departments d ON d.id=p.department_id AND d.template_id=p.template_id AND d.actif=1
    JOIN curriculum_references r ON r.id=p.curriculum_reference_id
    LEFT JOIN academic_cycles c ON c.curriculum_reference_id=r.id AND c.actif=1
    LEFT JOIN academic_levels l ON l.academic_cycle_id=c.id AND l.actif=1
        AND (l.preparatoire=0 OR p.preparatory_level_enabled=1)
    WHERE p.template_id=? AND p.actif=1
    GROUP BY p.id
    ORDER BY p.ordre,p.nom");
$s->execute([$id]);$programs=$s->fetchAll(PDO::FETCH_ASSOC);

$s=$pdo->prepare("SELECT o.*,p.nom program_nom FROM academic_template_options o
    JOIN academic_template_programs p ON p.id=o.program_id
    WHERE o.template_id=? AND o.actif=1 ORDER BY p.nom,o.ordre,o.nom");
$s->execute([$id]);$options=$s->fetchAll(PDO::FETCH_ASSOC);

$unitTypes=$pdo->prepare("SELECT type_unite,COALESCE(NULLIF(libelle,''),type_unite) libelle
    FROM academic_structure_template_unit_types WHERE template_id=? AND actif=1 ORDER BY ordre,libelle");
$unitTypes->execute([$id]);$unitTypes=$unitTypes->fetchAll(PDO::FETCH_ASSOC);

$curricula=$pdo->query("SELECT id,code,nom,domaine FROM curriculum_references WHERE actif=1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true,'',[
    'template'=>$template,'unit_types'=>$unitTypes,'curricula'=>$curricula,
    'units'=>$units,'departments'=>$departments,'programs'=>$programs,'options'=>$options
]);

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-option-sync.php';
require_once __DIR__.'/../../includes/academic-unit-sync.php';
require_once __DIR__.'/../../includes/academic-department-sync.php';
require_once __DIR__.'/../../includes/academic-program-sync.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();
$templateId=(int)($_POST['template_id']??0);$id=(int)($_POST['id']??0);$entity=$_POST['entity']??'';
if(!$templateId||!$id||!in_array($entity,['UNIT','DEPARTMENT','PROGRAM','OPTION'],true))
    jsonResponse(false,'Données invalides.',[],422);

try{
    $pdo->beginTransaction();
    if($entity==='UNIT'){
        $s=$pdo->prepare("SELECT
            (SELECT COUNT(*) FROM academic_template_units WHERE parent_id=? AND template_id=? AND actif=1)+
            (SELECT COUNT(*) FROM academic_template_departments WHERE unit_id=? AND template_id=? AND actif=1)+
            (SELECT COUNT(*) FROM academic_template_programs WHERE unit_id=? AND template_id=? AND actif=1)");
        $s->execute([$id,$templateId,$id,$templateId,$id,$templateId]);
        if((int)$s->fetchColumn())throw new RuntimeException('Cette unité contient encore des éléments actifs.');

        $pdo->prepare("
            UPDATE academic_template_units
            SET actif=0
            WHERE id=? AND template_id=?
        ")->execute([$id,$templateId]);

        syncAcademicTemplateUnit($pdo,$templateId,$id);
    }
    if($entity==='DEPARTMENT'){
        $s=$pdo->prepare("
            SELECT COUNT(*)
            FROM academic_template_programs
            WHERE department_id=? AND template_id=? AND actif=1
        ");
        $s->execute([$id,$templateId]);

        if((int)$s->fetchColumn())
            throw new RuntimeException('Ce département contient encore des programmes actifs.');

        $pdo->prepare("
            UPDATE academic_template_departments
            SET actif=0
            WHERE id=? AND template_id=?
        ")->execute([$id,$templateId]);

        syncAcademicTemplateDepartment($pdo,$templateId,$id);
    }
    if($entity==='PROGRAM'){
        $s=$pdo->prepare("
            SELECT COUNT(*)
            FROM academic_template_options
            WHERE program_id=? AND template_id=? AND actif=1
        ");
        $s->execute([$id,$templateId]);

        if((int)$s->fetchColumn())
            throw new RuntimeException('Ce programme contient encore des options / spécialités actives.');

        $pdo->prepare("
            UPDATE academic_template_programs
            SET actif=0
            WHERE id=? AND template_id=?
        ")->execute([$id,$templateId]);

        syncAcademicTemplateProgram($pdo,$templateId,$id);
    }
    if($entity==='OPTION'){
        $pdo->prepare("UPDATE academic_template_options SET actif=0 WHERE id=? AND template_id=?")->execute([$id,$templateId]);
        syncAcademicTemplateOption($pdo,$templateId,$id);
    }

    $pdo->prepare("UPDATE academic_structure_templates SET version_no=version_no+1 WHERE id=?")->execute([$templateId]);
    $pdo->commit();$message=match($entity){
        'UNIT'=>'Unité retirée du référentiel actif.',
        'DEPARTMENT'=>'Département retiré du référentiel actif.',
        'PROGRAM'=>'Filière / programme retiré du référentiel actif.',
        'OPTION'=>'Option / spécialité retirée du référentiel actif.',
        default=>'Élément supprimé du modèle.'
    };
    jsonResponse(true,$message);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],409);
}

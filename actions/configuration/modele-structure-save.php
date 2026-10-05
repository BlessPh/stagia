<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-option-sync.php';
require_once __DIR__.'/../../includes/academic-unit-sync.php';
require_once __DIR__.'/../../includes/academic-department-sync.php';
require_once __DIR__.'/../../includes/academic-program-sync.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();

$templateId=(int)($_POST['template_id']??0);
$entity=$_POST['entity']??'';
$id=(int)($_POST['id']??0);
if(!$templateId||!in_array($entity,['UNIT','DEPARTMENT','PROGRAM','OPTION'],true))
    jsonResponse(false,'Données invalides.',[],422);

function touchTemplate(PDO $pdo,int $templateId):void{
    $pdo->prepare("UPDATE academic_structure_templates SET version_no=version_no+1 WHERE id=?")->execute([$templateId]);
}
function makeCode(string $value,string $fallback):string{
    $raw=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;
    $code=strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/','_',$raw),'_'));
    return substr($code?:$fallback,0,40);
}

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("SELECT * FROM academic_structure_templates WHERE id=? LIMIT 1 FOR UPDATE");
    $s->execute([$templateId]);$tpl=$s->fetch(PDO::FETCH_ASSOC);
    if(!$tpl)throw new RuntimeException('Modèle introuvable.');

    if($entity==='UNIT'){
        $nom=trim($_POST['nom']??'');$type=strtoupper(trim($_POST['type_unite']??''));
        $parent=(int)($_POST['parent_id']??0)?:null;$ordre=(int)($_POST['ordre']??0);
        if($nom===''||$type==='')throw new RuntimeException('Type et nom obligatoires.');

        $s=$pdo->prepare("SELECT 1 FROM academic_structure_template_unit_types WHERE template_id=? AND type_unite=? AND actif=1 LIMIT 1");
        $s->execute([$templateId,$type]);if(!$s->fetchColumn())throw new RuntimeException("Ce type d'unité n'est pas autorisé par le modèle.");

        if($parent){
            if($id&&$parent===$id)throw new RuntimeException('Une unité ne peut pas être sa propre parente.');
            $s=$pdo->prepare("SELECT id FROM academic_template_units WHERE id=? AND template_id=? LIMIT 1");
            $s->execute([$parent,$templateId]);if(!$s->fetchColumn())throw new RuntimeException('Unité parente invalide.');
        }
        if($id){
            $s=$pdo->prepare("SELECT code FROM academic_template_units WHERE id=? AND template_id=? LIMIT 1");$s->execute([$id,$templateId]);$code=$s->fetchColumn();if(!$code)throw new RuntimeException('Unité nationale introuvable.');
            $pdo->prepare("UPDATE academic_template_units SET parent_id=?,type_unite=?,nom=?,ordre=?,actif=1 WHERE id=? AND template_id=?")->execute([$parent,$type,$nom,$ordre,$id,$templateId]);
            syncAcademicTemplateUnit($pdo,$templateId,$id);
        }else{
            $code=makeAcademicTemplateUnitCode($pdo,$templateId,$nom);
            $pdo->prepare("INSERT INTO academic_template_units(template_id,parent_id,type_unite,code,nom,ordre,actif) VALUES(?,?,?,?,?,?,1)")->execute([$templateId,$parent,$type,$code,$nom,$ordre]);
            $id=(int)$pdo->lastInsertId();syncAcademicTemplateUnit($pdo,$templateId,$id);
        }
    }

    if($entity==='DEPARTMENT'){
        $nom=trim($_POST['nom']??'');
        $unit=(int)($_POST['unit_id']??0)?:null;
        $ordre=(int)($_POST['ordre']??0);

        if($nom==='')throw new RuntimeException('Le nom du département est obligatoire.');
        if(!(int)$tpl['departement_active'])
            throw new RuntimeException('Les départements sont désactivés dans ce modèle.');

        if($unit&&!(int)$tpl['unite_academique_active'])
            throw new RuntimeException(
                "Ce modèle n'utilise pas les unités académiques. Enregistrez le département sans unité parente."
            );

        if($unit){
            $s=$pdo->prepare("
                SELECT id
                FROM academic_template_units
                WHERE id=? AND template_id=? AND actif=1
                LIMIT 1
            ");
            $s->execute([$unit,$templateId]);
            if(!$s->fetchColumn())throw new RuntimeException('Unité académique invalide.');
        }

        if($id){
            $s=$pdo->prepare("
                SELECT code
                FROM academic_template_departments
                WHERE id=? AND template_id=?
                LIMIT 1
            ");
            $s->execute([$id,$templateId]);
            $code=$s->fetchColumn();

            if(!$code)throw new RuntimeException('Département national introuvable.');

            $pdo->prepare("
                UPDATE academic_template_departments
                SET unit_id=?,nom=?,ordre=?,actif=1
                WHERE id=? AND template_id=?
            ")->execute([$unit,$nom,$ordre,$id,$templateId]);

            syncAcademicTemplateDepartment($pdo,$templateId,$id);
        }else{
            $code=makeAcademicTemplateDepartmentCode($pdo,$templateId,$nom);

            $pdo->prepare("
                INSERT INTO academic_template_departments(
                    template_id,unit_id,code,nom,ordre,actif
                ) VALUES(?,?,?,?,?,1)
            ")->execute([$templateId,$unit,$code,$nom,$ordre]);

            $id=(int)$pdo->lastInsertId();
            syncAcademicTemplateDepartment($pdo,$templateId,$id);
        }
    }

    if($entity==='PROGRAM'){
        $nom=trim($_POST['nom']??'');
        $mode=$_POST['rattachement_type']??'';
        $parent=(int)($_POST['parent_id']??0)?:null;
        $curriculum=(int)($_POST['curriculum_reference_id']??0);
        $duree=(int)($_POST['duree_annees']??0)?:null;
        $prep=(int)(($_POST['preparatory_level_enabled']??'0')==='1');
        $ordre=(int)($_POST['ordre']??0);

        if($nom===''||!$curriculum)
            throw new RuntimeException('Nom et référentiel de cursus obligatoires.');

        $unit=null;$dep=null;

        if($mode==='DEPARTMENT'){
            if(!(int)$tpl['departement_active'])
                throw new RuntimeException('Les départements sont désactivés.');

            $s=$pdo->prepare("
                SELECT id,unit_id
                FROM academic_template_departments
                WHERE id=? AND template_id=? AND actif=1
                LIMIT 1
            ");
            $s->execute([$parent,$templateId]);
            $r=$s->fetch(PDO::FETCH_ASSOC);

            if(!$r)throw new RuntimeException('Département invalide.');

            $dep=(int)$r['id'];
            $unit=(int)($r['unit_id']??0)?:null;
        }elseif($mode==='UNIT'){
            if(!(int)$tpl['unite_academique_active'])
                throw new RuntimeException("Les unités académiques sont désactivées dans ce modèle.");

            if(!(int)$tpl['filiere_directe_unite_autorisee'])
                throw new RuntimeException("Le rattachement direct à l'unité est interdit.");

            $s=$pdo->prepare("
                SELECT id
                FROM academic_template_units
                WHERE id=? AND template_id=? AND actif=1
                LIMIT 1
            ");
            $s->execute([$parent,$templateId]);

            if(!$s->fetchColumn())
                throw new RuntimeException('Unité invalide.');

            $unit=$parent;
        }elseif($mode==='ESTABLISHMENT'){
            if(!(int)$tpl['filiere_directe_etablissement_autorisee'])
                throw new RuntimeException("Le rattachement direct à l'établissement est interdit.");
        }else{
            throw new RuntimeException('Rattachement invalide.');
        }

        $s=$pdo->prepare("
            SELECT 1
            FROM curriculum_references
            WHERE id=? AND actif=1
            LIMIT 1
        ");
        $s->execute([$curriculum]);

        if(!$s->fetchColumn())
            throw new RuntimeException('Référentiel de cursus invalide.');

        if($id){
            $s=$pdo->prepare("
                SELECT code
                FROM academic_template_programs
                WHERE id=? AND template_id=?
                LIMIT 1
            ");
            $s->execute([$id,$templateId]);
            $code=$s->fetchColumn();

            if(!$code)throw new RuntimeException('Programme national introuvable.');

            $pdo->prepare("
                UPDATE academic_template_programs
                SET
                    unit_id=?,
                    department_id=?,
                    curriculum_reference_id=?,
                    nom=?,
                    duree_annees=?,
                    preparatory_level_enabled=?,
                    ordre=?,
                    actif=1
                WHERE id=? AND template_id=?
            ")->execute([
                $unit,$dep,$curriculum,$nom,$duree,$prep,$ordre,$id,$templateId
            ]);

            syncAcademicTemplateProgram($pdo,$templateId,$id);
        }else{
            $code=makeAcademicTemplateProgramCode($pdo,$templateId,$nom);

            $pdo->prepare("
                INSERT INTO academic_template_programs(
                    template_id,
                    unit_id,
                    department_id,
                    curriculum_reference_id,
                    code,
                    nom,
                    duree_annees,
                    preparatory_level_enabled,
                    ordre,
                    actif
                ) VALUES(?,?,?,?,?,?,?,?,?,1)
            ")->execute([
                $templateId,$unit,$dep,$curriculum,$code,$nom,$duree,$prep,$ordre
            ]);

            $id=(int)$pdo->lastInsertId();
            syncAcademicTemplateProgram($pdo,$templateId,$id);
        }
    }

    if($entity==='OPTION'){
        $program=(int)($_POST['program_id']??0);$nom=trim($_POST['nom']??'');$ordre=(int)($_POST['ordre']??0);
        if(!$program||$nom==='')throw new RuntimeException('Programme et nom obligatoires.');
        if(!(int)$tpl['option_specialite_active'])throw new RuntimeException('Les options / spécialités sont désactivées.');

        $s=$pdo->prepare("SELECT id FROM academic_template_programs WHERE id=? AND template_id=? AND actif=1 LIMIT 1");
        $s->execute([$program,$templateId]);if(!$s->fetchColumn())throw new RuntimeException('Programme invalide.');

        if($id){
            $s=$pdo->prepare("SELECT code FROM academic_template_options WHERE id=? AND template_id=? LIMIT 1");
            $s->execute([$id,$templateId]);$code=$s->fetchColumn();
            if(!$code)throw new RuntimeException('Option / spécialité introuvable.');
            $pdo->prepare("UPDATE academic_template_options SET program_id=?,nom=?,ordre=?,actif=1 WHERE id=? AND template_id=?")
                ->execute([$program,$nom,$ordre,$id,$templateId]);
            syncAcademicTemplateOption($pdo,$templateId,$id);
        }else{
            $code=makeCode($nom,'OPT');
            $s=$pdo->prepare("SELECT COUNT(*) FROM academic_template_options WHERE template_id=? AND program_id=? AND code=?");
            $s->execute([$templateId,$program,$code]);
            if((int)$s->fetchColumn())throw new RuntimeException('Une option / spécialité similaire existe déjà dans ce programme.');
            $pdo->prepare("INSERT INTO academic_template_options(template_id,program_id,code,nom,ordre,actif) VALUES(?,?,?,?,?,1)")
                ->execute([$templateId,$program,$code,$nom,$ordre]);
            $id=(int)$pdo->lastInsertId();
            syncAcademicTemplateOption($pdo,$templateId,$id);
        }
    }

    touchTemplate($pdo,$templateId);
    $pdo->commit();
    jsonResponse(true,'Structure du modèle enregistrée.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-unit-sync.php';
require_once __DIR__.'/../../includes/academic-department-sync.php';
require_once __DIR__.'/../../includes/academic-program-sync.php';

requireAjaxRole(['SUPER_ADMIN']);
verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
$action=strtoupper(trim((string)($_POST['action']??'')));
$comment=trim((string)($_POST['comment']??''));

if(!$id || !in_array($action,['APPROVE_LOCAL','INTEGRATE_NATIONAL','REFUSE'],true))
    jsonResponse(false,'Action invalide.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT
            p.*,
            eas.source_template_id
        FROM filieres p
        JOIN etablissement_academic_settings eas
          ON eas.etablissement_id=p.etablissement_id
        WHERE p.id=?
          AND p.ajoute_localement=1
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id]);
    $p=$s->fetch(PDO::FETCH_ASSOC);

    if(!$p)throw new RuntimeException('Ajout local introuvable.');

    $userId=(int)($_SESSION['user_id']??0)?:null;

    if($action==='APPROVE_LOCAL'){
        $pdo->prepare("
            UPDATE filieres
            SET
                validation_statut='VALIDE_LOCAL',
                actif=1,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([$userId,$comment?:null,$id]);

        $message='Filière / programme validé uniquement pour cet établissement.';
    }

    if($action==='REFUSE'){
        if($comment==='')throw new RuntimeException('Le motif du refus est obligatoire.');

        $pdo->prepare("
            UPDATE filieres
            SET
                validation_statut='REFUSE',
                actif=0,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([$userId,$comment,$id]);

        $message='Filière / programme local refusé.';
    }

    if($action==='INTEGRATE_NATIONAL'){
        $templateId=(int)$p['source_template_id'];
        if(!$templateId)
            throw new RuntimeException("Aucun modèle académique source n'est associé.");

        $s=$pdo->prepare("
            SELECT *
            FROM academic_structure_templates
            WHERE id=? AND actif=1
            LIMIT 1
        ");
        $s->execute([$templateId]);
        $tpl=$s->fetch(PDO::FETCH_ASSOC);

        if(!$tpl)throw new RuntimeException('Modèle académique introuvable.');

        $templateUnitId=null;
        $templateDepartmentId=null;

        if((int)($p['departement_id']??0)){
            $s=$pdo->prepare("
                SELECT source_template_department_id,faculte_id
                FROM departements
                WHERE id=?
                  AND etablissement_id=?
                LIMIT 1
            ");
            $s->execute([(int)$p['departement_id'],(int)$p['etablissement_id']]);
            $dep=$s->fetch(PDO::FETCH_ASSOC);

            $templateDepartmentId=(int)($dep['source_template_department_id']??0)?:null;

            if(!$templateDepartmentId){
                throw new RuntimeException(
                    "Le département parent doit d'abord être intégré au référentiel national."
                );
            }

            if((int)($dep['faculte_id']??0)){
                $s=$pdo->prepare("
                    SELECT source_template_unit_id
                    FROM facultes
                    WHERE id=?
                      AND etablissement_id=?
                    LIMIT 1
                ");
                $s->execute([(int)$dep['faculte_id'],(int)$p['etablissement_id']]);
                $templateUnitId=(int)$s->fetchColumn()?:null;
            }
        }elseif((int)($p['faculte_id']??0)){
            if(!(int)$tpl['filiere_directe_unite_autorisee'])
                throw new RuntimeException("Le modèle n'autorise pas le rattachement direct à une unité.");

            $s=$pdo->prepare("
                SELECT source_template_unit_id
                FROM facultes
                WHERE id=?
                  AND etablissement_id=?
                LIMIT 1
            ");
            $s->execute([(int)$p['faculte_id'],(int)$p['etablissement_id']]);
            $templateUnitId=(int)$s->fetchColumn()?:null;

            if(!$templateUnitId){
                throw new RuntimeException(
                    "L'unité académique parente doit d'abord être intégrée au référentiel national."
                );
            }
        }else{
            if(!(int)$tpl['filiere_directe_etablissement_autorisee'])
                throw new RuntimeException("Le modèle n'autorise pas le rattachement direct à l'établissement.");
        }

        $s=$pdo->prepare("
            SELECT 1
            FROM curriculum_references
            WHERE id=? AND actif=1
            LIMIT 1
        ");
        $s->execute([(int)$p['curriculum_reference_id']]);

        if(!$s->fetchColumn())
            throw new RuntimeException('Le référentiel de cursus associé est invalide ou inactif.');

        // Recherche d'un équivalent déjà présent dans le modèle.
        $s=$pdo->prepare("
            SELECT id,code
            FROM academic_template_programs
            WHERE template_id=?
              AND LOWER(TRIM(nom))=LOWER(TRIM(?))
              AND curriculum_reference_id=?
              AND department_id <=> ?
              AND unit_id <=> ?
            LIMIT 1
        ");
        $s->execute([
            $templateId,
            $p['nom'],
            (int)$p['curriculum_reference_id'],
            $templateDepartmentId,
            $templateUnitId
        ]);
        $existing=$s->fetch(PDO::FETCH_ASSOC);

        if($existing){
            $templateProgramId=(int)$existing['id'];
            $code=$existing['code'];

            $pdo->prepare("
                UPDATE academic_template_programs
                SET
                    nom=?,
                    duree_annees=?,
                    preparatory_level_enabled=?,
                    actif=1
                WHERE id=? AND template_id=?
            ")->execute([
                $p['nom'],
                $p['duree_annees'],
                (int)$p['preparatory_level_enabled'],
                $templateProgramId,
                $templateId
            ]);
        }else{
            $code=makeAcademicTemplateProgramCode($pdo,$templateId,$p['nom']);

            $s=$pdo->prepare("
                SELECT COALESCE(MAX(ordre),0)+10
                FROM academic_template_programs
                WHERE template_id=?
            ");
            $s->execute([$templateId]);
            $ordre=(int)$s->fetchColumn();

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
                $templateId,
                $templateUnitId,
                $templateDepartmentId,
                (int)$p['curriculum_reference_id'],
                $code,
                $p['nom'],
                $p['duree_annees'],
                (int)$p['preparatory_level_enabled'],
                $ordre
            ]);

            $templateProgramId=(int)$pdo->lastInsertId();
        }

        $pdo->prepare("
            UPDATE filieres
            SET
                source_template_program_id=?,
                code=?,
                validation_statut='INTEGRE_REFERENTIEL',
                actif=1,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([
            $templateProgramId,
            $code,
            $userId,
            $comment?:null,
            $id
        ]);

        $pdo->prepare("
            UPDATE academic_structure_templates
            SET version_no=version_no+1
            WHERE id=?
        ")->execute([$templateId]);

        syncAcademicTemplateProgram($pdo,$templateId,$templateProgramId);

        $message='Filière / programme intégré au référentiel national et propagé aux établissements concernés.';
    }

    $pdo->commit();
    jsonResponse(true,$message);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

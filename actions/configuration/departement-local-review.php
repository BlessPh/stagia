<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-unit-sync.php';
require_once __DIR__.'/../../includes/academic-department-sync.php';

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
            d.*,
            eas.source_template_id
        FROM departements d
        JOIN etablissement_academic_settings eas
          ON eas.etablissement_id=d.etablissement_id
        WHERE d.id=?
          AND d.ajoute_localement=1
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id]);
    $d=$s->fetch(PDO::FETCH_ASSOC);

    if(!$d)throw new RuntimeException('Ajout local introuvable.');

    $userId=(int)($_SESSION['user_id']??0)?:null;

    if($action==='APPROVE_LOCAL'){
        $pdo->prepare("
            UPDATE departements
            SET
                validation_statut='VALIDE_LOCAL',
                actif=1,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([$userId,$comment?:null,$id]);

        $message='Département validé pour cet établissement uniquement.';
    }

    if($action==='REFUSE'){
        if($comment==='')throw new RuntimeException('Le motif du refus est obligatoire.');

        $pdo->prepare("
            UPDATE departements
            SET
                validation_statut='REFUSE',
                actif=0,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([$userId,$comment,$id]);

        $message='Département local refusé.';
    }

    if($action==='INTEGRATE_NATIONAL'){
        $templateId=(int)$d['source_template_id'];
        if(!$templateId)throw new RuntimeException("Aucun modèle académique source n'est associé.");

        $templateUnitId=null;

        if((int)($d['faculte_id']??0)){
            $s=$pdo->prepare("
                SELECT source_template_unit_id
                FROM facultes
                WHERE id=?
                  AND etablissement_id=?
                LIMIT 1
            ");
            $s->execute([(int)$d['faculte_id'],(int)$d['etablissement_id']]);
            $templateUnitId=(int)$s->fetchColumn()?:null;

            if(!$templateUnitId){
                throw new RuntimeException(
                    "L'unité académique parente doit d'abord être intégrée au référentiel national."
                );
            }
        }

        $code=makeAcademicTemplateDepartmentCode($pdo,$templateId,$d['nom']);

        $s=$pdo->prepare("
            SELECT COALESCE(MAX(ordre),0)+10
            FROM academic_template_departments
            WHERE template_id=?
        ");
        $s->execute([$templateId]);
        $ordre=(int)$s->fetchColumn();

        $pdo->prepare("
            INSERT INTO academic_template_departments(
                template_id,unit_id,code,nom,ordre,actif
            ) VALUES(?,?,?,?,?,1)
        ")->execute([
            $templateId,
            $templateUnitId,
            $code,
            $d['nom'],
            $ordre
        ]);

        $templateDepartmentId=(int)$pdo->lastInsertId();

        $pdo->prepare("
            UPDATE departements
            SET
                source_template_department_id=?,
                code=?,
                validation_statut='INTEGRE_REFERENTIEL',
                actif=1,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([
            $templateDepartmentId,
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

        syncAcademicTemplateDepartment($pdo,$templateId,$templateDepartmentId);

        $message='Département intégré au référentiel national et propagé aux établissements concernés.';
    }

    $pdo->commit();
    jsonResponse(true,$message);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

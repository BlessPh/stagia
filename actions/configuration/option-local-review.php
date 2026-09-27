<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/academic-option-sync.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
$action=strtoupper(trim((string)($_POST['action']??'')));
$comment=trim((string)($_POST['comment']??''));
if(!$id||!in_array($action,['APPROVE_LOCAL','INTEGRATE_NATIONAL','REFUSE'],true))
    jsonResponse(false,'Action invalide.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT o.*,f.code filiere_code,f.nom filiere_nom,
               eas.source_template_id,e.type_etablissement
        FROM options_specialites o
        JOIN filieres f ON f.id=o.filiere_id
        JOIN etablissements e ON e.id=o.etablissement_id
        JOIN etablissement_academic_settings eas ON eas.etablissement_id=e.id
        WHERE o.id=? AND o.ajoute_localement=1
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$id]);$o=$s->fetch(PDO::FETCH_ASSOC);
    if(!$o)throw new RuntimeException('Ajout local introuvable.');

    $userId=(int)($_SESSION['user_id']??0)?:null;

    if($action==='APPROVE_LOCAL'){
        $pdo->prepare("
            UPDATE options_specialites
            SET validation_statut='VALIDE_LOCAL',actif=1,
                reviewed_by_user_id=?,reviewed_at=NOW(),review_comment=?
            WHERE id=?
        ")->execute([$userId,$comment?:null,$id]);
        $message='Option validée pour cet établissement uniquement.';
    }

    if($action==='REFUSE'){
        if($comment==='')throw new RuntimeException('Le motif du refus est obligatoire.');
        $pdo->prepare("
            UPDATE options_specialites
            SET validation_statut='REFUSE',actif=0,
                reviewed_by_user_id=?,reviewed_at=NOW(),review_comment=?
            WHERE id=?
        ")->execute([$userId,$comment,$id]);
        $message='Ajout local refusé.';
    }

    if($action==='INTEGRATE_NATIONAL'){
        $templateId=(int)$o['source_template_id'];
        if(!$templateId)throw new RuntimeException("L'établissement n'utilise aucun modèle académique source.");

        $s=$pdo->prepare("
            SELECT id
            FROM academic_template_programs
            WHERE template_id=? AND code=? AND actif=1
            LIMIT 1
        ");
        $s->execute([$templateId,$o['filiere_code']]);
        $programId=(int)$s->fetchColumn();
        if(!$programId)
            throw new RuntimeException("La filière locale « {$o['filiere_nom']} » n'existe pas encore dans le référentiel national. Intégrez d'abord cette filière.");

        $code=makeAcademicOptionCode($o['code']?:$o['nom']);
        $s=$pdo->prepare("
            SELECT id
            FROM academic_template_options
            WHERE template_id=? AND program_id=? AND code=?
            LIMIT 1
        ");
        $s->execute([$templateId,$programId,$code]);
        $templateOptionId=(int)$s->fetchColumn();

        if(!$templateOptionId){
            $s=$pdo->prepare("SELECT COALESCE(MAX(ordre),0)+10 FROM academic_template_options WHERE template_id=? AND program_id=?");
            $s->execute([$templateId,$programId]);$ordre=(int)$s->fetchColumn();

            $pdo->prepare("
                INSERT INTO academic_template_options(template_id,program_id,code,nom,ordre,actif)
                VALUES(?,?,?,?,?,1)
            ")->execute([$templateId,$programId,$code,$o['nom'],$ordre]);
            $templateOptionId=(int)$pdo->lastInsertId();
        }else{
            $pdo->prepare("UPDATE academic_template_options SET nom=?,actif=1 WHERE id=?")
                ->execute([$o['nom'],$templateOptionId]);
        }

        $pdo->prepare("
            UPDATE options_specialites
            SET source_template_option_id=?,
                validation_statut='INTEGRE_REFERENTIEL',
                actif=1,
                reviewed_by_user_id=?,reviewed_at=NOW(),review_comment=?
            WHERE id=?
        ")->execute([$templateOptionId,$userId,$comment?:null,$id]);

        $pdo->prepare("UPDATE academic_structure_templates SET version_no=version_no+1 WHERE id=?")
            ->execute([$templateId]);

        syncAcademicTemplateOption($pdo,$templateId,$templateOptionId);
        $message='Option intégrée au référentiel national et propagée aux établissements concernés.';
    }

    $pdo->commit();
    jsonResponse(true,$message);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

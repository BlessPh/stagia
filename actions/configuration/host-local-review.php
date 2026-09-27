<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/host-unit-template-sync.php';

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
            h.*,
            e.type_etablissement
        FROM host_units h
        JOIN etablissements e
          ON e.id=h.host_etablissement_id
        WHERE h.id=?
          AND h.ajoute_localement=1
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id]);
    $h=$s->fetch(PDO::FETCH_ASSOC);

    if(!$h)throw new RuntimeException('Ajout local introuvable.');

    $userId=(int)($_SESSION['user_id']??0)?:null;

    if($action==='APPROVE_LOCAL'){
        $u=$pdo->prepare("
            UPDATE host_units
            SET
                validation_statut='VALIDE_LOCAL',
                actif=1,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
              AND ajoute_localement=1
              AND validation_statut='EN_ATTENTE'
        ");
        $u->execute([$userId,$comment?:null,$id]);

        if($u->rowCount()!==1){
            throw new RuntimeException(
                "La validation n'a modifié aucune ligne. Rechargez la liste puis réessayez."
            );
        }

        $message="Structure validée uniquement pour cet établissement d'accueil.";
    }

    if($action==='REFUSE'){
        if($comment==='')throw new RuntimeException('Le motif du refus est obligatoire.');

        $pdo->prepare("
            UPDATE host_units
            SET
                validation_statut='REFUSE',
                actif=0,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([$userId,$comment,$id]);

        $message='Structure locale refusée.';
    }

    if($action==='INTEGRATE_NATIONAL'){
        $typeCode=$h['type_etablissement'];
        $parentTemplateId=null;

        if((int)($h['parent_id']??0)){
            $s=$pdo->prepare("
                SELECT source_template_host_unit_id
                FROM host_units
                WHERE id=?
                  AND host_etablissement_id=?
                LIMIT 1
            ");
            $s->execute([(int)$h['parent_id'],(int)$h['host_etablissement_id']]);
            $parentTemplateId=(int)$s->fetchColumn()?:null;

            if(!$parentTemplateId){
                throw new RuntimeException(
                    "La structure parente doit d'abord être intégrée au référentiel national."
                );
            }
        }

        // Rechercher un équivalent existant dans le référentiel.
        $s=$pdo->prepare("
            SELECT id,code
            FROM host_unit_templates
            WHERE establishment_type_code=?
              AND LOWER(TRIM(nom))=LOWER(TRIM(?))
              AND type=?
              AND parent_id <=> ?
            LIMIT 1
        ");
        $s->execute([
            $typeCode,
            $h['nom'],
            $h['type'],
            $parentTemplateId
        ]);
        $existing=$s->fetch(PDO::FETCH_ASSOC);

        if($existing){
            $templateId=(int)$existing['id'];
            $code=$existing['code'];

            $pdo->prepare("
                UPDATE host_unit_templates
                SET
                    description=?,
                    capacite=NULL,
                    actif=1
                WHERE id=?
            ")->execute([
                $h['description'],
                $templateId
            ]);
        }else{
            $code=nextHostTemplateCode($pdo,$typeCode,$h['type']);

            $s=$pdo->prepare("
                SELECT COALESCE(MAX(ordre),0)+10
                FROM host_unit_templates
                WHERE establishment_type_code=?
            ");
            $s->execute([$typeCode]);
            $ordre=(int)$s->fetchColumn();

            $pdo->prepare("
                INSERT INTO host_unit_templates(
                    establishment_type_code,
                    parent_id,
                    code,
                    nom,
                    type,
                    description,
                    capacite,
                    ordre,
                    actif
                ) VALUES(?,?,?,?,?,NULL,?,1)
            ")->execute([
                $typeCode,
                $parentTemplateId,
                $code,
                $h['nom'],
                $h['type'],
                $h['description'],
                $ordre
            ]);

            $templateId=(int)$pdo->lastInsertId();
        }

        $pdo->prepare("
            UPDATE host_units
            SET
                source_template_host_unit_id=?,
                code=?,
                validation_statut='INTEGRE_REFERENTIEL',
                actif=1,
                reviewed_by_user_id=?,
                reviewed_at=NOW(),
                review_comment=?
            WHERE id=?
        ")->execute([
            $templateId,
            $code,
            $userId,
            $comment?:null,
            $id
        ]);

        syncHostUnitTemplate($pdo,$templateId);

        $message="Structure intégrée au référentiel national et propagée aux établissements d'accueil du même type.";
    }

    $s=$pdo->prepare("
        SELECT
            id,code,nom,type,validation_statut,actif,
            reviewed_by_user_id,reviewed_at,review_comment
        FROM host_units
        WHERE id=?
        LIMIT 1
    ");
    $s->execute([$id]);
    $updated=$s->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    jsonResponse(true,$message,['item'=>$updated]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

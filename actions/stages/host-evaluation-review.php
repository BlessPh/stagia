<?php
/**
 * Endpoint AJAX de revue d'une évaluation : validation, retour en brouillon ou finalisation.
 * Les séparations d'auteur, de périmètre chef de service et de permission évitent l'auto-validation.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Chef de service : valider ou retourner.
   Administration : finaliser si permission. */
requireAjaxRole([
    'CHEF_SERVICE',
    'ADMIN_ACCUEIL',
    'ENCADREUR',
    'EVALUATEUR_CLINIQUE'
,'ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_STAGE_CLINIQUE','MAITRE_DE_STAGE_CLINIQUE']);

$csrf=$_POST['csrf']??'';

if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

/* Permission RBAC classique. */
/** Vérifie un droit RBAC actif de revue ou de finalisation pour l'établissement courant. */
function evReviewPerm(PDO $pdo,int $uid,int $eid,string $code):bool{
    $s=$pdo->prepare("
        SELECT 1
        FROM role_assignments ra
        JOIN role_permissions rp ON rp.role_id=ra.role_id
        JOIN permissions p ON p.id=rp.permission_id
        WHERE ra.user_id=?
          AND ra.actif=1
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
          AND p.code=?
          AND p.actif=1
          AND (ra.scope_type='PLATFORM' OR ra.etablissement_id=?)
        LIMIT 1
    ");
    $s->execute([$uid,$code,$eid]);
    return (bool)$s->fetchColumn();
}

/* Vérifie que le chef couvre le service ou son département parent. */
/** Vérifie le périmètre organisationnel ou unitaire d'un chef de service. */
function evReviewChefUnit(PDO $pdo,int $uid,int $eid,int $unitId):bool{
    $s=$pdo->prepare("
        SELECT 1
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        LEFT JOIN host_units hu ON hu.id=?
        WHERE ra.user_id=?
          AND (r.code='CHEF_SERVICE' OR r.code LIKE '%ROLE_CHEF_SERVICE')
          AND ra.etablissement_id=?
          AND ra.actif=1
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
          AND (
                ra.scope_type='ORGANIZATION'
                OR (
                    ra.scope_type='UNIT'
                    AND (
                        ra.scope_id=?
                        OR ra.scope_id=hu.parent_id
                    )
                )
          )
        LIMIT 1
    ");
    $s->execute([$unitId,$uid,$eid,$unitId]);
    return (bool)$s->fetchColumn();
}

try{
    /* Les identifiants et l'action sont validés avant le verrouillage de l'évaluation. */
    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $role=strtoupper(trim((string)($_SESSION['role_code']??'')));

    $id=(int)($_POST['id']??0);
    $action=strtoupper(trim((string)($_POST['action']??'')));

    if(!$eid||!$uid)
        jsonResponse(false,'Session invalide.',[],403);

    if(!$id)
        jsonResponse(false,'Évaluation invalide.',[],422);

    if(!in_array($action,['VALIDATE','RETURN','FINALIZE'],true))
        jsonResponse(false,'Action invalide.',[],422);

    $isChef=$role==='CHEF_SERVICE'||preg_match('/ROLE_CHEF_SERVICE$/',$role);

    /* Le verrou protège la transition de statut contre les décisions concurrentes. */
    $pdo->beginTransaction();

    /* Charge l’évaluation + rotation + service. */
    $s=$pdo->prepare("
        SELECT
            e.id,
            e.rotation_id,
            e.evaluator_user_id,
            e.statut,
            e.host_etablissement_id,
            r.host_unit_id,
            r.host_etablissement_id rotation_host_id
        FROM stage_evaluations e
        JOIN stage_rotations r ON r.id=e.rotation_id
        WHERE e.id=?
          AND e.host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id,$eid]);
    $ev=$s->fetch(PDO::FETCH_ASSOC);

    if(!$ev)
        throw new RuntimeException('Évaluation introuvable.');

    $unitId=(int)$ev['host_unit_id'];
    $oldStatus=(string)$ev['statut'];

    /* Chef autorisé par périmètre OU permission classique. */
    /* La revue combine le périmètre de chef et la permission RBAC explicite. */
    $canReview=
        ($isChef&&evReviewChefUnit($pdo,$uid,$eid,$unitId))
        || evReviewPerm($pdo,$uid,$eid,'evaluation.validate');

    /* Un chef ou un utilisateur habilité valide une soumission d'un autre évaluateur. */
    if($action==='VALIDATE'){
        if($oldStatus!=='SOUMISE')
            throw new RuntimeException('Seule une évaluation soumise peut être validée.');

        if((int)$ev['evaluator_user_id']===$uid)
            throw new RuntimeException('Vous ne pouvez pas valider votre propre évaluation.');

        if(!$canReview)
            throw new RuntimeException("Vous n'êtes pas autorisé à valider cette évaluation.");

        $newStatus='VALIDEE';

        $pdo->prepare("
            UPDATE stage_evaluations
            SET statut='VALIDEE',
                validated_at=NOW(),
                validated_by=?,
                finalized_at=NULL,
                finalized_by=NULL
            WHERE id=?
        ")->execute([$uid,$id]);
    }

    /* Le retour rend le brouillon à son auteur afin qu'il puisse le corriger. */
    if($action==='RETURN'){
        if($oldStatus!=='SOUMISE')
            throw new RuntimeException('Seule une évaluation soumise peut être retournée.');

        if((int)$ev['evaluator_user_id']===$uid)
            throw new RuntimeException('Vous ne pouvez pas retourner votre propre évaluation.');

        if(!$canReview)
            throw new RuntimeException("Vous n'êtes pas autorisé à retourner cette évaluation.");

        /* Retour correction : on remet en BROUILLON pour que l’encadreur puisse modifier. */
        $newStatus='BROUILLON';

        $pdo->prepare("
            UPDATE stage_evaluations
            SET statut='BROUILLON',
                submitted_at=NULL,
                validated_at=NULL,
                validated_by=NULL,
                finalized_at=NULL,
                finalized_by=NULL
            WHERE id=?
        ")->execute([$id]);
    }

    /* La finalisation clôt le cycle après une validation préalable. */
    if($action==='FINALIZE'){
        if($oldStatus!=='VALIDEE')
            throw new RuntimeException('Seule une évaluation validée peut être finalisée.');

        if(!evReviewPerm($pdo,$uid,$eid,'evaluation.finalize'))
            throw new RuntimeException("Vous n'êtes pas autorisé à finaliser cette évaluation.");

        $newStatus='FINALISEE';

        $pdo->prepare("
            UPDATE stage_evaluations
            SET statut='FINALISEE',
                finalized_at=NOW(),
                finalized_by=?
            WHERE id=?
        ")->execute([$uid,$id]);
    }

    /* Historique du changement de statut. */
    $pdo->prepare("
        INSERT INTO stage_evaluation_status_history(
            evaluation_id,
            previous_status,
            new_status,
            changed_by_user_id
        )
        VALUES(?,?,?,?)
    ")->execute([$id,$oldStatus,$newStatus,$uid]);

    $pdo->commit();

    $message=match($newStatus){
        'VALIDEE'=>'Évaluation validée par le Chef de service.',
        'BROUILLON'=>'Évaluation retournée à l’encadreur pour correction.',
        'FINALISEE'=>'Évaluation finalisée.',
        default=>'Action effectuée.'
    };

    jsonResponse(true,$message,[
        'id'=>$id,
        'statut'=>$newStatus
    ]);

}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],422);
}

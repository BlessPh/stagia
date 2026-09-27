<?php
/**
 * Endpoint AJAX de création d'un type de stage local à un établissement.
 * Le type, ses niveaux éligibles et ses politiques financières sont enregistrés dans une seule transaction.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-campaign.php';
require_once __DIR__.'/../../includes/stage-type.php';

verifyAjaxCsrf();

try{
    /* Les niveaux et conditions financières sont validés par les services métier partagés. */
    requireStageTypeManage($pdo);

    $eid=stageTypeEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0)?:null;

    $label=trim((string)($_POST['libelle']??''));
    $description=trim((string)($_POST['description']??''));
    $objectif=trim((string)($_POST['objectif']??''));

    $levels=stageTypeValidateLevels(
        $pdo,
        $eid,
        (array)($_POST['eligible_level_codes']??[])
    );

    $financial=stageTypeValidateFinancial($_POST);

    if($label===''||mb_strlen($label)>150)
        throw new RuntimeException(
            'Le libellé est obligatoire et limité à 150 caractères.'
        );

    if(mb_strlen($description)>1000)
        throw new RuntimeException(
            'La description est limitée à 1000 caractères.'
        );

    if(mb_strlen($objectif)>1000)
        throw new RuntimeException(
            "L'objectif est limité à 1000 caractères."
        );

    /* Un libellé ne peut pas dupliquer un type global ou local déjà visible dans cet espace. */
    $s=$pdo->prepare("
        SELECT id
        FROM stage_types
        WHERE (
            owner_etablissement_id IS NULL
            OR owner_etablissement_id=?
        )
        AND LOWER(TRIM(libelle))=LOWER(TRIM(?))
        LIMIT 1
    ");

    $s->execute([
        $eid,
        $label
    ]);

    if($s->fetchColumn())
        throw new RuntimeException(
            'Un type de stage portant ce libellé existe déjà dans votre espace.'
        );

    /* Le code fonctionnel est généré avant la création à partir du libellé et de l'établissement. */
    $code=stageTypeGenerateCode(
        $pdo,
        $eid,
        $label
    );

    /* Le type et ses règles associées doivent exister ensemble ou pas du tout. */
    $pdo->beginTransaction();

    $pdo->prepare("
        INSERT INTO stage_types(
            code,
            libelle,
            description,
            objectif,
            owner_etablissement_id,
            created_by_user_id,
            actif
        )
        VALUES(?,?,?,?,?,?,1)
    ")->execute([
        $code,
        $label,
        $description!==''?$description:null,
        $objectif!==''?$objectif:null,
        $eid,
        $userId
    ]);

    $id=(int)$pdo->lastInsertId();

    /* Persistance des niveaux autorisés et de la politique financière validée. */
    stageTypeSaveEligibleLevels(
        $pdo,
        $id,
        $levels
    );

    stageTypeSaveFinancialPolicy(
        $pdo,
        $id,
        $financial
    );

    $pdo->commit();

    $policies=stagePolicySnapshot(
        $pdo,
        $id
    );

    jsonResponse(
        true,
        'Type de stage créé.',
        [
            'type'=>[
                'id'=>$id,
                'code'=>$code,
                'libelle'=>$label,
                'description'=>$description?:null,
                'objectif'=>$objectif?:null,
                'owner_etablissement_id'=>$eid,
                'created_by_user_id'=>$userId,
                'actif'=>1,
                'local'=>true,
                'legacy'=>false,
                'policies'=>$policies,
                'financial'=>stageTypeFinancialFromPolicies(
                    $policies,
                    false
                )
            ]
        ]
    );

}catch(Throwable $e){
    /* Retour arrière complet si une règle de type ou de politique échoue. */

    if($pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(
        false,
        $e->getMessage(),
        [],
        422
    );
}

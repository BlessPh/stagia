<?php
/**
 * Endpoint AJAX de modification d'un type de stage appartenant à l'établissement courant.
 * Les types globaux et ceux d'autres établissements restent volontairement immuables ici.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-type.php';

verifyAjaxCsrf();

try{
    /* Les valeurs éditables et les politiques associées sont validées avant le contrôle de propriété. */
    requireStageTypeManage($pdo);
    $eid=stageTypeEtablissementId($pdo);
    $id=(int)($_POST['id']??0);
    $label=trim((string)($_POST['libelle']??''));
    $description=trim((string)($_POST['description']??''));
    $levels=stageTypeValidateLevels($pdo,$eid,(array)($_POST['eligible_level_codes']??[]));
    $financial=stageTypeValidateFinancial($_POST);

    if(!$id)throw new RuntimeException('Type de stage invalide.');
    if($label===''||mb_strlen($label)>150)
        throw new RuntimeException('Le libellé est obligatoire et limité à 150 caractères.');
    if(mb_strlen($description)>1000)
        throw new RuntimeException('La description est limitée à 1000 caractères.');

    /* Le filtre de propriété empêche toute modification d'un type global ou étranger. */
    $s=$pdo->prepare("SELECT id FROM stage_types WHERE id=? AND owner_etablissement_id=? LIMIT 1");
    $s->execute([$id,$eid]);
    if(!$s->fetchColumn())
        throw new RuntimeException('Vous ne pouvez modifier que les types créés par votre établissement.');

    $s=$pdo->prepare("
        SELECT id FROM stage_types
        WHERE id<>?
          AND (owner_etablissement_id IS NULL OR owner_etablissement_id=?)
          AND LOWER(TRIM(libelle))=LOWER(TRIM(?))
        LIMIT 1
    ");
    $s->execute([$id,$eid,$label]);
    if($s->fetchColumn())
        throw new RuntimeException('Un autre type portant ce libellé existe déjà dans votre espace.');

    /* Mise à jour de l'entête, des niveaux et des conditions financières dans une transaction unique. */
    $pdo->beginTransaction();
    $pdo->prepare("
        UPDATE stage_types SET libelle=?,description=?
        WHERE id=? AND owner_etablissement_id=?
    ")->execute([$label,$description?:null,$id,$eid]);

    stageTypeSaveEligibleLevels($pdo,$id,$levels);
    stageTypeSaveFinancialPolicy($pdo,$id,$financial);
    $pdo->commit();

    jsonResponse(true,'Type de stage modifié. Les campagnes déjà créées conservent leur ancienne condition financière.');
}catch(Throwable $e){
    /* Une erreur restaure l'état précédent de toutes les politiques associées. */
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

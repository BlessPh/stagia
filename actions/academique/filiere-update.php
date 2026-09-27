<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$cfg=$_SESSION['academic_settings']??[];

$id=(int)($_POST['id']??0);
$nom=trim((string)($_POST['nom']??''));
$curriculum=(int)($_POST['curriculum_reference_id']??0);
$duree=(int)($_POST['duree_annees']??0)?:null;
$prep=(int)(($_POST['preparatory_level_enabled']??'0')==='1');
$description=trim((string)($_POST['description']??''));

$hasParent=(int)($_POST['has_parent']??0)===1;

$mode=$hasParent
    ?strtoupper(trim((string)($_POST['parent_type']??'')))
    :'ETABLISSEMENT';

$parentId=$hasParent
    ?((int)($_POST['parent_id']??0)?:null)
    :null;

if(!$eid)
    jsonResponse(false,'Aucun établissement actif.',[],403);

if(empty($cfg['filiere_active']))
    jsonResponse(
        false,
        'Les filières / programmes sont désactivés.',
        [],
        403
    );

if(!$id || $nom==='' || !$curriculum)
    jsonResponse(false,'Données invalides.',[],422);

if(mb_strlen($nom)>150)
    jsonResponse(false,'Le nom est limité à 150 caractères.',[],422);

if($duree!==null && ($duree<1 || $duree>20))
    jsonResponse(false,'Durée invalide.',[],422);

if($hasParent && !in_array($mode,['DEPARTEMENT','UNITE'],true))
    jsonResponse(false,'Type de parent invalide.',[],422);

if($hasParent && !$parentId)
    jsonResponse(false,'Choisissez la structure parente.',[],422);

$s=$pdo->prepare("
    SELECT
        ajoute_localement,
        validation_statut
    FROM filieres
    WHERE id=?
      AND etablissement_id=?
    LIMIT 1
");

$s->execute([
    $id,
    $eid
]);

$row=$s->fetch(PDO::FETCH_ASSOC);

if(!$row)
    jsonResponse(false,'Filière / programme introuvable.',[],404);

if(!(int)$row['ajoute_localement'])
    jsonResponse(
        false,
        'Une filière nationale STAGIA ne peut pas être modifiée localement.',
        [],
        403
    );

$s=$pdo->prepare("
    SELECT id
    FROM curriculum_references
    WHERE id=?
      AND actif=1
    LIMIT 1
");

$s->execute([$curriculum]);

if(!$s->fetchColumn())
    jsonResponse(false,'Référentiel de cursus invalide.',[],422);

$departementId=null;
$faculteId=null;

if($mode==='DEPARTEMENT'){

    $s=$pdo->prepare("
        SELECT
            id,
            faculte_id
        FROM departements
        WHERE id=?
          AND etablissement_id=?
          AND actif=1
          AND validation_statut IN(
              'NATIONAL',
              'VALIDE_LOCAL',
              'INTEGRE_REFERENTIEL'
          )
        LIMIT 1
    ");

    $s->execute([
        $parentId,
        $eid
    ]);

    $dep=$s->fetch(PDO::FETCH_ASSOC);

    if(!$dep)
        jsonResponse(false,'Département invalide ou inactif.',[],422);

    $departementId=(int)$dep['id'];
    $faculteId=(int)($dep['faculte_id']??0)?:null;
}

if($mode==='UNITE'){

    $s=$pdo->prepare("
        SELECT id
        FROM facultes
        WHERE id=?
          AND etablissement_id=?
          AND actif=1
          AND validation_statut IN(
              'NATIONAL',
              'VALIDE_LOCAL',
              'INTEGRE_REFERENTIEL'
          )
        LIMIT 1
    ");

    $s->execute([
        $parentId,
        $eid
    ]);

    if(!$s->fetchColumn())
        jsonResponse(
            false,
            'Unité académique invalide ou inactive.',
            [],
            422
        );

    $faculteId=$parentId;
}

$s=$pdo->prepare("
    SELECT id
    FROM filieres
    WHERE etablissement_id=?
      AND id<>?
      AND LOWER(TRIM(nom))=LOWER(TRIM(?))
      AND departement_id <=> ?
      AND faculte_id <=> ?
      AND validation_statut<>'REFUSE'
    LIMIT 1
");

$s->execute([
    $eid,
    $id,
    $nom,
    $departementId,
    $faculteId
]);

if($s->fetchColumn())
    jsonResponse(
        false,
        'Une autre filière / un autre programme porte déjà ce nom dans ce rattachement.',
        [],
        409
    );

try{

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE filieres
        SET
            faculte_id=?,
            departement_id=?,
            curriculum_reference_id=?,
            preparatory_level_enabled=?,
            nom=?,
            duree_annees=?,
            description=?,
            motif_ajout=NULL,
            validation_statut='VALIDE_LOCAL',
            actif=1,
            reviewed_by_user_id=NULL,
            reviewed_at=NULL,
            review_comment=NULL
        WHERE id=?
          AND etablissement_id=?
          AND ajoute_localement=1
    ")->execute([
        $faculteId,
        $departementId,
        $curriculum,
        $prep,
        $nom,
        $duree,
        $description!==''?$description:null,
        $id,
        $eid
    ]);

    $pdo->commit();

    jsonResponse(
        true,
        'Filière / programme modifié avec succès.'
    );

}catch(Throwable $e){

    if($pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(
        false,
        $e->getMessage(),
        [],
        422
    );
}

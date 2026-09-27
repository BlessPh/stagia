<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$cfg=$_SESSION['academic_settings']??[];

if(!$eid)
    jsonResponse(false,'Aucun établissement actif.',[],403);

if(empty($cfg['filiere_active']))
    jsonResponse(
        false,
        'Les filières / programmes sont désactivés.',
        [],
        403
    );

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

if($nom==='')
    jsonResponse(false,'Le nom est obligatoire.',[],422);

if(mb_strlen($nom)>150)
    jsonResponse(false,'Le nom est limité à 150 caractères.',[],422);

if(!$curriculum)
    jsonResponse(false,'Le référentiel de cursus est obligatoire.',[],422);

if($duree!==null && ($duree<1 || $duree>20))
    jsonResponse(
        false,
        'La durée doit être comprise entre 1 et 20 ans.',
        [],
        422
    );

if($hasParent && !in_array($mode,['DEPARTEMENT','UNITE'],true))
    jsonResponse(false,'Type de parent invalide.',[],422);

if($hasParent && !$parentId)
    jsonResponse(false,'Choisissez la structure parente.',[],422);

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
        jsonResponse(
            false,
            'Département invalide ou inactif.',
            [],
            422
        );

    $departementId=(int)$dep['id'];

    /*
     * Le parent supérieur est déduit automatiquement.
     */
    $faculteId=
        (int)($dep['faculte_id']??0)
        ?:null;
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
      AND LOWER(TRIM(nom))=LOWER(TRIM(?))
      AND departement_id <=> ?
      AND faculte_id <=> ?
      AND validation_statut<>'REFUSE'
    LIMIT 1
");

$s->execute([
    $eid,
    $nom,
    $departementId,
    $faculteId
]);

if($s->fetchColumn())
    jsonResponse(
        false,
        'Une filière / un programme portant ce nom existe déjà dans ce rattachement.',
        [],
        409
    );

try{

    $pdo->beginTransaction();

    /*
     * Nouvelle règle :
     * l'établissement construit sa structure locale.
     * L'ajout devient immédiatement actif.
     */
    $pdo->prepare("
        INSERT INTO filieres(
            etablissement_id,
            faculte_id,
            departement_id,
            source_template_program_id,
            ajoute_localement,
            validation_statut,
            curriculum_reference_id,
            preparatory_level_enabled,
            code,
            nom,
            duree_annees,
            description,
            motif_ajout,
            created_by_user_id,
            actif
        )
        VALUES(
            ?,?,?,
            NULL,
            1,
            'VALIDE_LOCAL',
            ?,?,
            NULL,
            ?,?,?,
            NULL,
            ?,
            1
        )
    ")->execute([
        $eid,
        $faculteId,
        $departementId,
        $curriculum,
        $prep,
        $nom,
        $duree,
        $description!==''?$description:null,
        (int)($_SESSION['user_id']??0)
    ]);

    $id=(int)$pdo->lastInsertId();

    $code='FIL-'.
        str_pad(
            (string)$id,
            4,
            '0',
            STR_PAD_LEFT
        );

    $pdo->prepare("
        UPDATE filieres
        SET code=?
        WHERE id=?
          AND etablissement_id=?
    ")->execute([
        $code,
        $id,
        $eid
    ]);

    $pdo->commit();

    jsonResponse(
        true,
        "Filière / programme $code ajouté et activé.",
        [
            'id'=>$id,
            'code'=>$code
        ]
    );

}catch(Throwable $e){

    if($pdo->inTransaction())
        $pdo->rollBack();

    jsonResponse(
        false,
        'Erreur : '.$e->getMessage(),
        [],
        500
    );
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$settings=$_SESSION['academic_settings']??[];

if(!$eid)
    jsonResponse(false,'Aucun établissement actif.',[],403);

if(empty($settings['departement_active']))
    jsonResponse(false,'Les départements sont désactivés pour cet établissement.',[],403);

$nom=trim((string)($_POST['nom']??''));
$specialisation=trim((string)($_POST['specialisation']??''));
$hasParent=(int)($_POST['has_parent']??0)===1;
$faculteId=$hasParent
    ?((int)($_POST['faculte_id']??0)?:null)
    :null;

if($nom==='')
    jsonResponse(false,'Le nom du département est obligatoire.',[],422);

if(mb_strlen($nom)>150)
    jsonResponse(false,'Le nom est limité à 150 caractères.',[],422);

if(mb_strlen($specialisation)>180)
    jsonResponse(false,'La spécialisation est limitée à 180 caractères.',[],422);

if($hasParent&&!$faculteId)
    jsonResponse(false,'Choisissez la structure parente.',[],422);

if($faculteId){
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
        $faculteId,
        $eid
    ]);

    if(!$s->fetchColumn())
        jsonResponse(
            false,
            'Structure parente invalide ou inactive.',
            [],
            422
        );
}

$s=$pdo->prepare("
    SELECT id
    FROM departements
    WHERE etablissement_id=?
      AND LOWER(TRIM(nom))=LOWER(TRIM(?))
      AND validation_statut<>'REFUSE'
    LIMIT 1
");

$s->execute([
    $eid,
    $nom
]);

if($s->fetchColumn())
    jsonResponse(
        false,
        'Ce département existe déjà dans cet établissement.',
        [],
        409
    );

try{
    $pdo->beginTransaction();

    /*
     * Nouvelle règle STAGIA :
     * la structure locale réelle appartient à l'établissement.
     * Elle est immédiatement active et validée localement.
     */
    $pdo->prepare("
        INSERT INTO departements(
            etablissement_id,
            faculte_id,
            source_template_department_id,
            ajoute_localement,
            validation_statut,
            code,
            nom,
            specialisation,
            motif_ajout,
            created_by_user_id,
            actif
        )
        VALUES(
            ?,?,
            NULL,
            1,
            'VALIDE_LOCAL',
            NULL,
            ?,?,
            NULL,
            ?,
            1
        )
    ")->execute([
        $eid,
        $faculteId,
        $nom,
        $specialisation!==''?$specialisation:null,
        (int)($_SESSION['user_id']??0)
    ]);

    $id=(int)$pdo->lastInsertId();

    $code='DEP-'.
        str_pad(
            (string)$id,
            4,
            '0',
            STR_PAD_LEFT
        );

    $pdo->prepare("
        UPDATE departements
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
        "Département $code ajouté et activé.",
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

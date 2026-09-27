<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'academic.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$settings=$_SESSION['academic_settings']??[];

$id=(int)($_POST['id']??0);
$nom=trim((string)($_POST['nom']??''));
$specialisation=trim((string)($_POST['specialisation']??''));

$hasParent=(int)($_POST['has_parent']??0)===1;

$faculteId=$hasParent
    ?((int)($_POST['faculte_id']??0)?:null)
    :null;

if(!$eid)
    jsonResponse(false,'Aucun établissement actif.',[],403);

if(empty($settings['departement_active']))
    jsonResponse(false,'Les départements sont désactivés pour cet établissement.',[],403);

if(!$id)
    jsonResponse(false,'Département invalide.',[],422);

if($nom==='')
    jsonResponse(false,'Le nom du département est obligatoire.',[],422);

if(mb_strlen($nom)>150)
    jsonResponse(false,'Le nom est limité à 150 caractères.',[],422);

if(mb_strlen($specialisation)>180)
    jsonResponse(false,'La spécialisation est limitée à 180 caractères.',[],422);

if($hasParent&&!$faculteId)
    jsonResponse(false,'Choisissez la structure parente.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT
            id,
            ajoute_localement,
            validation_statut
        FROM departements
        WHERE id=?
          AND etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");

    $s->execute([
        $id,
        $eid
    ]);

    $current=$s->fetch(PDO::FETCH_ASSOC);

    if(!$current)
        throw new RuntimeException(
            'Département introuvable.'
        );

    /*
     * Les éléments du référentiel national restent protégés.
     */
    if((int)$current['ajoute_localement']!==1)
        throw new RuntimeException(
            'Un département national ne peut pas être modifié localement.'
        );

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
            throw new RuntimeException(
                'Structure parente invalide ou inactive.'
            );
    }

    $s=$pdo->prepare("
        SELECT id
        FROM departements
        WHERE etablissement_id=?
          AND id<>?
          AND LOWER(TRIM(nom))=LOWER(TRIM(?))
          AND validation_statut<>'REFUSE'
        LIMIT 1
    ");

    $s->execute([
        $eid,
        $id,
        $nom
    ]);

    if($s->fetchColumn())
        throw new RuntimeException(
            'Un autre département porte déjà ce nom.'
        );

    $pdo->prepare("
        UPDATE departements
        SET
            faculte_id=?,
            nom=?,
            specialisation=?,
            validation_statut='VALIDE_LOCAL',
            actif=1,
            review_comment=NULL
        WHERE id=?
          AND etablissement_id=?
          AND ajoute_localement=1
    ")->execute([
        $faculteId,
        $nom,
        $specialisation!==''?$specialisation:null,
        $id,
        $eid
    ]);

    $pdo->commit();

    jsonResponse(
        true,
        'Département modifié avec succès.'
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

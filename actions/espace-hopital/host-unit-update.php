<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'host.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);

$type=strtoupper(trim((string)($_POST['type']??'SERVICE')));
$nom=trim((string)($_POST['nom']??''));
$parentId=(int)($_POST['parent_id']??0)?:null;
$description=trim((string)($_POST['description']??''));
$motif=trim((string)($_POST['motif_ajout']??''));
$capacite=(int)($_POST['capacite']??0)?:null;

$allowed=[
    'DEPARTEMENT','SERVICE','UNITE','LABORATOIRE','PROJET',
    'CHANTIER','ATELIER','PARCELLE','EXPLOITATION','AUTRE'
];

if(!$eid||!$id||$nom===''||!in_array($type,$allowed,true))
    jsonResponse(false,'Données invalides.',[],422);

$s=$pdo->prepare("
    SELECT ajoute_localement,validation_statut
    FROM host_units
    WHERE id=? AND host_etablissement_id=?
    LIMIT 1
");
$s->execute([$id,$eid]);
$row=$s->fetch(PDO::FETCH_ASSOC);

if(!$row)jsonResponse(false,'Structure introuvable.',[],404);
if(!(int)$row['ajoute_localement'])
    jsonResponse(false,'Une structure nationale STAGIA ne peut pas être modifiée localement.',[],403);
if(!in_array($row['validation_statut'],['EN_ATTENTE','VALIDE_LOCAL'],true))
    jsonResponse(false,"Cette structure n'est plus modifiable localement.",[],409);

if($parentId){
    if($parentId===$id)
        jsonResponse(false,'Une structure ne peut pas être son propre parent.',[],422);

    $s=$pdo->prepare("
        SELECT id
        FROM host_units
        WHERE id=?
          AND host_etablissement_id=?
          AND actif=1
          AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL')
        LIMIT 1
    ");
    $s->execute([$parentId,$eid]);

    if(!$s->fetchColumn())
        jsonResponse(false,'Parent invalide.',[],422);
}

$pdo->prepare("
    UPDATE host_units
    SET
        parent_id=?,
        nom=?,
        type=?,
        description=?,
        motif_ajout=?,
        capacite=?,
        validation_statut='EN_ATTENTE',
        actif=0,
        reviewed_by_user_id=NULL,
        reviewed_at=NULL,
        review_comment=NULL
    WHERE id=? AND host_etablissement_id=?
")->execute([
    $parentId,
    $nom,
    $type,
    $description?:null,
    $motif?:null,
    $capacite,
    $id,
    $eid
]);

jsonResponse(true,'Ajout local modifié et renvoyé en validation au Super Admin.');

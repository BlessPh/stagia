<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

requirePermission($pdo,'host.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);

if(!$eid)jsonResponse(false,"Aucun établissement d'accueil actif.",[],403);
if(!contextHostEnabled())
    jsonResponse(false,"Le module Structure d'accueil n'est pas activé.",[],403);

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

$prefixes=[
    'DEPARTEMENT'=>'DEP','SERVICE'=>'SRV','UNITE'=>'UNI',
    'LABORATOIRE'=>'LAB','PROJET'=>'PRJ','CHANTIER'=>'CHN',
    'ATELIER'=>'ATL','PARCELLE'=>'PAR','EXPLOITATION'=>'EXP','AUTRE'=>'AUT'
];

if($nom==='' || !in_array($type,$allowed,true))
    jsonResponse(false,'Type et nom obligatoires.',[],422);

if($motif==='')
    jsonResponse(false,"Expliquez pourquoi cette structure manque au référentiel STAGIA.",[],422);

if($capacite!==null && $capacite<1)
    jsonResponse(false,'La capacité doit être supérieure à zéro.',[],422);

if($parentId){
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
        jsonResponse(false,'Structure parente invalide ou non validée.',[],422);
}

$s=$pdo->prepare("
    SELECT id
    FROM host_units
    WHERE host_etablissement_id=?
      AND LOWER(TRIM(nom))=LOWER(TRIM(?))
      AND parent_id <=> ?
      AND validation_statut<>'REFUSE'
    LIMIT 1
");
$s->execute([$eid,$nom,$parentId]);

if($s->fetchColumn())
    jsonResponse(false,'Une structure portant ce nom existe déjà à ce niveau.',[],409);

try{
    $pdo->beginTransaction();

    /*
     * Le champ host_units.code est NOT NULL.
     * Il faut donc générer le code AVANT l'INSERT et non après.
     *
     * Le verrou sur les codes du même établissement/type réduit le risque
     * que deux créations concurrentes choisissent le même numéro.
     */
    $prefix=$prefixes[$type].'-LOC-';

    $s=$pdo->prepare("
        SELECT code
        FROM host_units
        WHERE host_etablissement_id=?
          AND code LIKE ?
        ORDER BY code
        FOR UPDATE
    ");
    $s->execute([$eid,$prefix.'%']);
    $used=array_flip($s->fetchAll(PDO::FETCH_COLUMN));

    $code=null;

    for($n=1;$n<=999999;$n++){
        $candidate=$prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT);

        if(!isset($used[$candidate])){
            $code=$candidate;
            break;
        }
    }

    if($code===null)
        throw new RuntimeException("Impossible de générer un code pour cette structure.");

    $pdo->prepare("
        INSERT INTO host_units(
            host_etablissement_id,
            parent_id,
            source_template_host_unit_id,
            ajoute_localement,
            validation_statut,
            code,
            nom,
            type,
            description,
            motif_ajout,
            capacite,
            created_by_user_id,
            actif
        ) VALUES(?,?,NULL,1,'EN_ATTENTE',?,?,?,?,?,?,?,0)
    ")->execute([
        $eid,
        $parentId,
        $code,
        $nom,
        $type,
        $description?:null,
        $motif,
        $capacite,
        (int)($_SESSION['user_id']??0)
    ]);

    $id=(int)$pdo->lastInsertId();

    $pdo->commit();

    jsonResponse(
        true,
        "$type $code proposé localement. Il reste inactif jusqu'à validation du Super Admin.",
        ['id'=>$id,'code'=>$code]
    );
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$contexte=exigerAdministrationRolesAjax($pdo);verifyAjaxCsrf();

$nom=trim($_POST['nom']??'');
$description=trim($_POST['description']??'');

if($nom==='')jsonResponse(false,'Le nom du rôle est obligatoire.',[],422);
if(mb_strlen($nom)>100)jsonResponse(false,'Le nom du rôle est trop long.',[],422);

function roleCode(PDO $pdo,string $nom,?int $etablissementId):string{
    $base=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nom)?:$nom;
    $base=strtoupper(preg_replace('/[^A-Z0-9]+/i','_',trim($base)));
    $base=trim($base,'_');
    if($base==='')$base='ROLE';
    if(!str_starts_with($base,'ROLE_'))$base='ROLE_'.$base;
    if($etablissementId)$base='ETAB_'.$etablissementId.'_'.$base;
    $base=substr($base,0,45);
    $code=$base;$n=2;
    $s=$pdo->prepare("SELECT 1 FROM roles WHERE code=? LIMIT 1");
    while(true){
        $s->execute([$code]);
        if(!$s->fetchColumn())return $code;
        $suffix='_'.$n++;
        $code=substr($base,0,50-strlen($suffix)).$suffix;
    }
}

try{
    $etablissementId=$contexte['super']?null:(int)$contexte['etablissement_id'];
    $s=$pdo->prepare("SELECT 1 FROM roles WHERE LOWER(TRIM(nom))=LOWER(TRIM(?)) AND ".($contexte['super']?"systeme=1":"systeme=0 AND etablissement_id=?")." LIMIT 1");
    $s->execute($contexte['super']?[$nom]:[$nom,$etablissementId]);
    if($s->fetchColumn())jsonResponse(false,'Un rôle portant ce nom existe déjà.',[],409);

    $code=roleCode($pdo,$nom,$etablissementId);
    $pdo->prepare("INSERT INTO roles(code,nom,description,actif,systeme,etablissement_id,cree_par_user_id) VALUES(?,?,?,1,?,?,?)")
        ->execute([$code,$nom,$description?:null,$contexte['super']?1:0,$etablissementId,currentUserId()]);

    jsonResponse(true,'Rôle créé. Son code STAGIA est '.$code.'.',['id'=>(int)$pdo->lastInsertId(),'code'=>$code]);
}catch(Throwable $e){
    error_log('[ADMIN ROLE STORE] '.$e->getMessage());
    jsonResponse(false,'Impossible de créer le rôle. Consultez le journal serveur.',[],500);
}

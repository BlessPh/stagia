<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);verifyAjaxCsrf();

$id=(int)($_POST['id']??0);
$nom=trim($_POST['nom']??'');
$module=strtoupper(trim($_POST['module']??''));
$description=trim($_POST['description']??'');

if(!$id||$nom===''||$module==='')
    jsonResponse(false,'Permission, nom et module sont obligatoires.',[],422);

if(mb_strlen($nom)>150||mb_strlen($module)>60||mb_strlen($description)>255)
    jsonResponse(false,'Une valeur dépasse la longueur autorisée.',[],422);

if(!preg_match('/^[A-Z0-9_ -]+$/u',$module))
    jsonResponse(false,'Le module contient des caractères non autorisés.',[],422);

$s=$pdo->prepare("SELECT id,code FROM permissions WHERE id=? LIMIT 1");
$s->execute([$id]);$p=$s->fetch(PDO::FETCH_ASSOC);
if(!$p)jsonResponse(false,'Permission introuvable.',[],404);

$pdo->prepare("UPDATE permissions SET nom=?,module=?,description=? WHERE id=?")
    ->execute([$nom,$module,$description?:null,$id]);

jsonResponse(true,'Permission mise à jour. Le code technique '.$p['code'].' reste inchangé.');

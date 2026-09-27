<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/admin-scope.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/geographie.php';
header('Cache-Control: no-store');
if(!estSuperAdminPrincipal())jsonResponse(false,'Accès réservé au super administrateur principal.',[],403);
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $parentId=geoEntier($_GET['parent_id']??null,true);
        $chemin=$parentId===null?[]:geoChemin($pdo,$parentId,false);
        $parent=$chemin===[]?null:$chemin[count($chemin)-1];
        $s=$pdo->query('SELECT j.id,j.action,j.cree_le,u.nom AS localite,a.nom AS acteur FROM geographie_journal j JOIN geographie_unites u ON u.id=j.unite_id LEFT JOIN users a ON a.id=j.acteur_id ORDER BY j.id DESC LIMIT 20');
        jsonResponse(true,'',['items'=>geoEnfants($pdo,$parentId,false),'chemin'=>$chemin,'types'=>geoTypesEnfants($parent),
            'journal'=>$s->fetchAll(PDO::FETCH_ASSOC),'a_completer'=>(int)$pdo->query('SELECT COUNT(*) FROM geographie_localisations WHERE a_completer=1')->fetchColumn()]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(false,'Méthode non autorisée.',[],405);
    verifyAjaxCsrf();
    foreach(['id','parent_id','nom','type','source','actif','version'] as $key)
        if(isset($_POST[$key])&&!is_string($_POST[$key]))throw new InvalidArgumentException('Données invalides.');
    $id=geoSauverUnite($pdo,$_POST,currentUserId());
    jsonResponse(true,'Localité enregistrée.',['id'=>$id]);
}catch(InvalidArgumentException $e){jsonResponse(false,$e->getMessage(),[],422);}
catch(PDOException $e){
    error_log('[GEOGRAPHIE GESTION] '.$e->getMessage());
    jsonResponse(false,($e->errorInfo[1]??0)===1062?'Cette localité existe déjà sous ce parent.':'Enregistrement impossible. Vérifiez les migrations et consultez les journaux.',[],409);
}catch(Throwable $e){error_log('[GEOGRAPHIE GESTION] '.$e->getMessage());jsonResponse(false,'Référentiel indisponible. Vérifiez son installation.',[],503);}

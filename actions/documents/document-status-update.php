<?php
ini_set('display_errors','0');
if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
ob_start();

try{
    require_once __DIR__.'/../../config/config.php';
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/ajax.php';
    require_once __DIR__.'/../../includes/permissions.php';
    require_once __DIR__.'/../../includes/document-archive-service.php';

    $roles=['SUPER_ADMIN','ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES'];
    if(function_exists('requireAjaxRole'))requireAjaxRole($roles);else requireRole($roles);

    if(empty($_POST['csrf'])||empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],(string)$_POST['csrf']))
        throw new RuntimeException('Session expirée.');

    $roleUpper=strtoupper(trim((string)($_SESSION['role_code']??'')));
    if(!in_array($roleUpper,['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL'],true)){
        http_response_code(403);
        throw new RuntimeException('Action réservée à l’administration.');
    }

    $key=trim((string)($_POST['key']??''));
    $action=trim((string)($_POST['action']??''));
    if(!preg_match('/^(CERT|ARCH|CONV)-(\d+)$/',$key,$m))throw new RuntimeException('Document invalide.');
    if(!in_array($action,['cancel','archive','reactivate'],true))throw new RuntimeException('Action invalide.');

    $kind=$m[1];
    $id=(int)$m[2];
    if($id<=0)throw new RuntimeException('Document invalide.');

    $eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));
    $uid=(int)($_SESSION['user_id']??0);
    $items=stagiaDocArchiveList($pdo,$eid,$uid,$roleUpper);
    $found=false;
    foreach($items as $it){
        if(($it['key']??'')===$key){$found=true;break;}
    }
    if(!$found){
        http_response_code(403);
        throw new RuntimeException('Document introuvable dans votre périmètre.');
    }

    $table=match($kind){
        'CERT'=>'stage_certificates',
        'ARCH'=>'stage_document_archives',
        'CONV'=>'stage_conventions',
        default=>''
    };
    if($table===''||!stagiaArchiveTableExists($pdo,$table))throw new RuntimeException('Table document introuvable.');

    $statusCol=null;
    foreach(['statut','status'] as $c){
        if(stagiaArchiveHas($pdo,$table,$c)){$statusCol=$c;break;}
    }
    if(!$statusCol)throw new RuntimeException('Colonne statut introuvable pour ce document.');

    $target=match($action){
        'cancel'=>'ANNULEE',
        'archive'=>'ARCHIVEE',
        'reactivate'=>($kind==='ARCH'?'GENERE':'SIGNEE')
    };

    $sets=['`'.$statusCol.'`=?'];
    $params=[$target];
    if(stagiaArchiveHas($pdo,$table,'updated_at'))$sets[]='updated_at=NOW()';
    if($action==='cancel'&&stagiaArchiveHas($pdo,$table,'cancelled_at'))$sets[]='cancelled_at=NOW()';
    if($action==='cancel'&&stagiaArchiveHas($pdo,$table,'cancelled_by')){$sets[]='cancelled_by=?';$params[]=$uid?:null;}
    if($action==='reactivate'&&stagiaArchiveHas($pdo,$table,'cancelled_at'))$sets[]='cancelled_at=NULL';
    if($action==='reactivate'&&stagiaArchiveHas($pdo,$table,'cancelled_by'))$sets[]='cancelled_by=NULL';

    $params[]=$id;
    $s=$pdo->prepare('UPDATE `'.$table.'` SET '.implode(',',$sets).' WHERE id=? LIMIT 1');
    $s->execute($params);

    $message=match($action){
        'cancel'=>'Document annulé. Le QR indiquera que le document n’est plus valide.',
        'archive'=>'Document archivé.',
        'reactivate'=>'Document réactivé.'
    };

    ob_clean();
    echo json_encode(['success'=>true,'message'=>$message,'data'=>['status'=>$target]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $debug=ob_get_clean();
    if(http_response_code()<400)http_response_code(500);
    echo json_encode([
        'success'=>false,
        'message'=>$e->getMessage(),
        'data'=>['debug'=>trim(strip_tags($debug))]
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

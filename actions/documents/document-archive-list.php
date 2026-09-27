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

    $roles=[
        'SUPER_ADMIN','ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES'
    ];
    if(function_exists('requireAjaxRole'))requireAjaxRole($roles);else requireRole($roles);

    $eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));
    $uid=(int)($_SESSION['user_id']??0);
    $roleUpper=strtoupper(trim((string)($_SESSION['role_code']??'')));

    $items=stagiaDocArchiveList($pdo,$eid,$uid,$roleUpper);
    $type=trim((string)($_GET['type']??''));
    if($type!==''){
        $items=array_values(array_filter($items,function($x)use($type){
            return ($x['type']??'')===$type;
        }));
    }

    $kpi=['total'=>count($items),'valides'=>0,'archives'=>0,'annules'=>0,'attestations'=>0,'conventions'=>0,'modeles'=>0];
    foreach($items as $x){
        $t=(string)($x['type']??'');
        $st=(string)($x['status']??'');
        if($st==='ANNULÉ')$kpi['annules']++;
        elseif($st==='ARCHIVÉ')$kpi['archives']++;
        else $kpi['valides']++;
        if($t==='ATTESTATION_STAGE')$kpi['attestations']++;
        elseif($t==='CONVENTION_STAGE')$kpi['conventions']++;
        else $kpi['modeles']++;
    }

    ob_clean();
    echo json_encode(['success'=>true,'message'=>'','data'=>['items'=>$items,'kpi'=>$kpi]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    $debug=ob_get_clean();
    http_response_code(500);
    echo json_encode([
        'success'=>false,
        'message'=>'Erreur archives documents : '.$e->getMessage(),
        'data'=>['debug'=>trim(strip_tags($debug))]
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/document-archive-service.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','ENCADREUR','EVALUATEUR_CLINIQUE','STAGIAIRE']);
try{
 if(($_POST['csrf']??'')!==($_SESSION['csrf']??''))jsonResponse(false,'Session expirée.',[],419);
 $type=strtoupper(trim((string)($_POST['type']??'')));$ctx=(int)($_POST['context_id']??($_POST['assignment_id']??0));
 if(!in_array($type,['PRESENCE','APPRECIATION','RECOMMANDATION','RAPPORT_FINAL'],true))jsonResponse(false,'Type de document invalide.',[],422);
 $eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));$uid=(int)($_SESSION['user_id']??0);
 $url='/views/documents/print-stage-document.php?type='.rawurlencode(strtolower($type)).($ctx?'&context_id='.$ctx.'&assignment_id='.$ctx:'');
 stgDocArcRegister($pdo,['type_document'=>$type,'titre'=>stgDocArcTypeLabel($type),'reference'=>'DOC-'.date('Ymd').'-'.$ctx.'-'.$type,'etablissement_id'=>$eid?:null,'assignment_id'=>$ctx?:null,'source'=>'PRINTABLE_MODEL','source_token'=>$type.':'.$ctx,'file_url'=>defined('BASE_URL')?BASE_URL.$url:$url,'generated_by'=>$uid?:null,'metadata'=>['requested_from'=>'modeles-stage','role'=>$_SESSION['role_code']??'']]);
 jsonResponse(true,'Document enregistré dans les archives.');
}catch(Throwable $e){jsonResponse(false,'Erreur archivage document : '.$e->getMessage(),[],500);}

<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/document-archive-service.php';

requireAjaxRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE','CHEF_SERVICE','ENCADREUR','EVALUATEUR_CLINIQUE','STAGIAIRE']);
try{
 $role=(string)($_SESSION['role_code']??'');$uid=(int)($_SESSION['user_id']??0);$eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));
 $items=stgDocArcFetch($pdo,$eid,$uid,$role,$_GET);
 $k=['total'=>count($items),'attestations'=>0,'conventions'=>0,'modeles'=>0,'certificats'=>0];
 foreach($items as $x){$t=strtoupper((string)($x['type_document']??''));if($t==='ATTESTATION_STAGE')$k['attestations']++;elseif($t==='CONVENTION_STAGE')$k['conventions']++;elseif($t==='CERTIFICAT_STAGE')$k['certificats']++;elseif(in_array($t,['PRESENCE','APPRECIATION','RECOMMANDATION','RAPPORT_FINAL'],true))$k['modeles']++;}
 jsonResponse(true,'',['items'=>$items,'kpi'=>$k]);
}catch(Throwable $e){jsonResponse(false,'Erreur archives documents : '.$e->getMessage(),[],500);}

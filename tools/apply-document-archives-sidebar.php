<?php
header('Content-Type:text/plain; charset=utf-8');
$file=__DIR__.'/../includes/app-header.php';
if(!is_file($file)){http_response_code(404);exit("app-header.php introuvable\n");}
$s=file_get_contents($file);
if(strpos($s,'stage-documents-archives')!==false){exit("Déjà appliqué.\n");}
$archivesItem="\n\n        $"."organisationStageItems[]=[\n            BASE_URL.'/views/documents/archives.php',\n            'bi-folder2-open',\n            'Archives documents',\n            'stage-documents-archives'\n        ];";
$s=preg_replace("/('stage-documents-models'\s*,?)/","$1\n    'stage-documents-archives',",$s,1);
$re="/(\$organisationStageItems\[\]=\[\s*BASE_URL\.'\/views\/documents\/modeles-stage\.php'.*?'stage-documents-models'\s*\];)/s";
$n=0;$s2=preg_replace($re,"$1".$archivesItem,$s,2,$n);
if(!$n){
 $re2="/(\$organisationStageItems\[\]=\[\s*BASE_URL\.'\/views\/stages\/conventions\.php'.*?'stage-conventions'\s*\];)/s";
 $s2=preg_replace($re2,"$1".$archivesItem,$s,2,$n);
}
if(!$n){http_response_code(500);exit("Insertion impossible : ajoutez manuellement le lien Archives documents.\n");}
copy($file,$file.'.bak_'.date('Ymd_His'));
file_put_contents($file,$s2);
echo "OK - Sidebar mise à jour : Archives documents ajouté.\n";

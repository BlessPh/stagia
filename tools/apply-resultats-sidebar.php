<?php
/* STAGIA-RDC — Ajout automatique des liens Résultats dans includes/app-header.php */
$root=dirname(__DIR__);
$file=$root.'/includes/app-header.php';
if(!is_file($file)){http_response_code(404);exit("includes/app-header.php introuvable\n");}
$src=file_get_contents($file);$old=$src;$changed=[];
function add_once(&$src,$name,$fn,&$changed){$before=$src;$fn($src);if($src!==$before)$changed[]=$name;}

add_once($src,'page active hôpital hospital-results',function(&$s){
    if(strpos($s,"'hospital-results'")!==false)return;
    $s=preg_replace("/(\$hostSuiviPages\s*=\s*\[[\s\S]*?'hospital-completions'\s*)(,?)(\s*\];)/","$1,\n    'hospital-results'$3",$s,1);
},$changed);

add_once($src,'page active université university-results',function(&$s){
    if(strpos($s,"'university-results'")!==false)return;
    $s=preg_replace("/(\$stagePages\s*=\s*\[[\s\S]*?'admin-stage-supervision'\s*)(,?)(\s*\];)/","$1,\n    'university-results'$3",$s,1);
},$changed);

add_once($src,'menu hôpital Résultats de stage',function(&$s){
    if(strpos($s,"/views/espace-hopital/resultats-stage.php")!==false)return;
    $insert="\n    if((\$hAdmin||\$hCoord||\$hAuthority)&&contextPermission(['stage.manage','evaluation.view','evaluation.manage']))\n        \$suiviItems[]=[BASE_URL.'/views/espace-hopital/resultats-stage.php','bi-award','Résultats de stage','hospital-results'];\n";
    $needle="    if((\$hAdmin||\$hCoord)&&contextPermission('stage.manage'))\n        \$suiviItems[]=[BASE_URL.'/views/espace-hopital/clotures.php','bi-check2-square','Clôture des stages','hospital-completions'];";
    if(strpos($s,$needle)!==false){$s=str_replace($needle,$insert."\n".$needle,$s);return;}
    $pattern="/(\$suiviItems\[\]=\[\s*BASE_URL\.'\/views\/espace-hopital\/evaluations\.php',[\s\S]*?'hopital-evaluations'\s*\];)/";
    $s=preg_replace($pattern,"$1\n".$insert,$s,1);
},$changed);

add_once($src,'menu université Résultats reçus',function(&$s){
    if(strpos($s,"/views/espace-etablissement/resultats-stage.php")!==false)return;
    $insert="\n    if(contextPermission(['stage.view','stage.manage','placement.university.view']))\n        \$operationStageItems[]=[\n            BASE_URL.'/views/espace-etablissement/resultats-stage.php',\n            'bi-inbox',\n            'Résultats reçus',\n            'university-results'\n        ];\n";
    $pattern="/(\$operationStageItems\[\]=\[\s*BASE_URL\.'\/views\/stages\/candidatures\.php',[\s\S]*?'stages-candidatures'(?:\s*,\s*\$academicNewApplications)?\s*\];)/";
    $new=preg_replace($pattern,"$1\n".$insert,$s,1,$n);
    if($n){$s=$new;return;}
    /* Fallback : juste avant le rendu du bloc STAGES académique */
    $needle="    /* ----- Rendu du bloc STAGES ----- */";
    $pos=strpos($s,$needle);
    if($pos!==false)$s=substr($s,0,$pos).$insert."\n".substr($s,$pos);
},$changed);

if($src===$old){echo "Sidebar déjà à jour.\n";exit;}
$backup=$file.'.bak-resultats-'.date('Ymd-His');
if(!copy($file,$backup))exit("Impossible de créer la sauvegarde.\n");
file_put_contents($file,$src);
echo "Sidebar mise à jour avec succès.\n";
echo "Sauvegarde : ".$backup."\n";
echo "Modifications :\n- ".implode("\n- ",$changed)."\n";

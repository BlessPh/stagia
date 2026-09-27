<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$cible=realpath($argv[1]??'');$mode=$argv[2]??'--verifier';
if(!$cible||!in_array($mode,['--verifier','--installer'],true))throw new RuntimeException('Usage : php installer-geographie-menu.php /projet --verifier|--installer');
$path=$cible.'/includes/app-header.php';$old=file_get_contents($path);
if($old===false)throw new RuntimeException('En-tête introuvable.');
if(str_contains($old,"/views/geographie/index.php")){echo "Menu géographique déjà présent.\n";exit;}
if(substr_count($old,'</nav>')!==1)throw new RuntimeException('Structure du menu différente : intégration manuelle nécessaire.');
$block=<<<'MENU'
<?php
// STAGIA géographie : seul le super administrateur principal gère le référentiel.
if(hasRole('SUPER_ADMIN')&&($_SESSION['role_code']??'')==='SUPER_ADMIN'){
    stagiaNav(BASE_URL.'/views/geographie/index.php','bi-geo-alt','Référentiel géographique','geographie',$activePage);
}
?>
MENU;
if($mode==='--verifier'){echo "Point d’intégration du menu vérifié.\n";exit;}
$backup=dirname($cible).'/sauvegarde_menu_geographie_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.php';
if(!copy($path,$backup))throw new RuntimeException('Sauvegarde du menu impossible.');
if(file_put_contents($path,str_replace('</nav>',$block."\n</nav>",$old),LOCK_EX)===false)throw new RuntimeException('Écriture du menu impossible.');
echo "Lien géographique ajouté. Sauvegarde : $backup\n";

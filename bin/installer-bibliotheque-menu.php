<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$path=__DIR__.'/../includes/app-header.php';
$old=file_get_contents($path);
if($old===false)throw new RuntimeException('En-tête introuvable.');
if(str_contains($old,"/views/bibliotheque/index.php")){
    echo "Lien Bibliothèque déjà présent.\n";exit;
}
$anchor="</nav>";
if(substr_count($old,$anchor)!==1)throw new RuntimeException('Structure du menu différente. Intégration manuelle nécessaire.');
$block=<<<'MENU'
<?php
// STAGIA Bibliothèque : accès à tous les comptes authentifiés.
stagiaNav(
    BASE_URL.'/views/bibliotheque/index.php',
    'bi-book',
    'Bibliothèque numérique',
    'bibliotheque',
    $activePage
);
?>
MENU;
$backup=dirname(__DIR__,2).'/sauvegarde_menu_bibliotheque_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.php';
if(!copy($path,$backup))throw new RuntimeException('Sauvegarde du menu impossible.');
if(file_put_contents($path,str_replace($anchor,$block."\n".$anchor,$old),LOCK_EX)===false)
    throw new RuntimeException('Écriture du menu impossible. Sauvegarde : '.$backup);
echo "Menu ajouté. Sauvegarde : $backup\n";

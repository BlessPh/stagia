<?php
/* STAGIA-RDC — installateur local des niveaux académiques.
   À lancer UNE FOIS depuis http://localhost/stagia/tools/install-academic-levels.php */
$ip=$_SERVER['REMOTE_ADDR']??'';
if(PHP_SAPI!=='cli' && !in_array($ip,['127.0.0.1','::1'],true)){http_response_code(403);exit('Installation autorisée uniquement en local.');}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';
requireRole(['ADMIN_ETABLISSEMENT']);

$messages=[];$errors=[];
try{
    $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $columnExists=function(string $name)use($pdo,$db):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='academic_levels' AND COLUMN_NAME=?");$s->execute([$db,$name]);return (int)$s->fetchColumn()>0;};
    if(!$columnExists('owner_etablissement_id')){$pdo->exec("ALTER TABLE academic_levels ADD COLUMN owner_etablissement_id INT NULL AFTER academic_cycle_id");$messages[]='Colonne owner_etablissement_id ajoutée.';}else $messages[]='owner_etablissement_id déjà présente.';
    if(!$columnExists('created_by_user_id')){$pdo->exec("ALTER TABLE academic_levels ADD COLUMN created_by_user_id INT NULL AFTER owner_etablissement_id");$messages[]='Colonne created_by_user_id ajoutée.';}else $messages[]='created_by_user_id déjà présente.';
    $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='academic_levels' AND INDEX_NAME='idx_academic_levels_owner'");$s->execute([$db]);if(!(int)$s->fetchColumn()){$pdo->exec("CREATE INDEX idx_academic_levels_owner ON academic_levels(owner_etablissement_id)");$messages[]='Index propriétaire ajouté.';}else $messages[]='Index propriétaire déjà présent.';

    $file=realpath(__DIR__.'/../includes/app-header.php');
    if(!$file)throw new RuntimeException('includes/app-header.php introuvable.');
    $src=file_get_contents($file);if($src===false)throw new RuntimeException('Impossible de lire app-header.php.');
    $changed=false;
    if(strpos($src,"'academic-levels'")===false){
        $src=preg_replace_callback('/\\$adminPages\\s*=\\s*\\[(.*?)\\];/s',function($m){$body=rtrim($m[1]);return '$adminPages=['.$body.(str_ends_with(trim($body),',')?'':',')."\n    'academic-levels'\n];";},$src,1,$count);
        if(!$count)throw new RuntimeException('Bloc $adminPages non trouvé.');$changed=true;
    }
    if(strpos($src,"/views/administration-etablissement/niveaux-academiques.php")===false){
        $marker="/* ADMINISTRATION LOCALE */\n\n$".'localAdminItems=[];';
        $insert=$marker."\n\nif(\$academic && \$role==='ADMIN_ETABLISSEMENT' && contextPermission('academic.manage'))\n    \$localAdminItems[]=[\n        BASE_URL.'/views/administration-etablissement/niveaux-academiques.php',\n        'bi-layers',\n        'Niveaux académiques',\n        'academic-levels'\n    ];";
        if(strpos($src,$marker)===false)throw new RuntimeException('Bloc ADMINISTRATION LOCALE non trouvé dans app-header.php.');
        $src=str_replace($marker,$insert,$src);$changed=true;
    }
    if($changed){$backup=$file.'.before-academic-levels-'.date('Ymd-His').'.bak';copy($file,$backup);file_put_contents($file,$src);$messages[]='Menu Administration → Niveaux académiques ajouté. Sauvegarde : '.basename($backup);}else $messages[]='Menu déjà installé.';
}catch(Throwable $e){$errors[]=$e->getMessage();}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Installation niveaux académiques</title><style>body{font-family:Arial;background:#f5f7fb;padding:40px}.box{max-width:760px;margin:auto;background:#fff;padding:28px;border-radius:14px;border:1px solid #ddd}li{margin:8px 0}.ok{color:#16794b}.err{color:#b42318}</style></head><body><div class="box"><h2>STAGIA — Niveaux académiques</h2><?php if($errors):?><h3 class="err">Erreur</h3><ul><?php foreach($errors as $x):?><li class="err"><?=htmlspecialchars($x)?></li><?php endforeach?></ul><?php else:?><h3 class="ok">Installation terminée</h3><ul><?php foreach($messages as $x):?><li><?=htmlspecialchars($x)?></li><?php endforeach?></ul><p><a href="<?=defined('BASE_URL')?BASE_URL:''?>/views/administration-etablissement/niveaux-academiques.php">Ouvrir Niveaux académiques</a></p><p><strong>Après le test, supprimez ce fichier tools/install-academic-levels.php.</strong></p><?php endif?></div></body></html>

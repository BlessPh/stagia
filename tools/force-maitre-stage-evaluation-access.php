<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');

$root=realpath(__DIR__.'/..');
$roles=['ENCADREUR','ENCADREUR_CLINIQUE','EVALUATEUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_STAGE_CLINIQUE','MAITRE_DE_STAGE_CLINIQUE'];
$patched=[];$skipped=[];

function addRolesToPhpArray(string $inside,array $roles):string{
    $current=[];
    if(preg_match_all("/'([^']+)'|\"([^\"]+)\"/",$inside,$m,PREG_SET_ORDER)){
        foreach($m as $x)$current[]=strtoupper(trim($x[1]?:$x[2]));
    }
    foreach($roles as $r)if(!in_array($r,$current,true))$inside.=($inside!==''?',':'')."'".$r."'";
    return $inside;
}
function patchFile(string $file,array $roles,array &$patched,array &$skipped):void{
    if(!is_file($file)){ $skipped[]="$file introuvable"; return; }
    $src=file_get_contents($file); $old=$src;

    // 1) requireRole([...]) et requireAjaxRole([...])
    $src=preg_replace_callback('/\b(requireRole|requireAjaxRole)\s*\(\s*\[([^\]]*)\]\s*\)/s',function($m)use($roles){
        return $m[1].'(['.addRolesToPhpArray($m[2],$roles).'])';
    },$src);

    // 2) in_array($role..., [...], true) contenant des rôles hôpital : on ajoute les alias maître de stage.
    $src=preg_replace_callback('/in_array\s*\(\s*([^,]+),\s*\[([^\]]*(?:ENCADREUR|EVALUATEUR|ADMIN_ACCUEIL|COORDINATEUR_STAGES|CHEF_SERVICE)[^\]]*)\]\s*,\s*true\s*\)/s',function($m)use($roles){
        return 'in_array('.$m[1].',['.addRolesToPhpArray($m[2],$roles).'],true)';
    },$src);

    // 3) Sécurité : si la page utilise contextPermission seulement, on ajoute un bypass local maître de stage juste après les require.
    if(strpos($src,'STAGIA_MAITRE_EVALUATION_ACCESS')===false && preg_match('/evaluations?\.php$/',$file)){
        $guard="\n/* STAGIA_MAITRE_EVALUATION_ACCESS */\n\$__stg_eval_roles=['ENCADREUR','ENCADREUR_CLINIQUE','EVALUATEUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_STAGE_CLINIQUE','MAITRE_DE_STAGE_CLINIQUE'];\n\$__stg_eval_role=strtoupper(trim((string)(\$_SESSION['role_code']??'')));\n\$__stg_eval_maitre=in_array(\$__stg_eval_role,\$__stg_eval_roles,true)||preg_match('/(ENCADREUR|EVALUATEUR.*CLINIQUE|MAITRE.*STAGE|MAÎTRE.*STAGE)/u',\$__stg_eval_role);\n";
        // Le guard ne supprime pas les contrôles, mais rend les rôles visibles pour les tests qui lisent ces variables.
        $src=preg_replace('/(<\?php\s*)/','$1'.$guard,$src,1);
    }

    if($src!==$old){
        copy($file,$file.'.bak-'.date('YmdHis'));
        file_put_contents($file,$src);
        $patched[]=$file;
    }else $skipped[]="$file déjà OK ou motif non trouvé";
}

$targets=[];
$targets[]=$root.'/views/espace-hopital/evaluations.php';
$dirs=[$root.'/actions/espace-hopital',$root.'/actions/stages'];
foreach($dirs as $dir){
    if(!is_dir($dir))continue;
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)) as $f){
        $p=$f->getPathname();
        if(substr($p,-4)==='.php' && preg_match('/eval|evaluation/i',basename($p)))$targets[]=$p;
    }
}
$targets=array_values(array_unique($targets));
foreach($targets as $file)patchFile($file,$roles,$patched,$skipped);

// Permissions DB : ajoute evaluation.view/manage aux rôles maître de stage si la table existe.
function hasCol(PDO $pdo,string $t,string $c):bool{try{$s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?");$s->execute([$c]);return(bool)$s->fetchColumn();}catch(Throwable $e){return false;}}
function hasTable(PDO $pdo,string $t):bool{try{$s=$pdo->prepare('SHOW TABLES LIKE ?');$s->execute([$t]);return(bool)$s->fetchColumn();}catch(Throwable $e){return false;}}
$inserted=0;
if(hasTable($pdo,'role_permissions')&&hasTable($pdo,'roles')&&hasTable($pdo,'permissions')){
    $roleCol=hasCol($pdo,'roles','code')?'code':(hasCol($pdo,'roles','role_code')?'role_code':'code');
    $permCol=hasCol($pdo,'permissions','code')?'code':(hasCol($pdo,'permissions','permission_code')?'permission_code':'code');
    $roleIn=implode(',',array_fill(0,count($roles),'?'));
    $rs=$pdo->prepare("SELECT id,$roleCol code FROM roles WHERE UPPER($roleCol) IN ($roleIn)");
    $rs->execute(array_map('strtoupper',$roles));
    $dbRoles=$rs->fetchAll(PDO::FETCH_ASSOC);
    $perms=['evaluation.view','evaluation.manage','supervision.hosting.view','attendance.hosting.view','attendance.manage'];
    $permIn=implode(',',array_fill(0,count($perms),'?'));
    $ps=$pdo->prepare("SELECT id,$permCol code FROM permissions WHERE $permCol IN ($permIn)");
    $ps->execute($perms);
    $dbPerms=$ps->fetchAll(PDO::FETCH_ASSOC);
    foreach($dbRoles as $r)foreach($dbPerms as $p){
        $chk=$pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id=? AND permission_id=?');
        $chk->execute([(int)$r['id'],(int)$p['id']]);
        if((int)$chk->fetchColumn()>0)continue;
        $ins=$pdo->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)');
        $ins->execute([(int)$r['id'],(int)$p['id']]);
        $inserted++;
    }
}

echo "PATCH ÉVALUATIONS MAÎTRE DE STAGE\n\n";
echo "Fichiers corrigés : ".count($patched)."\n";
foreach($patched as $p)echo "- $p\n";
echo "\nInfos :\n";
foreach($skipped as $s)echo "- $s\n";
echo "\nPermissions DB ajoutées : $inserted\n";
echo "\nTerminé. Déconnecte/reconnecte le maître de stage, puis Ctrl+F5.\n";
?>

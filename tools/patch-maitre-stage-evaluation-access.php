<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');

function hasCol(PDO $pdo,string $t,string $c):bool{
    try{$s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?");$s->execute([$c]);return(bool)$s->fetchColumn();}
    catch(Throwable $e){return false;}
}
function tableExists(PDO $pdo,string $t):bool{
    try{$s=$pdo->prepare("SHOW TABLES LIKE ?");$s->execute([$t]);return(bool)$s->fetchColumn();}
    catch(Throwable $e){return false;}
}

$roleCol=hasCol($pdo,'roles','code')?'code':(hasCol($pdo,'roles','role_code')?'role_code':'code');
$permCol=hasCol($pdo,'permissions','code')?'code':(hasCol($pdo,'permissions','permission_code')?'permission_code':'code');

$roleCodes=[
    'ENCADREUR','ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE',
    'EVALUATEUR_CLINIQUE'
];
$permCodes=[
    'evaluation.view','evaluation.manage',
    'supervision.hosting.view',
    'attendance.hosting.view','attendance.manage'
];

echo "PATCH ACCÈS ÉVALUATION — MAÎTRE DE STAGE\n\n";

if(!tableExists($pdo,'role_permissions')){
    echo "Table role_permissions introuvable. Rien à modifier.\n";
    exit;
}

$roleIn=implode(',',array_fill(0,count($roleCodes),'?'));
$permIn=implode(',',array_fill(0,count($permCodes),'?'));

$rs=$pdo->prepare("SELECT id,$roleCol code FROM roles WHERE UPPER($roleCol) IN ($roleIn)");
$rs->execute(array_map('strtoupper',$roleCodes));
$roles=$rs->fetchAll(PDO::FETCH_ASSOC);

$ps=$pdo->prepare("SELECT id,$permCol code FROM permissions WHERE $permCol IN ($permIn)");
$ps->execute($permCodes);
$perms=$ps->fetchAll(PDO::FETCH_ASSOC);

echo "Rôles trouvés : ".count($roles)."\n";
foreach($roles as $r)echo "- {$r['id']} {$r['code']}\n";
echo "\nPermissions trouvées : ".count($perms)."\n";
foreach($perms as $p)echo "- {$p['id']} {$p['code']}\n";

if(!$roles||!$perms){
    echo "\nAucune insertion effectuée.\n";
    exit;
}

$inserted=0;
foreach($roles as $r){
    foreach($perms as $p){
        $chk=$pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id=? AND permission_id=?");
        $chk->execute([(int)$r['id'],(int)$p['id']]);
        if((int)$chk->fetchColumn()>0)continue;

        $ins=$pdo->prepare("INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)");
        $ins->execute([(int)$r['id'],(int)$p['id']]);
        $inserted++;
    }
}

echo "\nPermissions ajoutées : $inserted\n";
echo "Terminé. Déconnecte/reconnecte le maître de stage.\n";

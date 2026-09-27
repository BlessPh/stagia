<?php
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL','SUPER_ADMIN']);
header('Content-Type:text/plain; charset=utf-8');

echo "Correctif accès Chef de service aux affectations\n";
echo "------------------------------------------------\n";

try{
    $pdo->beginTransaction();
    $roleId=(int)$pdo->query("SELECT id FROM roles WHERE code='CHEF_SERVICE' OR role_code='CHEF_SERVICE' ORDER BY id LIMIT 1")->fetchColumn();
    if(!$roleId)throw new RuntimeException('Rôle CHEF_SERVICE introuvable.');

    $perms=['stage.manage','assignment.hosting.manage','host.view','supervision.hosting.view'];
    $hasRolePerm=false;
    try{$pdo->query('SELECT 1 FROM role_permissions LIMIT 1');$hasRolePerm=true;}catch(Throwable $e){}
    if($hasRolePerm){
        $sel=$pdo->prepare('SELECT id FROM permissions WHERE code=? LIMIT 1');
        $ins=$pdo->prepare('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(?,?)');
        foreach($perms as $p){
            $sel->execute([$p]);$pid=(int)$sel->fetchColumn();
            if($pid){$ins->execute([$roleId,$pid]);echo "Permission OK : $p\n";}
            else echo "Permission absente ignorée : $p\n";
        }
    }else echo "Table role_permissions absente : permissions non modifiées.\n";

    $pdo->commit();
    echo "\nTerminé. Reconnecte le chef de service puis ouvre Structure d’accueil → Affectations.\n";
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    echo "ERREUR : ".$e->getMessage();
}

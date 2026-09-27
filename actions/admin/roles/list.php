<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$contexte=exigerAdministrationRolesAjax($pdo);

try{
    $where=$contexte['super']?'r.systeme=1':'(r.systeme=1 OR r.etablissement_id=?)';
    $s=$pdo->prepare("
        SELECT r.id,r.code,r.nom,r.description,r.actif,r.systeme,r.etablissement_id,r.created_at,
               e.nom etablissement_nom,
               COUNT(DISTINCT rp.permission_id) nb_permissions,
               COUNT(DISTINCT CASE WHEN ra.actif=1 THEN ra.user_id END) nb_utilisateurs
        FROM roles r
        LEFT JOIN etablissements e ON e.id=r.etablissement_id
        LEFT JOIN role_permissions rp ON rp.role_id=r.id
        LEFT JOIN role_assignments ra ON ra.role_id=r.id
        WHERE $where
        GROUP BY r.id
        ORDER BY r.systeme DESC,r.id
    ");
    $s->execute($contexte['super']?[]:[$contexte['etablissement_id']]);
    $roles=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($roles as &$role){
        $role['modifiable']=roleModifiableDansContexte($role,$contexte)?1:0;
    }unset($role);

    $permissions=$pdo->query("
        SELECT id,code,nom,module,description,actif
        FROM permissions
        WHERE actif=1
        ORDER BY module,nom
    ")->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',['roles'=>$roles,'permissions'=>$permissions,'contexte'=>$contexte]);
}catch(Throwable $e){
    error_log('[ADMIN ROLES LIST] '.$e->getMessage());
    jsonResponse(false,'Impossible de charger les rôles.',[],500);
}

<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/admin-scope.php';

$role=$_SESSION['role_code']??'';
$academic=contextAcademicEnabled();
$host=contextHostEnabled();
$route=null;

if(hasRole('SUPER_ADMIN')){
    $route='/views/admin/dashboard.php';
}elseif(estResponsableMinisteriel($pdo)){
    $route='/views/espace-ministere/dashboard.php';
}elseif(contextPermission('analytics.overview.view') && hasRole('ORDRE_MEDECINS')){
    $route='/views/national/dashboard.php';
}elseif($role==='STAGIAIRE' || contextPermission(['student.self.view','stage.self.view'])){
    $route='/views/espace-etudiant/dashboard.php';
}elseif($role==='POINTEUR' && $host){
    $route='/views/espace-hopital/presences.php';
}elseif(in_array($role,['ADMIN_ACCUEIL','ENCADREUR'],true) && $host){
    $route='/views/espace-hopital/dashboard.php';
}elseif(in_array($role,['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],true) && $academic){
    $route='/views/espace-etablissement/dashboard.php';
}elseif($academic && !$host){
    $route='/views/espace-etablissement/dashboard.php';
}elseif($host && !$academic){
    $route='/views/espace-hopital/dashboard.php';
}elseif($academic && $host){
    $route=contextPermission(['host.manage','attendance.manage','evaluation.manage'])
        ?'/views/espace-hopital/dashboard.php'
        :'/views/espace-etablissement/dashboard.php';
}elseif(contextPermission('analytics.overview.view')){
    $route='/views/national/dashboard.php';
}

if($route!==null){header('Location: '.BASE_URL.$route);exit;}

http_response_code(403);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aucun espace associé | STAGIA-RDC</title>
<style>
body{font-family:"Segoe UI",Arial,sans-serif;background:#f7f7f7;margin:0;display:grid;place-items:center;min-height:100vh;color:#111827}
.card{width:min(620px,calc(100% - 32px));background:#fff;border:1px solid #e5e7eb;border-top:5px solid #ff751f;border-radius:16px;padding:30px;box-sizing:border-box}
h2{margin-top:0}.muted{color:#64748b;line-height:1.6}.badge{display:inline-block;background:#fff3e9;color:#ea580c;border-radius:999px;padding:6px 10px;font-weight:700}
a{display:inline-block;margin-top:18px;color:#ea580c;text-decoration:none;font-weight:700}
</style>
</head>
<body><div class="card">
<h2>Aucun espace n’est associé à ce contexte</h2>
<p class="muted">Le compte est authentifié, mais le type d’établissement ou les permissions actuelles ne donnent accès à aucun tableau de bord.</p>
<p>Rôle : <span class="badge"><?= htmlspecialchars($_SESSION['role_nom']??$role) ?></span></p>
<p>Type : <span class="badge"><?= htmlspecialchars($_SESSION['type_etablissement_nom']??'Non défini') ?></span></p>
<a href="<?= BASE_URL ?>/logout.php">Se déconnecter</a>
</div></body></html>

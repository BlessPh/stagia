<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

if($_SERVER['REQUEST_METHOD']!=='POST'){ header('Location: '.BASE_URL.'/views/etablissements/index.php'); exit; }
if(($_SESSION['role_code']??'')!=='SUPER_ADMIN'){ http_response_code(403); exit('Accès refusé.'); }
if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){ http_response_code(403); exit('Requête invalide.'); }

$id=(int)($_POST['id']??0);
$statut=$_POST['statut']??'';
$statuts=['VALIDE','REJETE','SUSPENDU'];

if(!$id || !in_array($statut,$statuts,true)){ http_response_code(400); exit('Données invalides.'); }

$stmt=$pdo->prepare("SELECT id,statut FROM etablissements WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$e=$stmt->fetch();

if(!$e){ http_response_code(404); exit('Établissement introuvable.'); }

$transitions=[
    'EN_ATTENTE'=>['VALIDE','REJETE'],
    'REJETE'=>['VALIDE'],
    'VALIDE'=>['SUSPENDU'],
    'SUSPENDU'=>['VALIDE']
];

if(!in_array($statut,$transitions[$e['statut']]??[],true)){
    http_response_code(400);
    exit('Changement de statut non autorisé.');
}

$pdo->prepare("UPDATE etablissements SET statut=? WHERE id=?")->execute([$statut,$id]);

header('Location: '.BASE_URL.'/views/etablissements/show.php?id='.$id.'&updated=1');
exit;
<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

if($_SERVER['REQUEST_METHOD']!=='POST'||($_SESSION['role_code']??'')!=='SUPER_ADMIN'){ http_response_code(403); exit('Accès refusé.'); }
if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){ http_response_code(403); exit('Requête invalide.'); }

$id=(int)($_POST['id']??0);
$statut=$_POST['statut']??'';
$commentaire=trim($_POST['commentaire']??'');

if(!in_array($statut,['EN_EXAMEN','A_COMPLETER','REJETEE'],true)){ exit('Statut invalide.'); }
if(in_array($statut,['A_COMPLETER','REJETEE'])&&$commentaire==='') exit('Une observation est obligatoire.');

$pdo->prepare("UPDATE demandes_adhesion SET statut=?,commentaire_admin=?,traite_par=?,traite_le=NOW() WHERE id=?")
    ->execute([$statut,$commentaire?:null,$_SESSION['user_id'],$id]);

header('Location: '.BASE_URL.'/views/adhesions/show.php?id='.$id.'&updated=1');
exit;
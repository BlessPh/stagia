<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==3){fwrite(STDERR,"Usage : php bin/diagnostiquer-bibliotheque.php email_lecteur id_document\n");exit(1);}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/permissions.php';
require_once __DIR__.'/../includes/rbac-session.php';
require_once __DIR__.'/../includes/bibliotheque.php';
$q=$pdo->prepare('SELECT id,actif,statut_compte FROM users WHERE email=?');$q->execute([$argv[1]]);
$users=$q->fetchAll(PDO::FETCH_ASSOC);
if(count($users)!==1)exit("Compte absent ou ambigu.\n");
$u=$users[0];$_SESSION=['user_id'=>(int)$u['id']];
$access=loadUserAccessContext($pdo,(int)$u['id']);
if(empty($access['ok'])||!(int)$u['actif']||$u['statut_compte']!=='ACTIF')exit("Compte sans accès actif à STAGIA.\n");
applyUserAccessContextToSession($access);
$c=biblioContexte($pdo);$d=biblioDocument($pdo,(int)$argv[2]);
$q=$pdo->prepare("SELECT COUNT(*) FROM bibliotheque_documents d WHERE d.id=? AND (".biblioCatalogueSql($c).")");
$q->execute([(int)$d['id']]);
echo json_encode(['document_id'=>$d['id'],'statut'=>$d['statut'],'diffusion'=>$d['visibilite'],
 'etablissement_document'=>$d['etablissement_id'],'etablissements_lecteur'=>$c['organisations'],
 'visible_catalogue'=>(bool)$q->fetchColumn(),'notice_accessible'=>biblioLit($c,$d),
 'telechargement'=>biblioTelecharge($c,$d)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";

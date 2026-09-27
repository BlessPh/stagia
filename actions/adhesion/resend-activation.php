<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../services/MailService.php';

if($_SERVER['REQUEST_METHOD']!=='POST'||($_SESSION['role_code']??'')!=='SUPER_ADMIN'){
    http_response_code(403);
    exit('Accès refusé.');
}

if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){
    http_response_code(403);
    exit('Requête invalide.');
}

$demandeId=(int)($_POST['id']??0);
if(!$demandeId) exit('Demande invalide.');


/* =========================================================
   DEMANDE + COMPTE
========================================================= */

$stmt=$pdo->prepare("
    SELECT
        d.id,
        d.user_id,
        d.nom_etablissement,
        d.responsable_nom,
        d.responsable_prenom,
        u.identifiant,
        u.email AS admin_email,
        u.statut_compte
    FROM demandes_adhesion d
    JOIN users u ON u.id=d.user_id
    WHERE d.id=?
      AND d.statut='VALIDEE'
    LIMIT 1
");

$stmt->execute([$demandeId]);
$d=$stmt->fetch();

if(!$d) exit('Demande ou utilisateur introuvable.');

if($d['statut_compte']==='ACTIF')
    exit('Ce compte est déjà activé.');

if($d['statut_compte']!=='A_ACTIVER')
    exit("Ce compte ne peut pas recevoir une invitation.");


/* =========================================================
   NOUVEAU TOKEN - 48 HEURES
========================================================= */

$token=bin2hex(random_bytes(32));
$hash=hash('sha256',$token);
$expire=date('Y-m-d H:i:s',time()+48*3600);

$stmt=$pdo->prepare("
    UPDATE users
    SET activation_token_hash=?,
        activation_expire_at=?
    WHERE id=?
      AND statut_compte='A_ACTIVER'
");

$stmt->execute([
    $hash,
    $expire,
    $d['user_id']
]);


/* =========================================================
   LIEN COMPLET
========================================================= */

$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')
    ?'https'
    :'http';

$lien=$scheme.'://'.$_SERVER['HTTP_HOST'].BASE_URL.
    '/activate.php?token='.urlencode($token);


/* =========================================================
   ENVOI SMTP
========================================================= */

$nom=trim(
    ($d['responsable_prenom']??'').' '.
    ($d['responsable_nom']??'')
);

$mailEnvoye=MailService::envoyerActivation(
    $d['admin_email'],
    $nom,
    $lien
);

if(!$mailEnvoye){
    error_log(
        '[SMTP RESEND] '.MailService::getLastError()
    );
}


/* =========================================================
   PAGE DE CONFIRMATION
========================================================= */

$_SESSION['activation_resend']=[
    'etablissement'=>$d['nom_etablissement'],
    'identifiant'=>$d['identifiant'],
    'email'=>$d['admin_email'],
    'token'=>$token,
    'expire'=>date('d/m/Y à H:i',strtotime($expire)),
    'mail_sent'=>$mailEnvoye
];

header(
    'Location: '.BASE_URL.
    '/views/adhesions/activation-link.php'
);

exit;
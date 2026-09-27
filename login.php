<?php
/**
 * Point d'entrée de connexion Web.
 *
 * Ce fichier affiche le formulaire et traite sa soumission POST :
 * il authentifie l'utilisateur, initialise la session RBAC, puis
 * redirige vers le tableau de bord.
 */

/* Une session est nécessaire pour mémoriser l'utilisateur authentifié. */
if(session_status()===PHP_SESSION_NONE)session_start();

/* Configuration générale, connexion PDO et chargement du contexte de rôles. */
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/rbac-session.php';

/* Un utilisateur déjà connecté ne doit pas revoir le formulaire de connexion. */
if(isset($_SESSION['user_id'])){header('Location: dashboard.php');exit;}

/* Traduction des codes d'erreur de redirection en messages compréhensibles. */
$message=match($_GET['error']??''){
    'session'=>'Votre session a expiré. Veuillez vous reconnecter.',
    'account'=>'Votre compte est désactivé ou n’est plus autorisé à se connecter.',
    'access'=>'Aucun rôle actif n’est actuellement attribué à votre compte.',
    'role'=>'Aucun espace n’est associé à votre rôle.',
    default=>''
};

/* Le même fichier agit comme contrôleur lorsque le formulaire est envoyé. */
if($_SERVER['REQUEST_METHOD']==='POST'){
    /* Nettoyage de l'identifiant ; le mot de passe reste inchangé avant vérification. */
    $login=trim($_POST['identifiant']??'');
    $password=$_POST['password']??'';
    $user=null;

    /* Validation minimale : les deux champs sont obligatoires. */
    if($login===''||$password===''){
        $message='Veuillez renseigner votre nom, identifiant ou e-mail et votre mot de passe.';
    }else{
        /* Identifiant ou e-mail : prioritaires et normalement uniques */
        $s=$pdo->prepare("
            SELECT u.*,r.code role_code,r.nom role_nom
            FROM users u JOIN roles r ON r.id=u.role_id
            WHERE LOWER(TRIM(u.identifiant))=LOWER(TRIM(?))
               OR LOWER(TRIM(u.email))=LOWER(TRIM(?))
            LIMIT 1
        ");
        $s->execute([$login,$login]);
        $user=$s->fetch(PDO::FETCH_ASSOC);

        /* Sinon chercher par nom / prénom / nom complet */
        /* Recherche de secours par nom/prénom ; elle accepte seulement un résultat non ambigu. */
        if(!$user){
            $s=$pdo->prepare("
                SELECT u.*,r.code role_code,r.nom role_nom
                FROM users u JOIN roles r ON r.id=u.role_id
                WHERE LOWER(TRIM(u.nom))=LOWER(TRIM(?))
                   OR LOWER(TRIM(u.prenom))=LOWER(TRIM(?))
                   OR LOWER(TRIM(CONCAT_WS(' ',u.nom,u.postnom,u.prenom)))=LOWER(TRIM(?))
                   OR LOWER(TRIM(CONCAT_WS(' ',u.prenom,u.nom,u.postnom)))=LOWER(TRIM(?))
                ORDER BY u.id LIMIT 2
            ");
            $s->execute([$login,$login,$login,$login]);
            $matches=$s->fetchAll(PDO::FETCH_ASSOC);

            /* Plusieurs homonymes ne sont jamais utilisés pour éviter de choisir le mauvais compte. */
            if(count($matches)===1)$user=$matches[0];
            elseif(count($matches)>1)
                $message='Plusieurs comptes correspondent à ce nom. Utilisez votre identifiant ou votre e-mail.';
        }

        /* Même message générique si le compte est introuvable ou si le mot de passe est erroné. */
        if(!$user&&$message===''){
            $message='Nom, identifiant, e-mail ou mot de passe incorrect.';
        }elseif($user){
            /* Vérification de l'état administratif du compte avant le mot de passe. */
            if(!(int)$user['actif']||($user['statut_compte']??'ACTIF')==='SUSPENDU'){
                $message='Votre compte est désactivé.';
            }elseif(($user['statut_compte']??'ACTIF')==='A_ACTIVER'){
                $message="Votre compte n'est pas encore activé. Utilisez le lien d'activation reçu.";
            /* password_verify compare le mot de passe fourni avec son hash enregistré. */
            }elseif(empty($user['password'])||!password_verify($password,$user['password'])){
                $message='Nom, identifiant, e-mail ou mot de passe incorrect.';
            }else{
                /* Chargement des rôles, périmètres et affectations encore valides dans la base. */
                $access=loadUserAccessContext($pdo,(int)$user['id']);

                if(empty($access['ok'])){
                    $message=$access['reason']==='NO_ACTIVE_ASSIGNMENT'
                        ?'Votre compte ne possède actuellement aucune affectation de rôle active.'
                        :'Aucun rôle valide n’est attribué à votre compte.';
                }else{
                    /* Nouvelle identité de session : protection contre la fixation de session. */
                    session_regenerate_id(true);

                    /* Données minimales nécessaires aux pages protégées. */
                    $_SESSION['user_id']=(int)$user['id'];
                    $_SESSION['nom']=$user['nom'];
                    $_SESSION['prenom']=$user['prenom'];
                    applyUserAccessContextToSession($access);
                    $_SESSION['last_activity']=$_SESSION['session_regenerated_at']=time();
                    /* Traçabilité de la dernière connexion, puis accès au tableau de bord. */
                    $pdo->prepare("UPDATE users SET derniere_connexion=NOW() WHERE id=?")->execute([$user['id']]);
                    header('Location: dashboard.php');exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<!-- Métadonnées, framework visuel et feuille de style commune. -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion | STAGIA-RDC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
/* Mise en page propre à la connexion : identité visuelle, carte et adaptation mobile. */
:root{--o:#ff751f;--b:#000;--m:#777;--bd:#e7e7e7;--s:#fff1e8}*{box-sizing:border-box}body{margin:0;font-family:"Segoe UI",Arial,sans-serif}.login-page{min-height:100vh;background:#f5f5f5;display:flex;flex-direction:column}.login-header{background:#fff;border-top:4px solid var(--o);border-bottom:1px solid #ececec;height:86px;display:flex;align-items:center}.login-brand{display:flex;align-items:center;gap:14px;text-decoration:none}.login-brand-logo{width:72px}.login-brand-text strong,.login-brand-text small{display:block;line-height:1.05}.login-brand-text strong{font-size:28px;font-weight:800;color:#000}.login-brand-text small{font-size:15px;color:var(--o)}.btn-back{display:flex;align-items:center;gap:8px;padding:11px 18px;border:1px solid #ddd;border-radius:9px;background:#fff;color:#444;text-decoration:none;font-size:13px;font-weight:600}.btn-back:hover{border-color:var(--o);color:var(--o)}.login-main{flex:1}.login-layout{display:grid;grid-template-columns:59% 41%;min-height:calc(100vh - 141px)}.login-left{position:relative;display:flex;align-items:center;background:url("./assets/img/login.png") center/cover no-repeat;overflow:hidden}.login-left-overlay{position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.96),rgba(255,255,255,.9) 34%,rgba(255,255,255,.55) 62%,rgba(255,255,255,.12))}.login-left-content{position:relative;z-index:2;max-width:520px;padding:0 80px 229px 75px}.login-badge{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;margin-bottom:26px;border:1px solid #ff751f59;border-radius:30px;background:#fff7f2;color:var(--o);font-size:12px;font-weight:700}.login-left-content h1{margin:0 0 22px;font-size:64px;line-height:1.02;font-weight:850;color:#000}.login-left-content h1 span{display:block;color:var(--o)}.login-left-content p{max-width:470px;color:#5f5f5f;font-size:18px;line-height:1.8}.login-benefits{display:flex;flex-direction:column;gap:18px;margin-top:40px}.benefit-item{display:flex;align-items:center;gap:16px}.benefit-item>span{width:68px;height:68px;display:flex;align-items:center;justify-content:center;border-radius:16px;border:1px solid var(--bd);background:#fffffff2;color:var(--o);font-size:30px}.benefit-item strong{display:block;font-size:17px}.benefit-item small{color:var(--m)}.login-right{display:flex;align-items:center;justify-content:center;padding:19px 47px;background:#fafafa;border-left:1px solid #ececec}.login-card{width:100%;max-width:560px;background:#fff;border:1px solid #e8e8e8;border-top:5px solid var(--o);border-radius:22px;padding:34px;box-shadow:0 22px 55px #00000014}.login-card-top{text-align:center}.login-card-logo{width:120px;margin:0 auto 12px}.login-card-top h2{font-size:26px;font-weight:800}.login-card-top p{color:#888}.login-alert{display:flex;gap:10px;margin-bottom:18px;padding:12px 14px;background:#fff5ee;border:1px solid #ffd8c0;border-radius:10px;color:#9e4d17;font-size:12px}.form-label{font-size:14px;font-weight:700}.stagia-input{min-height:56px;border:1px solid #ddd;border-radius:10px;overflow:hidden}.stagia-input:focus-within{border-color:var(--o);box-shadow:0 0 0 3px #ff751f17}.stagia-input .input-group-text,.stagia-input .form-control,.password-toggle{border:0;background:#fff}.stagia-input .form-control{min-height:54px;box-shadow:none}.password-toggle{padding:0 16px}.forgot-link{color:var(--o);text-decoration:none;font-size:13px}.btn-stagia-login{min-height:54px;border:0;border-radius:10px;background:linear-gradient(120deg,#000,var(--o));color:#fff;font-weight:750}.btn-stagia-login:hover{background:#000;color:#fff}.login-separator{position:relative;margin:28px 0 18px;text-align:center}.login-separator:before{content:"";position:absolute;left:0;right:0;top:50%;height:1px;background:#e8e8e8}.login-separator span{position:relative;padding:0 14px;background:#fff;color:#aaa;font-size:11px}.new-account-box{display:flex;gap:13px;padding:18px;margin-bottom:12px;background:#fafafa;border:1px solid #ededed;border-radius:12px}.new-account-icon{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:var(--s);color:var(--o);font-size:20px}.new-account-box p{margin:5px 0 0;color:#777;font-size:13px}.btn-adhesion{min-height:48px;display:flex;align-items:center;justify-content:center;border:1px solid var(--o);border-radius:10px;color:var(--o);font-weight:700;text-decoration:none}.btn-adhesion:hover{background:var(--o);color:#fff}.login-help{text-align:center;margin-top:20px;color:#888;font-size:13px}.login-help a{color:var(--o)}.login-footer{background:#000;border-top:4px solid var(--o);color:#d0d0d0;padding:16px 0;font-size:13px}@media(max-width:991px){.login-layout{grid-template-columns:1fr}.login-right{border:0;padding:30px 15px}}@media(max-width:576px){.login-card{padding:24px 18px}.login-brand-logo{width:48px}.login-brand-text strong{font-size:18px}}
</style>
</head>
<body class="login-page">

<header class="login-header">
<!-- En-tête : identité STAGIA et lien de retour vers le portail public. -->
<div class="container-fluid px-4 px-lg-5 d-flex justify-content-between align-items-center">
<a href="index.php" class="login-brand">
<img src="assets/img/logo.png" class="login-brand-logo" alt="STAGIA">
<div class="login-brand-text"><strong>STAGIA-RDC</strong><small>Portail National des Stages</small></div>
</a>
<a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i><span>Retour au portail</span></a>
</div>
</header>

<main class="login-main"><div class="login-layout">

<section class="login-left d-none d-lg-flex">
<!-- Présentation marketing, masquée sur petit écran pour privilégier le formulaire. -->
<div class="login-left-overlay"></div>
<div class="login-left-content">
<span class="login-badge"><i class="bi bi-shield-lock"></i>Accès sécurisé</span>
<h1>Bienvenue sur<span>STAGIA-RDC</span></h1>
<p>Connectez-vous à votre espace personnel pour accéder aux services de gestion, suivi et évaluation des stages.</p>

<div class="login-benefits">
<div class="benefit-item"><span><i class="bi bi-person"></i></span><div><strong>Accès personnalisé</strong><small>Un espace adapté à votre profil.</small></div></div>
<div class="benefit-item"><span><i class="bi bi-shield-check"></i></span><div><strong>Données sécurisées</strong><small>Protection et traçabilité des accès.</small></div></div>
<div class="benefit-item"><span><i class="bi bi-clock-history"></i></span><div><strong>Suivi permanent</strong><small>Retrouvez votre parcours et vos opérations.</small></div></div>
</div>
</div>
</section>

<section class="login-right"><div class="login-card">
<!-- Carte de connexion et retour visible des erreurs de validation/authentification. -->
<div class="login-card-top">
<img src="assets/img/logo.png" class="login-card-logo" alt="STAGIA">
<h2>Connexion</h2><p>Accédez à votre espace STAGIA</p>
</div>

<?php if($message): ?>
<!-- htmlspecialchars empêche qu'un message soit interprété comme du HTML. -->
<div class="login-alert"><i class="bi bi-exclamation-circle-fill"></i><span><?= htmlspecialchars($message) ?></span></div>
<?php endif; ?>

<!-- Sans attribut action, le formulaire POSTe vers ce même fichier login.php. -->
<form method="POST">
<div class="mb-3">
<label class="form-label">Nom, identifiant ou e-mail</label>
<div class="input-group stagia-input">
<span class="input-group-text"><i class="bi bi-person"></i></span>
<input type="text" name="identifiant" class="form-control"
       placeholder="Nom, identifiant ou e-mail"
       value="<?= htmlspecialchars($_POST['identifiant']??'') ?>"
       autocomplete="username" required>
</div>
</div>

<div class="mb-2">
<!-- Mot de passe avec autocomplétion dédiée et bouton JavaScript d'affichage temporaire. -->
<div class="d-flex justify-content-between">
<label class="form-label">Mot de passe</label>
<a href="#" class="forgot-link">Mot de passe oublié ?</a>
</div>
<div class="input-group stagia-input">
<span class="input-group-text"><i class="bi bi-lock"></i></span>
<input type="password" name="password" id="password" class="form-control"
       placeholder="Votre mot de passe" autocomplete="current-password" required>
<button class="password-toggle" type="button" id="togglePassword"><i class="bi bi-eye" id="passwordIcon"></i></button>
</div>
</div>

<div class="form-check my-4">
<!-- Élément visuel pour l'instant : sans name ni traitement PHP, il ne crée pas de session persistante. -->
<input class="form-check-input" type="checkbox" id="remember">
<label class="form-check-label" for="remember">Se souvenir de moi</label>
</div>

<button class="btn btn-stagia-login w-100">
<i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
</button>
</form>

<div class="login-separator"><span>Première utilisation ?</span></div>
<div class="new-account-box">
<!-- Parcours d'adhésion proposé aux établissements qui ne possèdent pas encore de compte. -->
<span class="new-account-icon"><i class="bi bi-building"></i></span>
<div><strong>Vous représentez un établissement ?</strong><p>Demandez l'adhésion de votre structure à STAGIA-RDC.</p></div>
</div>

<a href="adhesion.php" class="btn-adhesion w-100">
<i class="bi bi-building-add me-2"></i>Demander une adhésion
</a>

<div class="login-help"><i class="bi bi-question-circle"></i> Besoin d'aide ? <a href="#">Contacter l'assistance</a></div>
</div></section>

</div></main>

<footer class="login-footer">
<!-- Pied de page institutionnel. -->
<div class="container-fluid px-4 px-lg-5 d-flex justify-content-between flex-wrap">
<span>© <?= date('Y') ?> STAGIA-RDC. Tous droits réservés.</span>
<span>République Démocratique du Congo</span>
</div>
</footer>

<script>
// Alterne uniquement le type du champ et l'icône ; le mot de passe n'est jamais transmis ici.
const p=document.getElementById('password'),i=document.getElementById('passwordIcon');
document.getElementById('togglePassword').onclick=()=>{
 const show=p.type==='password';p.type=show?'text':'password';
 i.className=show?'bi bi-eye-slash':'bi bi-eye';
};
</script>
<!-- Composants JavaScript Bootstrap utilisés par l'interface. -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

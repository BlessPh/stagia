<?php
session_start();
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';

$token=trim($_GET['token']??'');
$invalid=false;
$user=null;

if($token===''){
    $invalid=true;
}else{
    $hash=hash('sha256',$token);

    $stmt=$pdo->prepare("
        SELECT u.id,u.nom,u.prenom,u.identifiant,e.nom AS etablissement
        FROM users u
        LEFT JOIN etablissement_users eu ON eu.user_id=u.id
        LEFT JOIN etablissements e ON e.id=eu.etablissement_id
        WHERE u.activation_token_hash=?
          AND u.statut_compte='A_ACTIVER'
          AND u.activation_expire_at>NOW()
        LIMIT 1
    ");

    $stmt->execute([$hash]);
    $user=$stmt->fetch();

    if(!$user) $invalid=true;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activation du compte | STAGIA-RDC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__.'/assets/css/style.css') ?>">
<style>
  a {
  color: rgb(255, 106, 8);
}
</style>
</head>

<body class="login-page">

<header class="login-header">
<div class="container d-flex justify-content-between align-items-center">
     <a class="navbar-brand" href="index.php">
            <img src="assets/img/logo.png" class="brand-logo" alt="Logo STAGIA-RDC">
        </a>
    <a href="index.php" class="login-brand">
     
        <div><small>Activation de votre espace</small></div>
    </a>
</div>
</header>

<main class="login-main">
<div class="container">
<div class="row justify-content-center align-items-center min-vh-login">

<div class="col-md-8 col-lg-5">

<?php if($invalid): ?>

    <div class="login-card text-center">
        <div class="activation-invalid">
            <i class="bi bi-link-45deg"></i>
        </div>

        <h2>Lien invalide ou expiré</h2>

        <p class="text-muted small">
            Ce lien d'activation n'est plus valide. Veuillez demander une nouvelle invitation à STAGIA-RDC.
        </p>

        <a href="index.php" class="btn btn-primary-stagia mt-2">Retour au portail</a>
    </div>

<?php else: ?>

    <div class="login-card">

        <div class="login-card-top">
            <div class="login-logo"><i class="bi bi-shield-lock"></i></div>
            <h2>Activez votre espace</h2>
            <p><?= htmlspecialchars($user['etablissement']??'STAGIA-RDC') ?></p>
        </div>

        <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger small">
            <?= match($_GET['error']){
                'password'=>'Le mot de passe ne respecte pas les critères demandés.',
                'confirm'=>'Les deux mots de passe ne correspondent pas.',
                default=>'Impossible d’activer le compte.'
            } ?>
        </div>
        <?php endif; ?>

        <div class="activation-user mb-4">
            <small>Votre identifiant</small>
            <strong><?= htmlspecialchars($user['identifiant']) ?></strong>
        </div>

        <form action="actions/auth/activate.php" method="POST">

            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

            <div class="mb-3">
                <label class="form-label">Nouveau mot de passe</label>

                <div class="input-group stagia-input">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>

                <small class="password-help">
                    8 caractères minimum avec majuscule, minuscule, chiffre et caractère spécial.
                </small>
            </div>

            <div class="mb-4">
                <label class="form-label">Confirmer le mot de passe</label>

                <div class="input-group stagia-input">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" name="confirmation" class="form-control" required>
                </div>
            </div>

            <button class="btn btn-stagia-login w-100">
                <i class="bi bi-check-circle me-1"></i> Activer mon compte
            </button>

        </form>

    </div>

<?php endif; ?>

</div>
</div>
</div>
</main>

</body>
</html>
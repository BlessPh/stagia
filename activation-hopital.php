<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';

$token=$_GET['token']??$_POST['token']??'';
$message='';
$success=false;
$user=null;

if($token){

    $hash=hash('sha256',$token);

    $stmt=$pdo->prepare("
        SELECT u.id,u.nom,u.prenom,u.identifiant,
               eu.etablissement_id,e.nom etablissement
        FROM users u
        JOIN etablissement_users eu
          ON eu.user_id=u.id
         AND eu.principal=1
        JOIN etablissements e
          ON e.id=eu.etablissement_id
        WHERE u.activation_token_hash=?
          AND u.statut_compte='A_ACTIVER'
          AND e.type_etablissement='HOPITAL'
        LIMIT 1
    ");

    $stmt->execute([$hash]);
    $user=$stmt->fetch();
}

if($_SERVER['REQUEST_METHOD']==='POST'){

    if(!$user){
        $message='Lien d’activation invalide ou déjà utilisé.';
    }else{

        $password=$_POST['password']??'';
        $confirmation=$_POST['password_confirmation']??'';

        if(strlen($password)<8){

            $message='Le mot de passe doit contenir au moins 8 caractères.';

        }elseif($password!==$confirmation){

            $message='Les mots de passe ne correspondent pas.';

        }else{

            try{

                $pdo->beginTransaction();

                $pdo->prepare("
                    UPDATE users
                    SET password=?,
                        statut_compte='ACTIF',
                        actif=1,
                        activation_token_hash=NULL
                    WHERE id=?
                ")->execute([
                    password_hash($password,PASSWORD_DEFAULT),
                    $user['id']
                ]);

                $pdo->prepare("
                    UPDATE etablissements
                    SET statut='ACTIF'
                    WHERE id=?
                      AND type_etablissement='HOPITAL'
                ")->execute([
                    $user['etablissement_id']
                ]);

                $pdo->commit();

                $success=true;

            }catch(Throwable $e){

                if($pdo->inTransaction())
                    $pdo->rollBack();

                $message='Impossible d’activer le compte.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title>Activation espace Hôpital | STAGIA-RDC</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
      rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
      rel="stylesheet">

<link rel="stylesheet"
      href="<?= BASE_URL ?>/assets/css/style.css">

</head>

<body class="login-page">

<main class="login-main">

<div class="container">

<div class="row justify-content-center align-items-center min-vh-100">

<div class="col-md-7 col-lg-5">

<div class="login-card">

<?php if($success): ?>

    <div class="text-center">

        <div class="login-logo mb-3">
            <i class="bi bi-check-lg"></i>
        </div>

        <h2>Espace activé</h2>

        <p class="text-muted">
            Votre compte administrateur hospitalier
            est maintenant actif.
        </p>

        <a href="<?= BASE_URL ?>/login.php"
           class="btn btn-stagia-login w-100 mt-3">

            <i class="bi bi-box-arrow-in-right me-1"></i>
            Se connecter

        </a>

    </div>

<?php elseif(!$user): ?>

    <div class="alert alert-danger mb-0">

        <i class="bi bi-exclamation-triangle me-2"></i>

        Lien d’activation invalide ou déjà utilisé.

    </div>

<?php else: ?>

    <div class="text-center mb-4">

        <div class="login-logo">
            <i class="bi bi-hospital"></i>
        </div>

        <h2>Activer votre espace</h2>

        <p class="text-muted mb-0">
            <?= htmlspecialchars($user['etablissement']) ?>
        </p>

    </div>

    <?php if($message): ?>

        <div class="alert alert-warning">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>

    <div class="alert alert-light border small">

        <strong>Identifiant :</strong>

        <?= htmlspecialchars($user['identifiant']) ?>

    </div>

    <form method="POST">

        <input type="hidden"
               name="token"
               value="<?= htmlspecialchars($token) ?>">

        <div class="mb-3">

            <label class="form-label">
                Mot de passe *
            </label>

            <input type="password"
                   name="password"
                   class="form-control"
                   minlength="8"
                   required>

        </div>

        <div class="mb-4">

            <label class="form-label">
                Confirmer le mot de passe *
            </label>

            <input type="password"
                   name="password_confirmation"
                   class="form-control"
                   minlength="8"
                   required>

        </div>

        <button class="btn btn-stagia-login w-100">

            <i class="bi bi-check-circle me-1"></i>
            Activer mon compte

        </button>

    </form>

<?php endif; ?>

</div>

</div>
</div>
</div>

</main>

</body>
</html>
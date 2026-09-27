<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/auth.php';

if(($_SESSION['role_code']??'')!=='SUPER_ADMIN' || empty($_SESSION['activation_resend'])){
    header('Location: '.BASE_URL.'/views/adhesions/index.php'); exit;
}

$a=$_SESSION['activation_resend'];
unset($_SESSION['activation_resend']);

$url='http://'.$_SERVER['HTTP_HOST'].BASE_URL.'/activate.php?token='.urlencode($a['token']);

$pageTitle='Lien d’activation';
$activePage='adhesions';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="success-card mx-auto mt-4">

    <div class="success-icon">
        <i class="bi bi-envelope-check"></i>
    </div>

    <h2 class="text-center">Nouvelle invitation créée</h2>

    <p class="text-center">
        <?= htmlspecialchars($a['etablissement']) ?>
    </p>

    <div class="reference-box">
        <small>Identifiant</small>
        <strong><?= htmlspecialchars($a['identifiant']) ?></strong>
    </div>

    <div class="reference-box">
        <small>E-mail</small>
        <strong><?= htmlspecialchars($a['email']) ?></strong>
    </div>

    <label class="form-label">Nouveau lien d'activation</label>

    <div class="input-group mb-3">
        <input id="activationLink" class="form-control"
               value="<?= htmlspecialchars($url) ?>" readonly>

        <button class="btn btn-outline-primary" type="button" onclick="copyLink()">
            <i class="bi bi-copy"></i> Copier
        </button>
    </div>

    <div class="alert alert-info small">
        Ce nouveau lien est valable pendant 48 heures. L'ancien lien devient automatiquement inutilisable.
    </div>

    <a href="<?= BASE_URL ?>/views/adhesions/index.php"
       class="btn btn-primary-stagia w-100">
        Retour aux demandes
    </a>

</div>

</main>

<script>
function copyLink(){
    navigator.clipboard.writeText(document.getElementById('activationLink').value);
}
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
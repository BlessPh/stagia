<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/auth.php';

if(($_SESSION['role_code']??'')!=='SUPER_ADMIN' || empty($_SESSION['new_account'])){
    header('Location: '.BASE_URL.'/views/adhesions/index.php');
    exit;
}

$a=$_SESSION['new_account'];
unset($_SESSION['new_account']);

$activationUrl='http://'.$_SERVER['HTTP_HOST'].BASE_URL.'/activate.php?token='.urlencode($a['token']);

$pageTitle='Espace créé';
$activePage='adhesions';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="success-card mx-auto mt-4">

    <div class="success-icon">
        <i class="bi bi-check-lg"></i>
    </div>

    <h2 class="text-center">Espace établissement créé</h2>

    <p class="text-center">
        <?= htmlspecialchars($a['etablissement']) ?> est désormais validé dans STAGIA-RDC.
    </p>

    <div class="reference-box">
        <small>Identifiant administrateur</small>
        <strong><?= htmlspecialchars($a['identifiant']) ?></strong>
    </div>

    <div class="reference-box">
        <small>Adresse e-mail</small>
        <strong><?= htmlspecialchars($a['email']) ?></strong>
    </div>

    <label class="form-label">Lien d'activation</label>

    <div class="input-group mb-3">
        <input id="activationLink" class="form-control"
               value="<?= htmlspecialchars($activationUrl) ?>" readonly>

        <button class="btn btn-outline-primary" type="button" onclick="copyActivation()">
            <i class="bi bi-copy"></i> Copier
        </button>
    </div>

    <div class="alert alert-info small">
        Le responsable doit utiliser ce lien dans les 48 heures et choisir lui-même son mot de passe.
    </div>

    <a href="<?= BASE_URL ?>/views/adhesions/index.php" class="btn btn-primary-stagia w-100">
        Retour aux demandes
    </a>

</div>

</main>

<script>
function copyActivation(){
    navigator.clipboard.writeText(document.getElementById('activationLink').value);
}
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
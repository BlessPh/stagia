<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);

$a=$_SESSION['activation_resend']??null;

if(!$a){
    header('Location: '.BASE_URL.'/views/adhesions/index.php');
    exit;
}

/* Informations utilisées une seule fois */
unset($_SESSION['activation_resend']);

$pageTitle="Invitation d'activation";
$activePage='adhesions';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="page-heading">
    <div>
        <a href="<?= BASE_URL ?>/views/adhesions/index.php" class="detail-back">
            <i class="bi bi-arrow-left"></i> Demandes d'adhésion
        </a>

        <h1>Invitation d'activation</h1>
        <p>Invitation du compte administrateur de l'établissement.</p>
    </div>
</div>

<div class="dashboard-card p-4" style="max-width:850px">

    <?php if(!empty($a['mail_sent'])): ?>

        <div class="alert alert-success">
            <i class="bi bi-envelope-check me-2"></i>
            <strong>Invitation envoyée avec succès.</strong>
            <div class="small mt-1">
                Le responsable a reçu son lien d'activation par e-mail.
            </div>
        </div>

    <?php else: ?>

        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <strong>L'e-mail n'a pas pu être envoyé.</strong>
            <div class="small mt-1">
                Vous pourrez renvoyer l'invitation depuis la fiche de la demande.
            </div>
        </div>

    <?php endif; ?>


    <?php if(!empty($a['etablissement'])): ?>

        <div class="mb-3">
            <label class="form-label fw-semibold">Établissement</label>

            <input
                class="form-control"
                value="<?= htmlspecialchars($a['etablissement']) ?>"
                readonly
            >
        </div>

    <?php endif; ?>


    <div class="row g-3">

        <div class="col-md-6">
            <label class="form-label fw-semibold">
                Adresse du responsable
            </label>

            <input
                class="form-control"
                value="<?= htmlspecialchars($a['email']??'') ?>"
                readonly
            >
        </div>

        <div class="col-md-6">
            <label class="form-label fw-semibold">
                Identifiant
            </label>

            <input
                class="form-control"
                value="<?= htmlspecialchars($a['identifiant']??'') ?>"
                readonly
            >
        </div>

    </div>


    <?php if(!empty($a['expire'])): ?>

        <div class="alert alert-light border mt-3 mb-0">
            <i class="bi bi-clock me-2"></i>

            L'invitation est valable jusqu'au

            <strong>
                <?= htmlspecialchars($a['expire']) ?>
            </strong>.
        </div>

    <?php endif; ?>


    <div class="d-flex gap-2 mt-4">

        <?php if(!empty($a['demande_id'])): ?>

            <a
                href="<?= BASE_URL ?>/views/adhesions/show.php?id=<?= (int)$a['demande_id'] ?>"
                class="btn btn-primary-stagia"
            >
                <i class="bi bi-eye me-1"></i>
                Voir la demande
            </a>

        <?php endif; ?>

        <a
            href="<?= BASE_URL ?>/views/adhesions/index.php"
            class="btn btn-light border"
        >
            Retour aux demandes
        </a>

    </div>

</div>

</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
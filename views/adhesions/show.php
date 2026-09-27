<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

/* =========================================================
   SÉCURITÉ
========================================================= */
if(($_SESSION['role_code']??'')!=='SUPER_ADMIN'){
    http_response_code(403);
    exit('Accès refusé.');
}

$id=(int)($_GET['id']??0);

$stmt=$pdo->prepare("
    SELECT *
    FROM demandes_adhesion
    WHERE id=?
    LIMIT 1
");
$stmt->execute([$id]);
$d=$stmt->fetch();

if(!$d){
    http_response_code(404);
    exit('Demande introuvable.');
}

/* =========================================================
   COMPTE ADMINISTRATEUR
========================================================= */
$admin=null;

if(!empty($d['user_id'])){
    $stmt=$pdo->prepare("
        SELECT
            id,
            identifiant,
            email,
            statut_compte,
            activation_expire_at,
            activated_at
        FROM users
        WHERE id=?
        LIMIT 1
    ");
    $stmt->execute([$d['user_id']]);
    $admin=$stmt->fetch();
}

/* =========================================================
   PAGE
========================================================= */
$pageTitle='Demande '.$d['reference'];
$activePage='adhesions';

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$labels=[
    'SOUMISE'=>['primary','Soumise'],
    'EN_EXAMEN'=>['info','En examen'],
    'A_COMPLETER'=>['warning','À compléter'],
    'VALIDEE'=>['success','Validée'],
    'REJETEE'=>['danger','Rejetée']
];

[$badge,$label]=$labels[$d['statut']]??['secondary',$d['statut']];

$hasDocument=!empty($d['piece_justificative']);

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- =========================================================
     ENTÊTE
========================================================= -->
<div class="page-heading">
    <div>
        <a href="index.php" class="detail-back">
            <i class="bi bi-arrow-left"></i>
            Demandes d'adhésion
        </a>

        <h1><?= htmlspecialchars($d['nom_etablissement']) ?></h1>
        <p><?= htmlspecialchars($d['reference']) ?></p>
    </div>

    <span class="badge text-bg-<?= $badge ?> detail-status">
        <?= $label ?>
    </span>
</div>

<?php if(isset($_GET['updated'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    Statut de la demande mis à jour.
    <button class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>


<div class="row g-3">

<!-- =========================================================
     COLONNE GAUCHE
========================================================= -->
<div class="col-lg-8">


<!-- =========================================================
     ÉTABLISSEMENT
========================================================= -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">
        <i class="bi bi-building"></i>

        <div>
            <h5>Établissement</h5>
            <p>Informations fournies lors de la demande.</p>
        </div>
    </div>

    <div class="detail-grid">

        <div>
            <span>Nom</span>
            <strong>
                <?= htmlspecialchars($d['nom_etablissement']) ?>
            </strong>
        </div>

        <div>
            <span>Type</span>
            <strong>
                <?= htmlspecialchars($d['type_etablissement']) ?>
            </strong>
        </div>

        <div>
            <span>N° d'agrément</span>
            <strong>
                <?= htmlspecialchars($d['numero_agrement']?:'Non renseigné') ?>
            </strong>
        </div>

        <div>
            <span>E-mail</span>
            <strong>
                <?= htmlspecialchars($d['email_etablissement']?:'Non renseigné') ?>
            </strong>
        </div>

        <div>
            <span>Téléphone</span>
            <strong>
                <?= htmlspecialchars($d['telephone_etablissement']?:'Non renseigné') ?>
            </strong>
        </div>

        <div>
            <span>Province</span>
            <strong>
                <?= htmlspecialchars($d['province']?:'Non renseignée') ?>
            </strong>
        </div>

        <div>
            <span>Ville / Territoire</span>
            <strong>
                <?= htmlspecialchars($d['ville']?:'Non renseignée') ?>
            </strong>
        </div>

        <div class="detail-full">
            <span>Adresse</span>
            <strong>
                <?= htmlspecialchars($d['adresse']?:'Non renseignée') ?>
            </strong>
        </div>

    </div>
</div>


<!-- =========================================================
     PIÈCE JUSTIFICATIVE
========================================================= -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">
        <i class="bi bi-file-earmark-check"></i>

        <div>
            <h5>Pièce justificative</h5>
            <p>
                Document officiel fourni lors de la demande
                d'adhésion.
            </p>
        </div>
    </div>

    <div class="p-3">

        <?php if($hasDocument): ?>

            <div class="registration-info">

                <i class="bi bi-file-earmark-text"></i>

                <div>
                    <strong>Document officiel disponible</strong>

                    <p>
                        Consultez et vérifiez cette pièce avant
                        de valider l'établissement.
                    </p>
                </div>

            </div>

            <a
                href="<?= BASE_URL ?>/actions/adhesion/document.php?id=<?= (int)$d['id'] ?>"
                target="_blank"
                rel="noopener"
                class="btn btn-outline-primary w-100 mt-3"
            >
                <i class="bi bi-eye me-1"></i>
                Consulter la pièce justificative
            </a>

        <?php else: ?>

            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle me-2"></i>

                <strong>Pièce justificative absente.</strong>

                <div class="small mt-1">
                    Cette demande a probablement été enregistrée
                    avant l'ajout des pièces justificatives.
                </div>
            </div>

        <?php endif; ?>

    </div>
</div>


<!-- =========================================================
     RESPONSABLE
========================================================= -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">
        <i class="bi bi-person-vcard"></i>

        <div>
            <h5>Responsable de l'établissement</h5>
            <p>
                Cette personne deviendra administrateur après validation.
            </p>
        </div>
    </div>

    <div class="detail-grid">

        <div>
            <span>Nom</span>
            <strong>
                <?= htmlspecialchars($d['responsable_nom']) ?>
            </strong>
        </div>

        <div>
            <span>Post-nom</span>
            <strong>
                <?= htmlspecialchars($d['responsable_postnom']?:'-') ?>
            </strong>
        </div>

        <div>
            <span>Prénom</span>
            <strong>
                <?= htmlspecialchars($d['responsable_prenom']?:'-') ?>
            </strong>
        </div>

        <div>
            <span>Fonction</span>
            <strong>
                <?= htmlspecialchars($d['responsable_fonction']?:'-') ?>
            </strong>
        </div>

        <div>
            <span>E-mail</span>
            <strong>
                <?= htmlspecialchars($d['responsable_email']) ?>
            </strong>
        </div>

        <div>
            <span>Téléphone</span>
            <strong>
                <?= htmlspecialchars($d['responsable_telephone']) ?>
            </strong>
        </div>

    </div>
</div>

</div>


<!-- =========================================================
     COLONNE DROITE
========================================================= -->
<div class="col-lg-4">


<!-- =========================================================
     TRAITEMENT
========================================================= -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">
        <i class="bi bi-shield-check"></i>

        <div>
            <h5>Traitement</h5>
            <p>Décision administrative.</p>
        </div>
    </div>

    <div class="p-3">


    <?php if(in_array(
        $d['statut'],
        ['SOUMISE','EN_EXAMEN','A_COMPLETER'],
        true
    )): ?>


        <!-- METTRE EN EXAMEN -->

        <?php if($d['statut']==='SOUMISE'): ?>

        <form
            action="<?= BASE_URL ?>/actions/adhesion/statut.php"
            method="POST"
            class="mb-2"
        >
            <input
                type="hidden"
                name="csrf"
                value="<?= $_SESSION['csrf'] ?>"
            >

            <input
                type="hidden"
                name="id"
                value="<?= $d['id'] ?>"
            >

            <input
                type="hidden"
                name="statut"
                value="EN_EXAMEN"
            >

            <button class="btn btn-outline-primary w-100">
                <i class="bi bi-search me-1"></i>
                Mettre en examen
            </button>
        </form>

        <?php endif; ?>


        <!-- VALIDATION -->

        <form
            action="<?= BASE_URL ?>/actions/adhesion/validate.php"
            method="POST"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= $_SESSION['csrf'] ?>"
            >

            <input
                type="hidden"
                name="id"
                value="<?= $d['id'] ?>"
            >

            <div class="alert alert-light border small mb-3">
                <i class="bi bi-magic me-1"></i>
                Le code STAGIA et l'identifiant administrateur
                seront générés automatiquement.
            </div>

            <?php if($hasDocument): ?>

                <div class="alert alert-success small">
                    <i class="bi bi-file-earmark-check me-1"></i>
                    Pièce justificative fournie.
                </div>

            <?php else: ?>

                <div class="alert alert-warning small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Aucune pièce justificative n'est disponible
                    pour cette ancienne demande.
                </div>

            <?php endif; ?>

            <button
                class="btn btn-success w-100"
                onclick="return confirm(
                    'Valider cette adhésion et créer son espace ?'
                )"
            >
                <i class="bi bi-check-circle me-1"></i>
                Valider et créer l'espace
            </button>

        </form>


        <hr>


        <!-- COMPLÉMENT / REJET -->

        <form
            action="<?= BASE_URL ?>/actions/adhesion/statut.php"
            method="POST"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= $_SESSION['csrf'] ?>"
            >

            <input
                type="hidden"
                name="id"
                value="<?= $d['id'] ?>"
            >

            <label class="form-label">
                Observation / motif
            </label>

            <textarea
                name="commentaire"
                class="form-control mb-2"
                rows="3"
                placeholder="Précisez votre observation..."
            ></textarea>

            <div class="d-grid gap-2">

                <button
                    name="statut"
                    value="A_COMPLETER"
                    class="btn btn-outline-warning"
                >
                    <i class="bi bi-pencil-square me-1"></i>
                    Demander un complément
                </button>

                <button
                    name="statut"
                    value="REJETEE"
                    class="btn btn-outline-danger"
                    onclick="return confirm(
                        'Rejeter cette demande ?'
                    )"
                >
                    <i class="bi bi-x-circle me-1"></i>
                    Rejeter
                </button>

            </div>

        </form>


    <?php elseif($d['statut']==='VALIDEE'): ?>


        <!-- ADHÉSION VALIDÉE -->

        <div class="registration-info">

            <i class="bi bi-check-circle"></i>

            <div>
                <strong>Adhésion validée</strong>

                <p>
                    L'établissement possède maintenant
                    un espace STAGIA-RDC.
                </p>
            </div>

        </div>


        <!-- COMPTE ADMINISTRATEUR -->

        <?php if($admin): ?>

        <div class="status-panel mt-3">

            <span>Compte administrateur</span>

            <strong>
                <?= htmlspecialchars($admin['identifiant']) ?>
            </strong>

            <small>
                <?= htmlspecialchars($admin['email']??'') ?>
            </small>


            <?php if($admin['statut_compte']==='ACTIF'): ?>

                <div class="mt-2 text-success small">
                    <i class="bi bi-check-circle-fill me-1"></i>
                    Compte activé
                </div>

                <?php if($admin['activated_at']): ?>

                    <small>
                        Activé le
                        <?= date(
                            'd/m/Y à H:i',
                            strtotime($admin['activated_at'])
                        ) ?>
                    </small>

                <?php endif; ?>


            <?php elseif($admin['statut_compte']==='A_ACTIVER'): ?>

                <div class="mt-2 text-warning small">
                    <i class="bi bi-clock me-1"></i>
                    En attente d'activation
                </div>

                <?php if($admin['activation_expire_at']): ?>

                    <small>
                        Invitation valable jusqu'au
                        <?= date(
                            'd/m/Y à H:i',
                            strtotime($admin['activation_expire_at'])
                        ) ?>
                    </small>

                <?php endif; ?>


            <?php elseif($admin['statut_compte']==='SUSPENDU'): ?>

                <div class="mt-2 text-danger small">
                    <i class="bi bi-pause-circle me-1"></i>
                    Compte suspendu
                </div>

            <?php endif; ?>

        </div>


        <!-- RENVOYER INVITATION -->

        <?php if($admin['statut_compte']==='A_ACTIVER'): ?>

        <form
            action="<?= BASE_URL ?>/actions/adhesion/resend-activation.php"
            method="POST"
            class="mt-3"
        >

            <input
                type="hidden"
                name="csrf"
                value="<?= $_SESSION['csrf'] ?>"
            >

            <input
                type="hidden"
                name="id"
                value="<?= $d['id'] ?>"
            >

            <button
                class="btn btn-outline-primary w-100"
                onclick="return confirm(
                    'Créer et envoyer un nouveau lien d’activation ?'
                )"
            >
                <i class="bi bi-envelope-arrow-up me-1"></i>
                Renvoyer l'invitation
            </button>

        </form>

        <?php endif; ?>


        <?php else: ?>

            <div class="alert alert-warning mt-3 mb-0 small">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Aucun compte administrateur n'est associé à cette demande.
            </div>

        <?php endif; ?>


    <?php else: ?>


        <!-- DEMANDE REJETÉE -->

        <div class="registration-info">

            <i class="bi bi-x-circle"></i>

            <div>
                <strong>Demande rejetée</strong>

                <p>
                    <?= htmlspecialchars(
                        $d['commentaire_admin']
                        ?: 'Aucun motif renseigné.'
                    ) ?>
                </p>
            </div>

        </div>


    <?php endif; ?>

    </div>
</div>


<!-- =========================================================
     INFORMATIONS DEMANDE
========================================================= -->
<div class="dashboard-card detail-card">

    <div class="p-3">

        <div class="status-panel">

            <span>Référence</span>

            <strong>
                <?= htmlspecialchars($d['reference']) ?>
            </strong>

            <small>
                Soumise le
                <?= date(
                    'd/m/Y à H:i',
                    strtotime($d['created_at'])
                ) ?>
            </small>

        </div>

    </div>

</div>


</div>
</div>

</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
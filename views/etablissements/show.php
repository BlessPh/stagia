<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);

$id=(int)($_GET['id']??0);

$stmt=$pdo->prepare("
    SELECT *
    FROM etablissements
    WHERE id=?
    LIMIT 1
");
$stmt->execute([$id]);

$e=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$e){
    http_response_code(404);
    exit('Établissement introuvable.');
}

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

function h($value):string{
    return htmlspecialchars(
        (string)($value??''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$pageTitle=$e['nom'];
$activePage='etablissements';

$types=[
    'UNIVERSITE'=>'Université',
    'INSTITUT_SUPERIEUR'=>'Institut supérieur',
    'ECOLE_PROFESSIONNELLE'=>'École professionnelle',
    'CENTRE_FORMATION'=>'Centre de formation',
    'ENTREPRISE'=>'Entreprise',
    'MINISTERE'=>'Ministère',
    'HOPITAL'=>'Hôpital',
    'ONG'=>'ONG',
    'SOCIETE_PRIVEE'=>'Société privée',
    'AUTRE'=>'Autre'
];

$badges=[
    'EN_ATTENTE'=>['warning','En attente'],
    'VALIDE'=>['success','Validé'],
    'REJETE'=>['danger','Rejeté'],
    'SUSPENDU'=>['secondary','Suspendu']
];

[$badge,$statutLabel]=
    $badges[$e['statut']]
    ??['secondary',$e['statut']];

$hasDocument=
    !empty($e['piece_justificative']);

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
            Établissements
        </a>

        <h1>
            <?= h($e['nom']) ?>
        </h1>

        <p>
            <?= h($e['code']) ?>
            ·
            <?= h(
                $types[$e['type_etablissement']]
                ??$e['type_etablissement']
            ) ?>
        </p>

    </div>

    <span class="badge text-bg-<?= h($badge) ?> detail-status">
        <?= h($statutLabel) ?>
    </span>

</div>


<?php if(isset($_GET['updated'])): ?>

<div class="alert alert-success alert-dismissible fade show">

    <i class="bi bi-check-circle me-2"></i>

    Statut mis à jour avec succès.

    <button
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php endif; ?>


<div class="row g-3">

<!-- =========================================================
     COLONNE GAUCHE
========================================================= -->
<div class="col-lg-8">


<!-- INFORMATIONS -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">

        <i class="bi bi-building"></i>

        <div>
            <h5>Informations générales</h5>
            <p>Identification de la structure.</p>
        </div>

    </div>


    <div class="detail-grid">

        <div>
            <span>Nom officiel</span>
            <strong>
                <?= h($e['nom']) ?>
            </strong>
        </div>


        <div>
            <span>Code</span>
            <strong>
                <?= h($e['code']) ?>
            </strong>
        </div>


        <div>
            <span>Type</span>
            <strong>
                <?= h(
                    $types[$e['type_etablissement']]
                    ??'-'
                ) ?>
            </strong>
        </div>


        <div>
            <span>N° d'agrément</span>
            <strong>
                <?= h(
                    $e['numero_agrement']
                    ?:'Non renseigné'
                ) ?>
            </strong>
        </div>

    </div>

</div>


<!-- LOCALISATION -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">

        <i class="bi bi-geo-alt"></i>

        <div>
            <h5>Localisation</h5>
            <p>Adresse de l'établissement.</p>
        </div>

    </div>


    <div class="detail-grid">

        <div>
            <span>Province</span>
            <strong>
                <?= h(
                    $e['province']
                    ?:'Non renseignée'
                ) ?>
            </strong>
        </div>


        <div>
            <span>Ville / Territoire</span>
            <strong>
                <?= h(
                    $e['ville']
                    ?:'Non renseigné'
                ) ?>
            </strong>
        </div>


        <div class="detail-full">

            <span>Adresse</span>

            <strong>
                <?= h(
                    $e['adresse']
                    ?:'Non renseignée'
                ) ?>
            </strong>

        </div>

    </div>

</div>


<!-- COORDONNÉES -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">

        <i class="bi bi-telephone"></i>

        <div>
            <h5>Coordonnées</h5>
            <p>
                Informations permettant de contacter la structure.
            </p>
        </div>

    </div>


    <div class="detail-grid">

        <div>

            <span>Téléphone</span>

            <strong>
                <?= h(
                    $e['telephone']
                    ?:'Non renseigné'
                ) ?>
            </strong>

        </div>


        <div>

            <span>Adresse e-mail</span>

            <strong>
                <?= h(
                    $e['email']
                    ?:'Non renseignée'
                ) ?>
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
                Document officiel fourni lors de
                l'enregistrement de l'établissement.
            </p>
        </div>

    </div>


    <div class="p-3">

        <?php if($hasDocument): ?>

            <div
                class="d-flex
                       justify-content-between
                       align-items-center
                       flex-wrap
                       gap-3
                       border
                       rounded-3
                       p-3">

                <div class="d-flex align-items-center gap-3">

                    <div
                        class="stagia-kpi-icon kpi-orange">

                        <i class="bi bi-file-earmark-pdf"></i>

                    </div>


                    <div>

                        <strong class="d-block">
                            Document justificatif
                        </strong>

                        <small class="text-muted">
                            Agrément, arrêté, RCCM ou
                            autorisation de fonctionnement.
                        </small>

                    </div>

                </div>


                <a
                    href="<?= BASE_URL ?>/actions/etablissements/document.php?id=<?= (int)$e['id'] ?>"
                    target="_blank"
                    rel="noopener"
                    class="btn btn-outline-primary">

                    <i class="bi bi-eye me-1"></i>

                    Voir le document

                </a>

            </div>

        <?php else: ?>

            <div class="alert alert-light border mb-0">

                <i class="bi bi-exclamation-circle me-2"></i>

                Aucune pièce justificative n'est enregistrée
                pour cet établissement.

            </div>

        <?php endif; ?>

    </div>

</div>

</div>


<!-- =========================================================
     COLONNE DROITE
========================================================= -->
<div class="col-lg-4">


<!-- STATUT -->
<div class="dashboard-card detail-card">

    <div class="form-section-title">

        <i class="bi bi-shield-check"></i>

        <div>
            <h5>Validation administrative</h5>
            <p>État de l'établissement.</p>
        </div>

    </div>


    <div class="p-3">

        <div class="status-panel">

            <span>Statut actuel</span>

            <strong class="text-<?= h($badge) ?>">
                <?= h($statutLabel) ?>
            </strong>

            <small>
                Enregistré le
                <?= date(
                    'd/m/Y',
                    strtotime($e['created_at'])
                ) ?>
            </small>

        </div>


        <?php if(
            ($_SESSION['role_code']??'')
            ==='SUPER_ADMIN'
        ): ?>


            <?php if(
                in_array(
                    $e['statut'],
                    ['EN_ATTENTE','REJETE'],
                    true
                )
            ): ?>

            <form
                action="<?= BASE_URL ?>/actions/etablissements/statut.php"
                method="POST"
                class="mt-3">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($_SESSION['csrf']) ?>">

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$e['id'] ?>">

                <input
                    type="hidden"
                    name="statut"
                    value="VALIDE">


                <button
                    class="btn btn-success w-100"
                    onclick="return confirm('Valider cet établissement ?')">

                    <i class="bi bi-check-circle me-1"></i>

                    Valider l'établissement

                </button>

            </form>

            <?php endif; ?>


            <?php if($e['statut']==='EN_ATTENTE'): ?>

            <form
                action="<?= BASE_URL ?>/actions/etablissements/statut.php"
                method="POST"
                class="mt-2">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($_SESSION['csrf']) ?>">

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$e['id'] ?>">

                <input
                    type="hidden"
                    name="statut"
                    value="REJETE">


                <button
                    class="btn btn-outline-danger w-100"
                    onclick="return confirm('Rejeter cet établissement ?')">

                    <i class="bi bi-x-circle me-1"></i>

                    Rejeter

                </button>

            </form>

            <?php endif; ?>


            <?php if($e['statut']==='VALIDE'): ?>

            <form
                action="<?= BASE_URL ?>/actions/etablissements/statut.php"
                method="POST"
                class="mt-3">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($_SESSION['csrf']) ?>">

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$e['id'] ?>">

                <input
                    type="hidden"
                    name="statut"
                    value="SUSPENDU">


                <button
                    class="btn btn-outline-secondary w-100"
                    onclick="return confirm('Suspendre cet établissement ?')">

                    <i class="bi bi-pause-circle me-1"></i>

                    Suspendre

                </button>

            </form>

            <?php endif; ?>


            <?php if($e['statut']==='SUSPENDU'): ?>

            <form
                action="<?= BASE_URL ?>/actions/etablissements/statut.php"
                method="POST"
                class="mt-3">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= h($_SESSION['csrf']) ?>">

                <input
                    type="hidden"
                    name="id"
                    value="<?= (int)$e['id'] ?>">

                <input
                    type="hidden"
                    name="statut"
                    value="VALIDE">


                <button
                    class="btn btn-success w-100">

                    <i class="bi bi-play-circle me-1"></i>

                    Réactiver

                </button>

            </form>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</div>


<!-- ACTIONS -->
<div class="dashboard-card detail-card">

    <div class="p-3 d-grid gap-2">

        <a
            href="edit.php?id=<?= (int)$e['id'] ?>"
            class="btn btn-light border">

            <i class="bi bi-pencil me-1"></i>

            Modifier les informations

        </a>


        <?php if($hasDocument): ?>

        <a
            href="<?= BASE_URL ?>/actions/etablissements/document.php?id=<?= (int)$e['id'] ?>"
            target="_blank"
            rel="noopener"
            class="btn btn-light border">

            <i class="bi bi-file-earmark-text me-1"></i>

            Voir la pièce justificative

        </a>

        <?php endif; ?>


        <a
            href="index.php"
            class="btn btn-light border">

            <i class="bi bi-list me-1"></i>

            Retour à la liste

        </a>

    </div>

</div>

</div>

</div>

</main>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
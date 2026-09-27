<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

if(($_SESSION['role_code']??'')!=='STAGIAIRE'){
    http_response_code(403);
    exit('Accès refusé.');
}

$userId=(int)($_SESSION['user_id']??0);

$stmt=$pdo->prepare("
    SELECT *
    FROM student_profiles
    WHERE user_id=?
    LIMIT 1
");
$stmt->execute([$userId]);
$p=$stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle='Mon profil';
$activePage='student-profile';

function e($v){
    return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8');
}

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="page-heading">
    <div>
        <h1>Mon profil</h1>
        <p>Consultez vos informations personnelles dans STAGIA-RDC.</p>
    </div>
</div>

<?php if(!$p): ?>

<div class="dashboard-card p-4">
    <div class="alert alert-warning mb-0">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Aucun profil stagiaire n'est associé à votre compte.
    </div>
</div>

<?php else: ?>

<?php
$nomComplet=trim(
    ($p['prenom']??'').' '.
    ($p['nom']??'').' '.
    ($p['postnom']??'')
);

$initiale=strtoupper(
    substr($p['prenom']?:$p['nom']?:'S',0,1)
);

$sexe=$p['sexe']==='M'
    ?'Masculin'
    :($p['sexe']==='F'?'Féminin':'Non renseigné');
?>

<div class="row g-3">

<!-- PROFIL -->
<div class="col-lg-4">

    <div class="dashboard-card detail-card">

        <div class="p-4 text-center">

            <?php if(!empty($p['photo'])): ?>

                <img
                    src="<?= BASE_URL.'/'.e($p['photo']) ?>"
                    alt="Photo"
                    class="rounded-circle mb-3"
                    style="width:120px;height:120px;object-fit:cover"
                >

            <?php else: ?>

                <div class="rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center"
                     style="width:120px;height:120px;background:#fff1e8;color:#ff6b00;font-size:40px;font-weight:700">
                    <?= e($initiale) ?>
                </div>

            <?php endif; ?>

            <h4 class="mb-1">
                <?= e($nomComplet) ?>
            </h4>

            <p class="text-muted mb-3">
                Stagiaire STAGIA-RDC
            </p>

            <span class="badge <?= $p['statut']==='ACTIF'?'text-bg-success':'text-bg-secondary' ?>">
                <?= e($p['statut']) ?>
            </span>

        </div>

        <div class="border-top p-3">

            <div class="status-panel mb-2">
                <span>Code STAGIA</span>
                <strong><?= e($p['stagia_code']) ?></strong>
            </div>

            <div class="status-panel">
                <span>Date d'inscription</span>
                <strong>
                    <?= !empty($p['created_at'])
                        ?date('d/m/Y',strtotime($p['created_at']))
                        :'-' ?>
                </strong>
            </div>

        </div>

    </div>

</div>


<!-- INFORMATIONS -->
<div class="col-lg-8">

    <div class="dashboard-card detail-card">

        <div class="form-section-title">
            <i class="bi bi-person-vcard"></i>

            <div>
                <h5>Informations personnelles</h5>
                <p>Informations d'identification du stagiaire.</p>
            </div>
        </div>

        <div class="detail-grid">

            <div>
                <span>Nom</span>
                <strong><?= e($p['nom']) ?></strong>
            </div>

            <div>
                <span>Post-nom</span>
                <strong><?= e($p['postnom']?:'-') ?></strong>
            </div>

            <div>
                <span>Prénom</span>
                <strong><?= e($p['prenom']?:'-') ?></strong>
            </div>

            <div>
                <span>Sexe</span>
                <strong><?= e($sexe) ?></strong>
            </div>

            <div>
                <span>Date de naissance</span>
                <strong>
                    <?= !empty($p['date_naissance'])
                        ?date('d/m/Y',strtotime($p['date_naissance']))
                        :'Non renseignée' ?>
                </strong>
            </div>

            <div>
                <span>Code STAGIA</span>
                <strong><?= e($p['stagia_code']) ?></strong>
            </div>

        </div>

    </div>


    <div class="dashboard-card detail-card">

        <div class="form-section-title">
            <i class="bi bi-telephone"></i>

            <div>
                <h5>Coordonnées</h5>
                <p>Informations utilisées pour vous contacter.</p>
            </div>
        </div>

        <div class="detail-grid">

            <div>
                <span>Adresse e-mail</span>
                <strong>
                    <?= e($p['email']?:'Non renseignée') ?>
                </strong>
            </div>

            <div>
                <span>Téléphone</span>
                <strong>
                    <?= e($p['telephone']?:'Non renseigné') ?>
                </strong>
            </div>

        </div>

    </div>


    <div class="dashboard-card detail-card">

        <div class="form-section-title">
            <i class="bi bi-shield-check"></i>

            <div>
                <h5>État du profil</h5>
                <p>Informations administratives STAGIA-RDC.</p>
            </div>
        </div>

        <div class="detail-grid">

            <div>
                <span>Statut</span>

                <strong class="<?= $p['statut']==='ACTIF'?'text-success':'text-muted' ?>">
                    <?= e($p['statut']) ?>
                </strong>
            </div>

            <div>
                <span>Dernière mise à jour</span>

                <strong>
                    <?= !empty($p['updated_at'])
                        ?date('d/m/Y à H:i',strtotime($p['updated_at']))
                        :'-' ?>
                </strong>
            </div>

        </div>

    </div>

</div>

</div>

<?php endif; ?>

</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
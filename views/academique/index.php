<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) exit('Aucun établissement associé.');

function totalAcad(PDO $pdo,string $table,int $etablissementId){
    $allowed=['annees_academiques','facultes','departements','filieres','promotions'];
    if(!in_array($table,$allowed,true)) return 0;
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE etablissement_id=?");
    $stmt->execute([$etablissementId]);
    return (int)$stmt->fetchColumn();
}

$stats=[
    'annees'=>totalAcad($pdo,'annees_academiques',$etablissementId),
    'facultes'=>totalAcad($pdo,'facultes',$etablissementId),
    'departements'=>totalAcad($pdo,'departements',$etablissementId),
    'filieres'=>totalAcad($pdo,'filieres',$etablissementId),
    'promotions'=>totalAcad($pdo,'promotions',$etablissementId)
];

$pageTitle='Organisation académique';
$activePage='academique';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="page-heading">
    <div>
        <h1>Organisation académique</h1>
        <p>Configurez la structure académique de votre établissement.</p>
    </div>
</div>

<div class="academic-flow">
    <div><span>1</span><strong>Année académique</strong></div>
    <i class="bi bi-chevron-right"></i>
    <div><span>2</span><strong>Facultés</strong></div>
    <i class="bi bi-chevron-right"></i>
    <div><span>3</span><strong>Départements</strong></div>
    <i class="bi bi-chevron-right"></i>
    <div><span>4</span><strong>Filières</strong></div>
    <i class="bi bi-chevron-right"></i>
    <div><span>5</span><strong>Promotions</strong></div>
</div>

<div class="row g-3 mt-1">

    <div class="col-md-6 col-xl">
        <a href="annees.php" class="academic-card">
            <div class="academic-icon"><i class="bi bi-calendar3"></i></div>
            <strong>Années académiques</strong>
            <span><?= $stats['annees'] ?> enregistrée<?= $stats['annees']>1?'s':'' ?></span>
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="col-md-6 col-xl">
        <a href="facultes.php" class="academic-card">
            <div class="academic-icon"><i class="bi bi-bank"></i></div>
            <strong>Facultés</strong>
            <span><?= $stats['facultes'] ?> enregistrée<?= $stats['facultes']>1?'s':'' ?></span>
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="col-md-6 col-xl">
        <a href="departements.php" class="academic-card">
            <div class="academic-icon"><i class="bi bi-diagram-2"></i></div>
            <strong>Départements</strong>
            <span><?= $stats['departements'] ?> enregistré<?= $stats['departements']>1?'s':'' ?></span>
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="col-md-6 col-xl">
        <a href="filieres.php" class="academic-card">
            <div class="academic-icon"><i class="bi bi-mortarboard"></i></div>
            <strong>Filières</strong>
            <span><?= $stats['filieres'] ?> enregistrée<?= $stats['filieres']>1?'s':'' ?></span>
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

    <div class="col-md-6 col-xl">
        <a href="promotions.php" class="academic-card">
            <div class="academic-icon"><i class="bi bi-people"></i></div>
            <strong>Promotions</strong>
            <span><?= $stats['promotions'] ?> enregistrée<?= $stats['promotions']>1?'s':'' ?></span>
            <i class="bi bi-arrow-right"></i>
        </a>
    </div>

</div>

<div class="dashboard-card mt-3 academic-info">
    <div>
        <i class="bi bi-info-circle"></i>
    </div>

    <div>
        <h5>Avant d'inscrire les étudiants</h5>
        <p>
            Configurez au minimum une filière et une promotion. Les étudiants
            seront ensuite rattachés à leur filière, promotion et année académique.
        </p>
    </div>
</div>

</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
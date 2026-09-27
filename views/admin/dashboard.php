<?php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';

require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';


/* =========================================================
   SÉCURITÉ
========================================================= */

requireRole([
    'SUPER_ADMIN'
]);


/* =========================================================
   PAGE
========================================================= */

$pageTitle  = 'Tableau de bord';
$activePage = 'dashboard';


/* =========================================================
   STATISTIQUES
========================================================= */

$stats = $pdo->query("
    SELECT

        (
            SELECT COUNT(*)
            FROM etablissements
        ) AS etablissements,

        (
            SELECT COUNT(*)
            FROM demandes_adhesion
            WHERE statut IN(
                'SOUMISE',
                'EN_EXAMEN',
                'A_COMPLETER'
            )
        ) AS adhesions,

        (
            SELECT COUNT(*)
            FROM etablissements
            WHERE type_etablissement='HOPITAL'
        ) AS hopitaux,

        (
            SELECT COUNT(*)
            FROM etablissements
            WHERE statut IN(
                'VALIDE',
                'ACTIF'
            )
        ) AS actifs

")->fetch(PDO::FETCH_ASSOC);


$nbEtablissements       = (int)$stats['etablissements'];
$nbAdhesions            = (int)$stats['adhesions'];
$nbHopitaux              = (int)$stats['hopitaux'];
$nbEtablissementsActifs = (int)$stats['actifs'];


/* =========================================================
   HEADER
========================================================= */

require_once __DIR__.'/../../includes/app-header.php';

?>

<main class="dashboard-content">

<!-- ENTÊTE -->
<div class="stagia-page-head">

    <div>
        <h1>Tableau de bord</h1>

        <p>
            Vue générale de l'administration nationale STAGIA-RDC.
        </p>
    </div>

</div>


<!-- KPI -->
<div class="stagia-kpi-grid">

    <!-- Établissements -->
    <div class="stagia-kpi-card">

        <div>
            <span>ÉTABLISSEMENTS</span>

            <strong>
                <?= $nbEtablissements ?>
            </strong>

            <small>
                Structures référencées
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-buildings"></i>
        </div>

    </div>


    <!-- Adhésions -->
    <div class="stagia-kpi-card">

        <div>
            <span>ADHÉSIONS EN ATTENTE</span>

            <strong>
                <?= $nbAdhesions ?>
            </strong>

            <small>
                Dossiers à traiter
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-inbox"></i>
        </div>

    </div>


    <!-- Hôpitaux -->
    <div class="stagia-kpi-card">

        <div>
            <span>HÔPITAUX</span>

            <strong>
                <?= $nbHopitaux ?>
            </strong>

            <small>
                Établissements de santé
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-hospital"></i>
        </div>

    </div>


    <!-- Actifs -->
    <div class="stagia-kpi-card">

        <div>
            <span>STRUCTURES ACTIVES</span>

            <strong>
                <?= $nbEtablissementsActifs ?>
            </strong>

            <small>
                Espaces validés ou actifs
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>

    </div>

</div>


<!-- ACCÈS RAPIDES -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>
            <h5 class="mb-1">
                Accès rapides
            </h5>

            <small class="text-muted">
                Principaux modules de l'administration nationale.
            </small>
        </div>

    </div>


    <div class="p-4">

        <div class="row g-3">

            <!-- Établissements -->
            <div class="col-md-6 col-xl-3">

                <a href="<?= BASE_URL ?>/views/etablissements/index.php"
                   class="text-decoration-none">

                    <div class="border rounded-3 p-4 h-100">

                        <div class="stagia-kpi-icon kpi-blue mb-3">
                            <i class="bi bi-buildings"></i>
                        </div>

                        <h6 class="text-dark">
                            Établissements
                        </h6>

                        <p class="small text-muted mb-0">
                            Consulter et gérer les structures STAGIA.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Hôpitaux -->
            <div class="col-md-6 col-xl-3">

                <a href="<?= BASE_URL ?>/views/admin/hopitaux.php"
                   class="text-decoration-none">

                    <div class="border rounded-3 p-4 h-100">

                        <div class="stagia-kpi-icon kpi-green mb-3">
                            <i class="bi bi-hospital"></i>
                        </div>

                        <h6 class="text-dark">
                            Hôpitaux
                        </h6>

                        <p class="small text-muted mb-0">
                            Référencer les établissements de santé.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Adhésions -->
            <div class="col-md-6 col-xl-3">

                <a href="<?= BASE_URL ?>/views/adhesions/index.php"
                   class="text-decoration-none">

                    <div class="border rounded-3 p-4 h-100">

                        <div class="stagia-kpi-icon kpi-orange mb-3">
                            <i class="bi bi-inbox"></i>
                        </div>

                        <h6 class="text-dark">
                            Demandes d'adhésion
                        </h6>

                        <p class="small text-muted mb-0">
                            Examiner les demandes institutionnelles.
                        </p>

                    </div>

                </a>

            </div>


            <!-- Stages -->
            <div class="col-md-6 col-xl-3">

                <a href="<?= BASE_URL ?>/views/stages/campagnes.php"
                   class="text-decoration-none">

                    <div class="border rounded-3 p-4 h-100">

                        <div class="stagia-kpi-icon kpi-purple mb-3">
                            <i class="bi bi-briefcase"></i>
                        </div>

                        <h6 class="text-dark">
                            Stages
                        </h6>

                        <p class="small text-muted mb-0">
                            Supervision des campagnes de stage.
                        </p>

                    </div>

                </a>

            </div>

        </div>

    </div>

</div>

</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
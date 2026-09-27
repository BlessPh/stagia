<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

$userId=(int)$_SESSION['user_id'];

/* Profil étudiant */
$stmt=$pdo->prepare("
    SELECT id,stagia_code,nom,postnom,prenom,email,telephone,statut
    FROM student_profiles
    WHERE user_id=?
    LIMIT 1
");
$stmt->execute([$userId]);
$student=$stmt->fetch();

if(!$student)
    exit('Profil étudiant introuvable.');

$studentId=(int)$student['id'];

/* Inscription académique actuelle */
$stmt=$pdo->prepare("
    SELECT
        se.id enrollment_id,
        se.matricule,
        se.statut enrollment_statut,
        e.nom etablissement,
        sae.id academic_enrollment_id,
        sae.statut academic_statut,
        aa.libelle annee_academique,
        p.nom promotion,
        f.nom filiere

    FROM student_enrollments se

    JOIN etablissements e
      ON e.id=se.etablissement_id

    LEFT JOIN student_academic_enrollments sae
      ON sae.enrollment_id=se.id

    LEFT JOIN annees_academiques aa
      ON aa.id=sae.annee_academique_id

    LEFT JOIN promotions p
      ON p.id=sae.promotion_id

    LEFT JOIN filieres f
      ON f.id=p.filiere_id

    WHERE se.student_id=?

    ORDER BY
        (sae.statut='EN_COURS') DESC,
        sae.id DESC

    LIMIT 1
");
$stmt->execute([$studentId]);
$academic=$stmt->fetch();


/* Documents */
$stmt=$pdo->prepare("
    SELECT COUNT(*)
    FROM student_documents
    WHERE student_id=?
      AND statut='ACTIF'
");
$stmt->execute([$studentId]);
$nbDocuments=(int)$stmt->fetchColumn();


/* Notes */
$stmt=$pdo->prepare("
    SELECT COUNT(*)

    FROM student_notes sn

    JOIN student_academic_enrollments sae
      ON sae.id=sn.academic_enrollment_id

    JOIN student_enrollments se
      ON se.id=sae.enrollment_id

    WHERE se.student_id=?
");
$stmt->execute([$studentId]);
$nbNotes=(int)$stmt->fetchColumn();


/* Candidatures de stage */
$stmt=$pdo->prepare("
    SELECT COUNT(*)

    FROM stage_applications sa

    JOIN student_academic_enrollments sae
      ON sae.id=sa.academic_enrollment_id

    JOIN student_enrollments se
      ON se.id=sae.enrollment_id

    WHERE se.student_id=?
");
$stmt->execute([$studentId]);
$nbCandidatures=(int)$stmt->fetchColumn();


/* Réservations actives */
$stmt=$pdo->prepare("
    SELECT COUNT(*)

    FROM stage_reservations sr

    JOIN stage_applications sa
      ON sa.id=sr.application_id

    JOIN student_academic_enrollments sae
      ON sae.id=sa.academic_enrollment_id

    JOIN student_enrollments se
      ON se.id=sae.enrollment_id

    WHERE se.student_id=?
      AND sr.statut IN(
          'RESERVEE_TEMPORAIREMENT',
          'EN_ATTENTE_PAIEMENT',
          'CONFIRMEE'
      )
");
$stmt->execute([$studentId]);
$nbReservations=(int)$stmt->fetchColumn();


$pageTitle='Tableau de bord étudiant';
$activePage='student-dashboard';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">

    <div>

        <h1>
            Bonjour,
            <?= htmlspecialchars($student['prenom'] ?: $student['nom']) ?>
        </h1>

        <p>
            Bienvenue dans votre espace personnel STAGIA-RDC.
        </p>

    </div>

    <span class="badge bg-success px-3 py-2">

        <i class="bi bi-person-check me-1"></i>

        <?= htmlspecialchars($student['stagia_code']) ?>

    </span>

</div>


<!-- PROFIL ACADÉMIQUE -->
<div class="stagia-list-card mb-4">

    <div class="p-4">

        <div class="row align-items-center g-3">

            <div class="col">

                <small class="text-muted">
                    Étudiant
                </small>

                <h4 class="mb-1">

                    <?= htmlspecialchars(
                        trim(
                            $student['nom'].' '.
                            ($student['postnom']??'').' '.
                            ($student['prenom']??'')
                        )
                    ) ?>

                </h4>

                <div class="text-muted">

                    <?= htmlspecialchars(
                        $academic['etablissement']??'-'
                    ) ?>

                </div>

            </div>


            <div class="col-md-auto">

                <small class="text-muted">
                    Matricule
                </small>

                <div class="fw-bold">
                    <?= htmlspecialchars(
                        $academic['matricule']??'-'
                    ) ?>
                </div>

            </div>


            <div class="col-md-auto">

                <small class="text-muted">
                    Promotion
                </small>

                <div class="fw-bold">
                    <?= htmlspecialchars(
                        $academic['promotion']??'-'
                    ) ?>
                </div>

            </div>


            <div class="col-md-auto">

                <small class="text-muted">
                    Année académique
                </small>

                <div class="fw-bold">
                    <?= htmlspecialchars(
                        $academic['annee_academique']??'-'
                    ) ?>
                </div>

            </div>

        </div>

    </div>

</div>


<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">

        <div>
            <span>DOCUMENTS</span>
            <strong><?= $nbDocuments ?></strong>
            <small>Documents dans mon dossier</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-file-earmark-text"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>NOTES</span>
            <strong><?= $nbNotes ?></strong>
            <small>Évaluations enregistrées</small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-journal-check"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>CANDIDATURES</span>
            <strong><?= $nbCandidatures ?></strong>
            <small>Demandes de stage</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-send"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>RÉSERVATIONS</span>
            <strong><?= $nbReservations ?></strong>
            <small>Places de stage actives</small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-calendar-check"></i>
        </div>

    </div>

</div>


<!-- ACTIONS -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>

            <h5 class="mb-1">
                Mon espace
            </h5>

            <small class="text-muted">
                Accédez rapidement à votre dossier et à vos stages.
            </small>

        </div>

    </div>


    <div class="p-4">

        <div class="row g-3">


            <div class="col-md-6 col-xl-4">

                <a href="<?= BASE_URL ?>/views/espace-etudiant/stages.php"
                   class="text-decoration-none">

                    <div class="border rounded-3 p-4 h-100">

                        <div class="stagia-kpi-icon kpi-orange mb-3">
                            <i class="bi bi-hospital"></i>
                        </div>

                        <h6 class="text-dark">
                            Choisir mon lieu de stage
                        </h6>

                        <p class="small text-muted mb-0">
                            Consultez les hôpitaux disponibles
                            et réservez votre place.
                        </p>

                    </div>

                </a>

            </div>


            <div class="col-md-6 col-xl-4">

                <div class="border rounded-3 p-4 h-100">

                    <div class="stagia-kpi-icon kpi-blue mb-3">
                        <i class="bi bi-folder2-open"></i>
                    </div>

                    <h6>Mon dossier</h6>

                    <p class="small text-muted mb-0">
                        Consultez votre identité,
                        parcours et documents.
                    </p>

                </div>

            </div>


            <div class="col-md-6 col-xl-4">

                <div class="border rounded-3 p-4 h-100">

                    <div class="stagia-kpi-icon kpi-green mb-3">
                        <i class="bi bi-briefcase"></i>
                    </div>

                    <h6>Mes stages</h6>

                    <p class="small text-muted mb-0">
                        Suivez vos candidatures,
                        réservations et stages.
                    </p>

                </div>

            </div>


        </div>

    </div>

</div>

</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
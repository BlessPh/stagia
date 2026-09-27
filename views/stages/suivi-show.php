<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

$etablissementId=currentEtablissementId($pdo);
$assignmentId=(int)($_GET['id']??0);

if(!$etablissementId)
    exit('Aucun établissement associé.');

if($assignmentId<=0)
    exit('Stage invalide.');


/* =========================================================
   STAGE
========================================================= */
$stmt=$pdo->prepare("
    SELECT
        a.id AS assignment_id,
        a.statut AS assignment_statut,
        a.date_debut,
        a.date_fin,
        a.ended_at,

        app.academic_enrollment_id,

        COALESCE(
            spl.student_id,
            0
        ) AS student_id,

        COALESCE(
            sp.stagia_code,
            CONCAT(
                'INS-',
                LPAD(app.academic_enrollment_id,8,'0')
            )
        ) AS stagia_code,

        COALESCE(
            NULLIF(TRIM(sp.nom),''),
            CONCAT(
                'Étudiant #',
                COALESCE(spl.student_id,app.academic_enrollment_id)
            )
        ) AS nom,

        COALESCE(sp.postnom,'') AS postnom,
        COALESCE(sp.prenom,'') AS prenom,

        c.titre AS campaign_title,
        c.code AS campaign_code,

        p.id AS promotion_id,

        h.nom AS host_name,
        h.ville AS host_ville,
        h.province AS host_province,

        hu.nom AS unit_name

    FROM stage_assignments a

    INNER JOIN stage_admissions ad
        ON ad.id=a.admission_id

    INNER JOIN stage_reservations sr
        ON sr.id=ad.reservation_id

    INNER JOIN stage_applications app
        ON app.id=sr.application_id

    INNER JOIN student_academic_enrollments sae
        ON sae.id=app.academic_enrollment_id

    INNER JOIN promotions p
        ON p.id=sae.promotion_id

    LEFT JOIN stage_placements spl
        ON spl.id=(
            SELECT MAX(spl2.id)
            FROM stage_placements spl2
            WHERE spl2.academic_enrollment_id=
                  app.academic_enrollment_id
        )

    LEFT JOIN student_profiles sp
        ON sp.id=spl.student_id

    INNER JOIN stage_campaigns c
        ON c.id=app.campaign_id

    INNER JOIN etablissements h
        ON h.id=a.host_etablissement_id

    LEFT JOIN host_units hu
        ON hu.id=a.host_unit_id

    WHERE a.id=?
      AND p.etablissement_id=?

    LIMIT 1
");

$stmt->execute([
    $assignmentId,
    $etablissementId
]);

$stage=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$stage){
    http_response_code(404);
    exit('Stage introuvable ou inaccessible.');
}


/* =========================================================
   ROTATIONS
========================================================= */
$stmt=$pdo->prepare("
    SELECT
        r.id,
        r.sequence_no,
        r.date_debut,
        r.date_fin,
        r.statut,
        r.started_at,
        r.ended_at,
        r.objectifs,
        r.observation,
        hu.nom AS unit_name

    FROM stage_rotations r

    LEFT JOIN host_units hu
        ON hu.id=r.host_unit_id

    WHERE r.assignment_id=?

    ORDER BY
        r.sequence_no,
        r.id
");

$stmt->execute([$assignmentId]);
$rotations=$stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   PRÉSENCES
========================================================= */
$stmt=$pdo->prepare("
    SELECT
        id,
        date_presence,
        heure_arrivee,
        heure_depart,
        statut,
        minutes_retard,
        source,
        justification,
        observation

    FROM stage_attendances

    WHERE assignment_id=?

    ORDER BY
        date_presence DESC,
        id DESC
");

$stmt->execute([$assignmentId]);
$attendances=$stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   JOURNAUX
========================================================= */
$stmt=$pdo->prepare("
    SELECT
        id,
        rotation_id,
        date_journal,
        resume_activites,
        apprentissages,
        difficultes,
        statut,
        commentaire_encadreur,
        validated_at

    FROM stage_logbook_entries

    WHERE assignment_id=?

    ORDER BY
        date_journal DESC,
        id DESC
");

$stmt->execute([$assignmentId]);
$journals=$stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   ÉVALUATIONS
========================================================= */
$stmt=$pdo->prepare("
    SELECT
        id,
        rotation_id,
        evaluator_role,
        type_evaluation,
        statut,
        note_finale,
        finalized_at

    FROM stage_evaluations

    WHERE assignment_id=?

    ORDER BY
        id DESC
");

$stmt->execute([$assignmentId]);
$evaluations=$stmt->fetchAll(PDO::FETCH_ASSOC);


/* =========================================================
   STATISTIQUES
========================================================= */
$totalRotations=count($rotations);

$rotationsTerminees=count(
    array_filter(
        $rotations,
        fn($r)=>$r['statut']==='TERMINEE'
    )
);

$totalPresences=count(
    array_filter(
        $attendances,
        fn($a)=>in_array(
            $a['statut'],
            ['PRESENT','RETARD','GARDE'],
            true
        )
    )
);

$totalAbsences=count(
    array_filter(
        $attendances,
        fn($a)=>$a['statut']==='ABSENT'
    )
);

$totalAttendance=count($attendances);

$tauxPresence=$totalAttendance>0
    ?round(
        ($totalPresences/$totalAttendance)*100,
        2
    )
    :null;

$journauxValides=count(
    array_filter(
        $journals,
        fn($j)=>$j['statut']==='VALIDE'
    )
);

$finalEvaluation=null;

foreach($evaluations as $evaluation){
    if(
        $evaluation['type_evaluation']==='FIN_ROTATION' &&
        $evaluation['statut']==='FINALISEE'
    ){
        $finalEvaluation=$evaluation;
        break;
    }
}


/* =========================================================
   HELPERS
========================================================= */
function h($v):string{
    return htmlspecialchars(
        (string)$v,
        ENT_QUOTES,
        'UTF-8'
    );
}

function dateFr($v):string{
    if(!$v)
        return '-';

    $time=strtotime($v);

    return $time
        ?date('d/m/Y',$time)
        :'-';
}

function dateTimeFr($v):string{
    if(!$v)
        return '-';

    $time=strtotime($v);

    return $time
        ?date('d/m/Y H:i',$time)
        :'-';
}

$pageTitle='Détail du suivi';
$activePage='stages-suivi';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.stage-detail-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:14px;
    margin-bottom:18px
}
.stage-detail-card{
    background:#fff;
    border:1px solid #e4e9ef;
    border-radius:12px;
    padding:16px
}
.stage-detail-card small{
    display:block;
    color:#64748b;
    margin-bottom:5px
}
.stage-detail-card strong{
    font-size:20px
}
.stage-section{
    background:#fff;
    border:1px solid #e4e9ef;
    border-radius:13px;
    margin-bottom:18px;
    overflow:hidden
}
.stage-section-head{
    padding:15px 18px;
    border-bottom:1px solid #edf0f3;
    display:flex;
    justify-content:space-between;
    align-items:center
}
.stage-section-body{
    padding:0
}
@media(max-width:900px){
    .stage-detail-grid{
        grid-template-columns:1fr 1fr
    }
}
@media(max-width:560px){
    .stage-detail-grid{
        grid-template-columns:1fr
    }
}
</style>

<main class="dashboard-content">

<div class="stagia-page-head">

    <div>
        <h1>Suivi détaillé du stage</h1>

        <p>
            <?=h(
                trim(
                    $stage['nom'].' '.
                    $stage['postnom'].' '.
                    $stage['prenom']
                )
            )?>
            • <?=h($stage['stagia_code'])?>
        </p>
    </div>

    <a href="<?=BASE_URL?>/views/stages/suivi.php"
       class="btn btn-light border">

        <i class="bi bi-arrow-left me-1"></i>
        Retour au suivi

    </a>

</div>


<!-- INFORMATIONS -->
<div class="stagia-list-card mb-3">

    <div class="p-3">

        <div class="row g-3">

            <div class="col-lg-3 col-md-6">
                <small class="text-muted">
                    Étudiant
                </small>

                <div class="fw-semibold">
                    <?=h(
                        trim(
                            $stage['nom'].' '.
                            $stage['postnom'].' '.
                            $stage['prenom']
                        )
                    )?>
                </div>

                <small>
                    <?=h($stage['stagia_code'])?>
                </small>
            </div>


            <div class="col-lg-3 col-md-6">
                <small class="text-muted">
                    Campagne
                </small>

                <div class="fw-semibold">
                    <?=h($stage['campaign_title'])?>
                </div>

                <small>
                    <?=h($stage['campaign_code'])?>
                </small>
            </div>


            <div class="col-lg-3 col-md-6">
                <small class="text-muted">
                    Établissement d'accueil
                </small>

                <div class="fw-semibold">
                    <?=h($stage['host_name'])?>
                </div>

                <small>
                    <?=h($stage['unit_name']?:'-')?>
                </small>
            </div>


            <div class="col-lg-3 col-md-6">
                <small class="text-muted">
                    Période
                </small>

                <div class="fw-semibold">
                    <?=dateFr($stage['date_debut'])?>
                    au
                    <?=dateFr($stage['date_fin'])?>
                </div>

                <span class="badge bg-secondary mt-1">
                    <?=h($stage['assignment_statut'])?>
                </span>
            </div>

        </div>

    </div>

</div>


<!-- KPI -->
<div class="stage-detail-grid">

    <div class="stage-detail-card">
        <small>Rotations terminées</small>

        <strong>
            <?=$rotationsTerminees?>
            /
            <?=$totalRotations?>
        </strong>
    </div>


    <div class="stage-detail-card">
        <small>Présence</small>

        <strong>
            <?=$tauxPresence!==null
                ?h(number_format($tauxPresence,2,',',' ')).' %'
                :'-'?>
        </strong>

        <div class="small text-muted mt-1">
            <?=$totalPresences?> présence(s)
            • <?=$totalAbsences?> absence(s)
        </div>
    </div>


    <div class="stage-detail-card">
        <small>Journaux validés</small>

        <strong>
            <?=$journauxValides?>
            /
            <?=count($journals)?>
        </strong>
    </div>


    <div class="stage-detail-card">
        <small>Évaluation finale</small>

        <strong>
            <?=$finalEvaluation &&
               $finalEvaluation['note_finale']!==null
                ?h(
                    number_format(
                        (float)$finalEvaluation['note_finale'],
                        2,
                        ',',
                        ' '
                    )
                ).' / 20'
                :'Non disponible'?>
        </strong>
    </div>

</div>


<!-- ROTATIONS -->
<div class="stage-section">

    <div class="stage-section-head">
        <strong>
            <i class="bi bi-arrow-repeat me-1"></i>
            Rotations
        </strong>

        <span class="badge bg-light text-dark">
            <?=count($rotations)?>
        </span>
    </div>

    <div class="stage-section-body table-responsive">

        <table class="table stagia-modern-table mb-0">

            <thead>
            <tr>
                <th>SÉQUENCE</th>
                <th>SERVICE</th>
                <th>DÉBUT</th>
                <th>FIN</th>
                <th>STATUT</th>
            </tr>
            </thead>

            <tbody>

            <?php if(!$rotations): ?>

                <tr>
                    <td colspan="5"
                        class="text-center py-4 text-muted">
                        Aucune rotation.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach($rotations as $r): ?>

                    <tr>
                        <td>
                            <?=h($r['sequence_no'])?>
                        </td>

                        <td>
                            <?=h($r['unit_name']?:'-')?>
                        </td>

                        <td>
                            <?=dateFr($r['date_debut'])?>
                        </td>

                        <td>
                            <?=dateFr($r['date_fin'])?>
                        </td>

                        <td>
                            <span class="badge bg-secondary">
                                <?=h($r['statut'])?>
                            </span>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- PRESENCES -->
<div class="stage-section">

    <div class="stage-section-head">
        <strong>
            <i class="bi bi-calendar-check me-1"></i>
            Présences
        </strong>

        <span class="badge bg-light text-dark">
            <?=count($attendances)?>
        </span>
    </div>

    <div class="stage-section-body table-responsive">

        <table class="table stagia-modern-table mb-0">

            <thead>
            <tr>
                <th>DATE</th>
                <th>ARRIVÉE</th>
                <th>DÉPART</th>
                <th>STATUT</th>
                <th>SOURCE</th>
            </tr>
            </thead>

            <tbody>

            <?php if(!$attendances): ?>

                <tr>
                    <td colspan="5"
                        class="text-center py-4 text-muted">
                        Aucune présence.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach($attendances as $a): ?>

                    <tr>
                        <td>
                            <?=dateFr($a['date_presence'])?>
                        </td>

                        <td>
                            <?=h($a['heure_arrivee']?:'-')?>
                        </td>

                        <td>
                            <?=h($a['heure_depart']?:'-')?>
                        </td>

                        <td>
                            <span class="badge bg-secondary">
                                <?=h($a['statut'])?>
                            </span>
                        </td>

                        <td>
                            <?=h($a['source'])?>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- JOURNAUX -->
<div class="stage-section">

    <div class="stage-section-head">
        <strong>
            <i class="bi bi-journal-check me-1"></i>
            Journaux de stage
        </strong>

        <span class="badge bg-light text-dark">
            <?=count($journals)?>
        </span>
    </div>

    <div class="stage-section-body table-responsive">

        <table class="table stagia-modern-table mb-0">

            <thead>
            <tr>
                <th>DATE</th>
                <th>ACTIVITÉS</th>
                <th>STATUT</th>
                <th>VALIDATION</th>
            </tr>
            </thead>

            <tbody>

            <?php if(!$journals): ?>

                <tr>
                    <td colspan="4"
                        class="text-center py-4 text-muted">
                        Aucun journal.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach($journals as $j): ?>

                    <tr>

                        <td>
                            <?=dateFr($j['date_journal'])?>
                        </td>

                        <td>
                            <?=h($j['resume_activites'])?>
                        </td>

                        <td>
                            <span class="badge bg-secondary">
                                <?=h($j['statut'])?>
                            </span>
                        </td>

                        <td>
                            <?=dateTimeFr($j['validated_at'])?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- EVALUATIONS -->
<div class="stage-section">

    <div class="stage-section-head">

        <strong>
            <i class="bi bi-clipboard-data me-1"></i>
            Évaluations
        </strong>

        <span class="badge bg-light text-dark">
            <?=count($evaluations)?>
        </span>

    </div>

    <div class="stage-section-body table-responsive">

        <table class="table stagia-modern-table mb-0">

            <thead>
            <tr>
                <th>TYPE</th>
                <th>RÔLE</th>
                <th>NOTE</th>
                <th>STATUT</th>
                <th>FINALISÉE LE</th>
            </tr>
            </thead>

            <tbody>

            <?php if(!$evaluations): ?>

                <tr>
                    <td colspan="5"
                        class="text-center py-4 text-muted">
                        Aucune évaluation.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach($evaluations as $e): ?>

                    <tr>

                        <td>
                            <?=h($e['type_evaluation'])?>
                        </td>

                        <td>
                            <?=h($e['evaluator_role'])?>
                        </td>

                        <td>
                            <?=$e['note_finale']!==null
                                ?h(
                                    number_format(
                                        (float)$e['note_finale'],
                                        2,
                                        ',',
                                        ' '
                                    )
                                ).' / 20'
                                :'-'?>
                        </td>

                        <td>
                            <span class="badge bg-secondary">
                                <?=h($e['statut'])?>
                            </span>
                        </td>

                        <td>
                            <?=dateTimeFr($e['finalized_at'])?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</main>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
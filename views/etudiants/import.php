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

if(!$etablissementId)
    exit('Aucun établissement associé.');

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

function e($v):string{
    return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8');
}


/* =========================================================
   ÉTABLISSEMENT + TYPE
========================================================= */

$stmt=$pdo->prepare("
    SELECT
        id,
        nom,
        type_etablissement
    FROM etablissements
    WHERE id=?
    LIMIT 1
");

$stmt->execute([$etablissementId]);

$etablissement=
    $stmt->fetch(PDO::FETCH_ASSOC);

if(!$etablissement)
    exit('Établissement introuvable.');

$isUniversite=
    strtoupper(
        (string)$etablissement['type_etablissement']
    )==='UNIVERSITE';


/* =========================================================
   ANNÉE ACADÉMIQUE RÉELLEMENT EN COURS
========================================================= */

$stmt=$pdo->prepare("
    SELECT id,libelle,date_debut,date_fin
    FROM annees_academiques
    WHERE etablissement_id=?
      AND actif=1
      AND date_debut IS NOT NULL
      AND date_fin IS NOT NULL
      AND CURDATE() BETWEEN date_debut AND date_fin
    ORDER BY date_debut DESC,id DESC
    LIMIT 1
");
$stmt->execute([$etablissementId]);
$anneeCourante=$stmt->fetch(PDO::FETCH_ASSOC)?:null;


/* =========================================================
   STRUCTURE ACADÉMIQUE
========================================================= */

$facultes=[];
$departements=[];

if($isUniversite){

    $stmt=$pdo->prepare("
        SELECT id,nom
        FROM facultes
        WHERE etablissement_id=?
          AND actif=1
        ORDER BY nom
    ");

    $stmt->execute([$etablissementId]);

    $facultes=
        $stmt->fetchAll(PDO::FETCH_ASSOC);


    $stmt=$pdo->prepare("
        SELECT
            id,
            faculte_id,
            nom
        FROM departements
        WHERE etablissement_id=?
          AND actif=1
        ORDER BY nom
    ");

    $stmt->execute([$etablissementId]);

    $departements=
        $stmt->fetchAll(PDO::FETCH_ASSOC);
}


$stmt=$pdo->prepare("
    SELECT
        p.id,
        p.nom,
        p.niveau,
        f.id AS filiere_id,
        f.nom AS filiere,
        d.id AS departement_id,
        d.nom AS departement,
        fa.id AS faculte_id,
        fa.nom AS faculte

    FROM promotions p

    INNER JOIN filieres f
        ON f.id=p.filiere_id

    LEFT JOIN departements d
        ON d.id=f.departement_id

    LEFT JOIN facultes fa
        ON fa.id=d.faculte_id

    WHERE p.etablissement_id=?
      AND p.actif=1
      ".($anneeCourante?"AND p.annee_academique_id=?":"AND 1=0")."

    ORDER BY fa.nom,d.nom,f.nom,p.nom
");

$stmt->execute($anneeCourante?[$etablissementId,(int)$anneeCourante['id']]:[$etablissementId]);

$promotions=
    $stmt->fetchAll(PDO::FETCH_ASSOC);


$pageTitle='Importer les étudiants';
$activePage='etudiant-import';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">

    <div>

        <a href="index.php"
           class="detail-back">

            <i class="bi bi-arrow-left"></i>

            Liste des étudiants

        </a>

        <h1>
            Importer les étudiants
        </h1>

        <p>
            Importez plusieurs étudiants à partir
            d’un fichier Excel ou CSV.
        </p>

    </div>

</div>


<div class="row g-4">

<div class="col-lg-8">

<div class="stagia-list-card">

<form
    id="importForm"
    enctype="multipart/form-data"
>

<input
    type="hidden"
    name="csrf"
    value="<?= e($_SESSION['csrf']) ?>"
>


<!-- =========================================================
     RATTACHEMENT ACADÉMIQUE
========================================================= -->

<div class="p-4 border-bottom">

    <h5 class="mb-3">

        <i class="bi bi-mortarboard me-2"></i>

        Rattachement académique

    </h5>


    <div class="row g-3">

        <!-- ANNÉE AUTOMATIQUE -->

        <div class="<?= $isUniversite?'col-12':'col-md-6' ?>">

            <label class="form-label">
                Année académique
            </label>

            <?php if($anneeCourante): ?>

                <div class="form-control bg-light">

                    <i class="bi bi-calendar-check text-success me-1"></i>

                    <strong>
                        <?= e($anneeCourante['libelle']) ?>
                    </strong>

                </div>

                <div class="form-text">
                    Année réellement en cours : <?= e(date('d/m/Y',strtotime($anneeCourante['date_debut']))) ?> → <?= e(date('d/m/Y',strtotime($anneeCourante['date_fin']))) ?>.
                    STAGIA refuse l'import si aucune année ne couvre la date du jour.
                </div>

            <?php else: ?>

                <div class="alert alert-danger mb-0 py-2">
                    <strong>Aucune année académique en cours.</strong>
                    Vérifiez les dates de début/fin dans « Années académiques ». L'import est désactivé.
                </div>

            <?php endif; ?>

        </div>


        <?php if($isUniversite): ?>

        <!-- FACULTÉ -->

        <div class="col-md-4">

            <label class="form-label">
                Faculté *
            </label>

            <select
                name="faculte_id"
                id="faculteSelect"
                class="form-select"
                required
            >

                <option value="">
                    Sélectionner une faculté...
                </option>

                <?php foreach($facultes as $f): ?>

                    <option value="<?= (int)$f['id'] ?>">
                        <?= e($f['nom']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <!-- DÉPARTEMENT -->

        <div class="col-md-4">

            <label class="form-label">
                Département *
            </label>

            <select
                name="departement_id"
                id="departementSelect"
                class="form-select"
                required
                disabled
            >

                <option value="">
                    Choisissez d'abord la faculté...
                </option>

            </select>

        </div>


        <!-- PROMOTION -->

        <div class="col-md-4">

            <label class="form-label">
                Promotion *
            </label>

            <select
                name="promotion_id"
                id="promotionSelect"
                class="form-select"
                required
                disabled
            >

                <option value="">
                    Choisissez d'abord le département...
                </option>

            </select>

        </div>

        <?php else: ?>

        <div class="col-md-6">

            <label class="form-label">
                Promotion *
            </label>

            <select
                name="promotion_id"
                id="promotionSelect"
                class="form-select"
                required
            >

                <option value="">
                    Sélectionner...
                </option>

                <?php foreach($promotions as $p): ?>

                    <option value="<?= (int)$p['id'] ?>">
                        <?= e($p['nom']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     FICHIER
========================================================= -->

<div class="p-4 border-bottom">

    <h5 class="mb-3">

        <i class="bi bi-file-earmark-excel me-2"></i>

        Fichier des étudiants

    </h5>

    <input
        type="file"
        name="file"
        id="importFile"
        class="form-control"
        accept=".xlsx,.xls,.csv"
        required
    >

    <div class="form-text mt-2">
        Formats autorisés : XLSX, XLS et CSV.
        Maximum 5 Mo.
    </div>

</div>


<!-- =========================================================
     INFORMATIONS FICHIER
========================================================= -->

<div class="p-4">

    <div class="alert alert-light border">

        <strong>
            Colonnes attendues
        </strong>

        <div class="small text-muted mt-2">

            nom, postnom, prenom, sexe,
            date_naissance, email,
            telephone, email_institutionnel

        </div>

    </div>


    <div class="alert alert-info mb-0">

        <i class="bi bi-info-circle me-2"></i>

        Le code STAGIA, le matricule et l'année académique
        seront déterminés automatiquement.
        Aucun compte ni e-mail d'activation n'est créé pendant l'import.

    </div>

</div>


<!-- =========================================================
     ACTIONS
========================================================= -->

<div class="p-4 border-top d-flex justify-content-end gap-2">

    <a
        href="index.php"
        class="btn btn-light border"
    >
        Annuler
    </a>

    <button
        type="submit"
        id="importBtn"
        class="btn btn-primary-stagia"
        <?= $anneeCourante?'':'disabled' ?>
    >

        <i class="bi bi-upload me-1"></i>

        Importer les étudiants

    </button>

</div>

<div class="px-4 pb-3">
    <div class="alert alert-warning py-2 mb-0 small">
        <i class="bi bi-shield-lock me-1"></i>
        <strong>Protection contre le double import :</strong>
        dès que l'envoi commence, le bouton <strong>Importer les étudiants</strong> est désactivé jusqu'à la fin du traitement ou jusqu'à une erreur.
        Ne relancez pas le même fichier pendant qu'une importation est en cours.
    </div>
</div>


<!-- =========================================================
     PROGRESSION
========================================================= -->

<div
    id="importProgressCard"
    class="px-4 pb-4 d-none"
>

    <div class="border rounded-3 p-3 bg-light">

        <div class="d-flex
                    justify-content-between
                    align-items-start
                    gap-3
                    flex-wrap
                    mb-3">

            <div>

                <h6 class="mb-1">

                    <i class="bi bi-file-earmark-arrow-up me-1"></i>

                    Importation en cours

                </h6>

                <small
                    id="importProgressStep"
                    class="text-muted"
                >
                    Préparation du fichier...
                </small>

            </div>

            <span
                id="importStatusBadge"
                class="badge bg-secondary"
            >
                EN ATTENTE
            </span>

        </div>


        <div class="row g-2 mb-3">

            <div class="col-6 col-md">

                <div class="border rounded-3 p-2 bg-white h-100">

                    <small class="text-muted d-block">
                        TOTAL À IMPORTER
                    </small>

                    <strong
                        id="importTotal"
                        class="fs-5"
                    >
                        0
                    </strong>

                </div>

            </div>


            <div class="col-6 col-md">

                <div class="border rounded-3 p-2 bg-white h-100">

                    <small class="text-muted d-block">
                        TRAITÉS
                    </small>

                    <strong
                        id="importProcessed"
                        class="fs-5"
                    >
                        0
                    </strong>

                </div>

            </div>


            <div class="col-6 col-md">

                <div class="border rounded-3 p-2 bg-white h-100">

                    <small class="text-muted d-block">
                        IMPORTÉS
                    </small>

                    <strong
                        id="importImported"
                        class="fs-5 text-success"
                    >
                        0
                    </strong>

                </div>

            </div>


            <div class="col-6 col-md">

                <div class="border rounded-3 p-2 bg-white h-100">

                    <small class="text-muted d-block">
                        ÉCHECS
                    </small>

                    <strong
                        id="importFailed"
                        class="fs-5 text-danger"
                    >
                        0
                    </strong>

                </div>

            </div>


            <div class="col-6 col-md">

                <div class="border rounded-3 p-2 bg-white h-100">

                    <small class="text-muted d-block">
                        RESTANTS
                    </small>

                    <strong
                        id="importRemaining"
                        class="fs-5"
                    >
                        0
                    </strong>

                </div>

            </div>

        </div>


        <div
            class="progress"
            style="height:12px"
        >

            <div
                id="importProgressBar"
                class="progress-bar
                       progress-bar-striped
                       progress-bar-animated"
                role="progressbar"
                style="width:0%"
                aria-valuemin="0"
                aria-valuemax="100"
            >
            </div>

        </div>


        <div class="d-flex
                    justify-content-between
                    gap-3
                    flex-wrap
                    mt-2">

            <small
                id="importCurrentStudent"
                class="text-muted"
            >
            </small>

            <small class="text-muted">

                <i class="bi bi-info-circle me-1"></i>

                L'import continue sur le serveur même
                si vous quittez cette page.

            </small>

        </div>


        <div
            id="importErrors"
            class="mt-3 d-none"
        >
        </div>

    </div>

</div>

</form>

</div>

</div>


<!-- =========================================================
     COLONNE DROITE
========================================================= -->

<div class="col-lg-4">

<div class="stagia-list-card p-4 mb-3">

    <div class="d-flex gap-3">

        <div class="stagia-kpi-icon kpi-green">

            <i class="bi bi-file-earmark-spreadsheet"></i>

        </div>

        <div>

            <h6>
                Importation groupée
            </h6>

            <p class="small text-muted mb-0">

                Une seule promotion est sélectionnée
                pour tout le fichier.

            </p>

        </div>

    </div>

</div>


<div class="stagia-list-card p-4">

    <h6>
        Traitement automatique
    </h6>

    <div class="small text-muted mt-3">

        <p>✓ Profil STAGIA</p>
        <p>✓ Code STAGIA</p>
        <p>✓ Matricule établissement</p>
        <p>✓ Année académique automatique</p>
        <p>✓ Inscription académique</p>
        <p>✓ Contrôle majorité (18 ans)</p>
        <p class="mb-0">✓ Import uniquement — invitations envoyées séparément</p>

    </div>

</div>

</div>

</div>

</main>


<script>
document.addEventListener('DOMContentLoaded',()=>{

    const BASE_URL='<?= BASE_URL ?>';
    const HAS_ACTIVE_YEAR=<?= $anneeCourante?'true':'false' ?>;
    const IS_UNIVERSITY=<?= $isUniversite?'true':'false' ?>;

    const DEPARTMENTS=
        <?= json_encode(
            $departements,
            JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES|
            JSON_HEX_TAG|
            JSON_HEX_AMP|
            JSON_HEX_APOS|
            JSON_HEX_QUOT
        ) ?>;

    const PROMOTIONS=
        <?= json_encode(
            $promotions,
            JSON_UNESCAPED_UNICODE|
            JSON_UNESCAPED_SLASHES|
            JSON_HEX_TAG|
            JSON_HEX_AMP|
            JSON_HEX_APOS|
            JSON_HEX_QUOT
        ) ?>;

    const JOB_KEY=
        'stagia_student_import_job';

    const $=
        id=>document.getElementById(id);

    const form=
        $('importForm');

    const btn=
        $('importBtn');

    let currentJob=
        localStorage.getItem(
            JOB_KEY
        )||null;

    let pollTimer=null;
    let hideTimer=null;
    let redirectScheduled=false;
    let submitting=false;

    if(currentJob){
        btn.disabled=true;
        btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Vérification de l’import en cours...';
    }


    function resetImportButton(){
        submitting=false;
        btn.disabled=!HAS_ACTIVE_YEAR;
        btn.innerHTML='<i class="bi bi-upload me-1"></i>Importer les étudiants';
    }

    /* =====================================================
       FACULTÉ -> DÉPARTEMENT -> PROMOTION
    ====================================================== */

    if(IS_UNIVERSITY){

        const faculte=
            $('faculteSelect');

        const departement=
            $('departementSelect');

        const promotion=
            $('promotionSelect');


        function resetDepartments(){

            departement.innerHTML=`
                <option value="">
                    Choisissez d'abord la faculté...
                </option>
            `;

            departement.disabled=true;
        }


        function resetPromotions(){

            promotion.innerHTML=`
                <option value="">
                    Choisissez d'abord le département...
                </option>
            `;

            promotion.disabled=true;
        }


        faculte.onchange=()=>{

            resetDepartments();
            resetPromotions();

            const facultyId=
                Number(
                    faculte.value
                    ||0
                );


            if(!facultyId)
                return;


            const items=
                DEPARTMENTS.filter(
                    x=>
                        Number(x.faculte_id)===
                        facultyId
                );


            departement.innerHTML=`
                <option value="">
                    Sélectionner un département...
                </option>

                ${
                    items.map(
                        x=>`
                            <option value="${Number(x.id)}">
                                ${STAGIA.escape(x.nom||'')}
                            </option>
                        `
                    ).join('')
                }
            `;


            departement.disabled=
                !items.length;
        };


        departement.onchange=()=>{

            resetPromotions();

            const departmentId=
                Number(
                    departement.value
                    ||0
                );


            if(!departmentId)
                return;


            const items=
                PROMOTIONS.filter(
                    x=>
                        Number(x.departement_id)===
                        departmentId
                );


            promotion.innerHTML=`
                <option value="">
                    Sélectionner une promotion...
                </option>

                ${
                    items.map(
                        x=>`
                            <option value="${Number(x.id)}">
                                ${STAGIA.escape(x.nom||'')}
                            </option>
                        `
                    ).join('')
                }
            `;


            promotion.disabled=
                !items.length;
        };
    }


    /* =====================================================
       PROGRESSION
    ====================================================== */

    function running(status){

        return [
            'PENDING',
            'RUNNING'
        ].includes(status);
    }


    function statusLabel(status){

        return {
            PENDING:'EN ATTENTE',
            RUNNING:'EN COURS',
            COMPLETED:'TERMINÉ',
            COMPLETED_WITH_ERRORS:'TERMINÉ AVEC ERREURS',
            FAILED:'ÉCHEC'
        }[status]
        ||status
        ||'-';
    }


    function statusBadge(status){

        return status==='COMPLETED'
            ?'bg-success'
            :status==='COMPLETED_WITH_ERRORS'
            ?'bg-warning text-dark'
            :status==='FAILED'
            ?'bg-danger'
            :status==='RUNNING'
            ?'bg-primary'
            :'bg-secondary';
    }


    function renderJob(job){

        if(!job)
            return;


        currentJob=
            job.uuid;


        localStorage.setItem(
            JOB_KEY,
            job.uuid
        );


        $('importProgressCard')
            .classList
            .remove('d-none');


        $('importTotal').textContent=
            Number(job.total||0);


        $('importProcessed').textContent=
            Number(job.processed||0);


        $('importImported').textContent=
            Number(job.imported||0);


        $('importFailed').textContent=
            Number(job.failed||0);


        $('importRemaining').textContent=
            Number(job.remaining||0);


        $('importProgressStep').textContent=
            job.step_label
            ||'Traitement en cours...';


        $('importCurrentStudent').textContent=
            job.current_student
            ?'Traitement : '+job.current_student
            :'';


        const progress=
            Math.max(
                0,
                Math.min(
                    100,
                    Number(job.progress||0)
                )
            );


        const bar=
            $('importProgressBar');


        bar.style.width=
            progress+'%';


        bar.textContent=
            progress>=12
            ?progress+'%'
            :'';


        bar.classList.toggle(
            'progress-bar-animated',
            running(job.statut)
        );


        const badge=
            $('importStatusBadge');


        badge.className=
            'badge '+
            statusBadge(
                job.statut
            );


        badge.textContent=
            statusLabel(
                job.statut
            );


        const errors=
            Array.isArray(job.errors)
            ?job.errors
            :[];


        if(errors.length){

            $('importErrors')
                .classList
                .remove('d-none');


            $('importErrors').innerHTML=`

                <div class="alert alert-warning mb-0">

                    <strong>
                        Dernières erreurs
                    </strong>

                    <ul class="mb-0 mt-2">

                        ${
                            errors.map(
                                x=>`
                                    <li>
                                        Ligne ${Number(x.row_number||0)}
                                        —
                                        ${STAGIA.escape(x.error_message||'Erreur')}
                                    </li>
                                `
                            ).join('')
                        }

                    </ul>

                </div>
            `;

        }else{

            $('importErrors')
                .classList
                .add('d-none');

            $('importErrors').innerHTML='';
        }


        if(running(job.statut)){

            submitting=true;

            if(hideTimer){

                clearTimeout(
                    hideTimer
                );

                hideTimer=null;
            }


            btn.disabled=true;


            btn.innerHTML=`

                <span class="spinner-border
                             spinner-border-sm
                             me-1">
                </span>

                Importation ${progress} %

            `;

            return;
        }


        resetImportButton();


        localStorage.removeItem(
            JOB_KEY
        );


        currentJob=null;


        if(job.statut==='FAILED'){

            STAGIA.toast(
                job.error_message
                ||'Importation interrompue.',
                'danger'
            );

            return;
        }


        if(
            job.statut==='COMPLETED'
        ){

            if(!redirectScheduled){

                redirectScheduled=true;


                STAGIA.toast(
                    job.message
                    ||'Importation terminée.',
                    'success'
                );


                hideTimer=
                    setTimeout(
                        ()=>{

                            window.location.href=
                                BASE_URL+
                                '/views/etudiants/index.php';

                        },
                        3500
                    );
            }

        }else if(
            job.statut==='COMPLETED_WITH_ERRORS'
        ){

            STAGIA.toast(
                job.message
                ||'Importation terminée avec certaines erreurs.',
                'warning'
            );
        }
    }


    async function loadStatus(){

        try{

            const q=
                currentJob
                ?'?job_uuid='+
                 encodeURIComponent(
                    currentJob
                 )
                :'';


            const r=
                await STAGIA.request(

                    BASE_URL+
                    '/actions/etudiants/student-import-status.php'+
                    q

                );


            const job=
                r.data.job
                ||null;


            if(!job){

    if(pollTimer){
        clearInterval(pollTimer);
        pollTimer=null;
    }

    localStorage.removeItem(JOB_KEY);
    currentJob=null;
    submitting=false;

    resetImportButton();

    return;
}

            renderJob(
                job
            );


            if(running(job.statut)){

                if(!pollTimer){

                    pollTimer=
                        setInterval(
                            loadStatus,
                            1200
                        );
                }

            }else if(pollTimer){

                clearInterval(
                    pollTimer
                );

                pollTimer=null;
            }


        }catch(error){

            console.error(
                'Progression import :',
                error
            );
        }
    }


    /* =====================================================
       SOUMISSION
    ====================================================== */

    form.onsubmit=async event=>{

        event.preventDefault();

        if(submitting||btn.disabled&&currentJob){
            STAGIA.toast('Une importation est déjà en cours. Attendez sa fin.','warning');
            return;
        }

        if(!HAS_ACTIVE_YEAR){

            STAGIA.toast(
                'Aucune année académique active.',
                'danger'
            );

            return;
        }


        if(IS_UNIVERSITY){

            if(!$('faculteSelect').value){

                STAGIA.toast(
                    'Sélectionnez une faculté.',
                    'warning'
                );

                return;
            }


            if(!$('departementSelect').value){

                STAGIA.toast(
                    'Sélectionnez un département.',
                    'warning'
                );

                return;
            }


            if(!$('promotionSelect').value){

                STAGIA.toast(
                    'Sélectionnez une promotion.',
                    'warning'
                );

                return;
            }
        }


        const file=
            $('importFile').files[0];


        if(!file){

            STAGIA.toast(
                'Sélectionnez un fichier.',
                'warning'
            );

            return;
        }


        if(file.size>5*1024*1024){

            STAGIA.toast(
                'Le fichier ne doit pas dépasser 5 Mo.',
                'danger'
            );

            return;
        }


        submitting=true;
        btn.disabled=true;


        btn.innerHTML=`

            <span class="spinner-border
                         spinner-border-sm
                         me-1">
            </span>

            Envoi du fichier...

        `;


        try{

            const data=
                new FormData(
                    form
                );


            const r=
                await STAGIA.post(

                    BASE_URL+
                    '/actions/etudiants/student-import.php',

                    data

                );


            const job=
                r.data
                ||{};


            if(!job.job_uuid){

                throw new Error(
                    'La tâche d’importation n’a pas été créée.'
                );
            }


            currentJob=
                job.job_uuid;


            localStorage.setItem(
                JOB_KEY,
                currentJob
            );


            redirectScheduled=false;


            renderJob({
                uuid:currentJob,
                statut:job.statut||'PENDING',
                total:job.total||0,
                processed:0,
                imported:0,
                failed:0,
                remaining:job.total||0,
                progress:job.progress||5,
                step_label:
                    job.step_label
                    ||'Fichier reçu. Préparation...',
                errors:[]
            });


            await loadStatus();


        }catch(error){

            resetImportButton();


            STAGIA.toast(
                error.message,
                'danger'
            );
        }
    };


    /* Reprise automatique au retour */
    loadStatus();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

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

function e($value): string{
    return htmlspecialchars($value??'',ENT_QUOTES,'UTF-8');
}


/* =========================================================
   ANNÉE ACADÉMIQUE AUTOMATIQUE
========================================================= */

$stmt=$pdo->prepare("
    SELECT
        id,
        libelle,
        date_debut,
        date_fin

    FROM annees_academiques

    WHERE etablissement_id=?
      AND actif=1

    ORDER BY
        CASE
            WHEN CURDATE() BETWEEN date_debut AND date_fin
            THEN 0
            ELSE 1
        END,
        date_debut DESC,
        id DESC

    LIMIT 1
");

$stmt->execute([$etablissementId]);

$anneeCourante=
    $stmt->fetch(PDO::FETCH_ASSOC)
    ?:null;

$maxBirthDate=
    (new DateTimeImmutable('today'))
    ->modify('-18 years')
    ->format('Y-m-d');


/* =========================================================
   TYPE D'ÉTABLISSEMENT
========================================================= */

$stmt=$pdo->prepare("
    SELECT type_etablissement
    FROM etablissements
    WHERE id=?
    LIMIT 1
");

$stmt->execute([$etablissementId]);

$etablissementType=
    strtoupper(
        (string)$stmt->fetchColumn()
    );

$isUniversite=
    $etablissementType==='UNIVERSITE';


/* =========================================================
   STRUCTURE UNIVERSITAIRE
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
    $facultes=$stmt->fetchAll(PDO::FETCH_ASSOC);


    $stmt=$pdo->prepare("
        SELECT id,faculte_id,nom
        FROM departements
        WHERE etablissement_id=?
          AND actif=1
        ORDER BY nom
    ");

    $stmt->execute([$etablissementId]);
    $departements=$stmt->fetchAll(PDO::FETCH_ASSOC);
}


/* =========================================================
   PROMOTIONS
========================================================= */

$stmt=$pdo->prepare("
    SELECT
        p.id,
        p.nom,
        p.niveau,
        f.id filiere_id,
        f.nom filiere,
        d.id departement_id,
        d.nom departement,
        fa.id faculte_id,
        fa.nom faculte
    FROM promotions p
    JOIN filieres f
      ON f.id=p.filiere_id
    LEFT JOIN departements d
      ON d.id=f.departement_id
    LEFT JOIN facultes fa
      ON fa.id=d.faculte_id
    WHERE p.etablissement_id=?
      AND p.actif=1
    ORDER BY fa.nom,d.nom,f.nom,p.nom
");

$stmt->execute([$etablissementId]);
$promotions=$stmt->fetchAll();

$pageTitle='Inscrire un étudiant';
$activePage='etudiants-inscrire';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- =========================================================
     ENTÊTE
========================================================= -->

<div class="stagia-page-head">

    <div>
        <a href="index.php" class="detail-back">
            <i class="bi bi-arrow-left"></i>
            Liste des étudiants
        </a>

        <h1>Inscrire un étudiant</h1>

        <p>
            Rattacher un étudiant existant ou créer
            une nouvelle identité STAGIA.
        </p>
    </div>

</div>


<div class="row g-4">

<!-- =========================================================
     COLONNE GAUCHE
========================================================= -->

<div class="col-lg-8">

<div class="stagia-list-card">


<!-- =========================================================
     TYPE DE PROFIL
========================================================= -->

<div class="p-4 border-bottom">

    <h5 class="mb-1">Profil étudiant</h5>

    <p class="text-muted mb-3">
        Vérifiez d'abord si l'étudiant possède déjà
        une identité STAGIA.
    </p>

    <div class="stagia-tabs">

        <button
            type="button"
            class="stagia-tab active"
            data-mode="existing"
        >
            <i class="bi bi-search me-1"></i>
            Étudiant existant
        </button>

        <button
            type="button"
            class="stagia-tab"
            data-mode="new"
        >
            <i class="bi bi-person-plus me-1"></i>
            Nouveau profil
        </button>

    </div>

</div>


<form id="studentForm">

<input
    type="hidden"
    name="csrf"
    value="<?= e($_SESSION['csrf']) ?>"
>

<input
    type="hidden"
    name="mode"
    id="studentMode"
    value="existing"
>

<input
    type="hidden"
    name="student_id"
    id="studentId"
>


<!-- =========================================================
     ÉTUDIANT EXISTANT
========================================================= -->

<div id="existingSection" class="p-4 border-bottom">

    <label class="form-label fw-semibold">
        Rechercher dans STAGIA
    </label>

    <div class="input-group">

        <span class="input-group-text">
            <i class="bi bi-search"></i>
        </span>

        <input
            type="text"
            id="studentSearch"
            class="form-control"
            placeholder="Code STAGIA, nom, e-mail ou téléphone"
            autocomplete="off"
        >

    </div>

    <div class="form-text">
        Saisissez au minimum 2 caractères.
    </div>

    <div id="searchResults" class="mt-3"></div>

    <div
        id="selectedStudent"
        class="alert alert-success mt-3 mb-0 d-none"
    ></div>

</div>


<!-- =========================================================
     NOUVEAU PROFIL
========================================================= -->

<div id="newSection" class="p-4 border-bottom d-none">

    <h6 class="mb-3">
        <i class="bi bi-person-vcard me-2"></i>
        Identité personnelle
    </h6>

    <div class="row g-3">

        <div class="col-md-4">

            <label class="form-label">
                Nom *
            </label>

            <input
                type="text"
                name="nom"
                id="studentNom"
                class="form-control"
                maxlength="100"
                disabled
            >

        </div>


        <div class="col-md-4">

            <label class="form-label">
                Postnom
            </label>

            <input
                type="text"
                name="postnom"
                id="studentPostnom"
                class="form-control"
                maxlength="100"
                disabled
            >

        </div>


        <div class="col-md-4">

            <label class="form-label">
                Prénom
            </label>

            <input
                type="text"
                name="prenom"
                id="studentPrenom"
                class="form-control"
                maxlength="100"
                disabled
            >

        </div>


        <div class="col-md-4">

            <label class="form-label">
                Sexe
            </label>

            <select
                name="sexe"
                class="form-select"
                disabled
            >
                <option value="">
                    Sélectionner...
                </option>

                <option value="M">
                    Masculin
                </option>

                <option value="F">
                    Féminin
                </option>
            </select>

        </div>


        <div class="col-md-4">

            <label class="form-label">
                Date de naissance
            </label>

            <input
                type="date"
                name="date_naissance"
                id="studentBirthDate"
                class="form-control"
                max="<?= e($maxBirthDate) ?>"
                disabled
            >

        </div>


        <div class="col-md-4">

            <label class="form-label">
                Téléphone
            </label>

            <input
                type="text"
                name="telephone"
                class="form-control"
                maxlength="30"
                disabled
            >

        </div>


        <!-- EMAIL IMPORTANT -->

        <div class="col-12">

            <label class="form-label">
                E-mail personnel *
            </label>

            <input
                type="email"
                name="email"
                id="studentEmail"
                class="form-control"
                maxlength="150"
                autocomplete="email"
                disabled
            >

            <div class="form-text">

                <i class="bi bi-envelope-check me-1"></i>

                STAGIA enverra à cette adresse
                le lien permettant à l'étudiant de
                créer son mot de passe.

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     INSCRIPTION ÉTABLISSEMENT
========================================================= -->

<div class="p-4">

    <h6 class="mb-1">

        <i class="bi bi-building me-2"></i>

        Inscription dans votre établissement

    </h6>

    <p class="text-muted small mb-4">
        Le matricule et le parcours académique sont
        propres à votre établissement.
    </p>


    <div class="row g-3">

                <!-- MATRICULE -->

            <div class="col-md-6">

            <label class="form-label">
                Matricule
            </label>

            <div class="form-control bg-light text-muted">
                <i class="bi bi-magic me-1"></i>
                Généré automatiquement après l'inscription
            </div>

            <div class="form-text">
                Exemple : UNIKIN-ETU-2026-000001
            </div>

        </div>


        <!-- EMAIL INSTITUTIONNEL -->

        <div class="col-md-6">

            <label class="form-label">
                E-mail institutionnel
            </label>

            <input
                type="email"
                name="email_institutionnel"
                class="form-control"
                maxlength="150"
            >

        </div>


        <!-- DATE -->

        <div class="col-md-6">

            <label class="form-label">
                Date d'inscription
            </label>

            <input
                type="date"
                name="date_inscription"
                class="form-control"
                value="<?= date('Y-m-d') ?>"
            >

        </div>


        <!-- ANNÉE ACADÉMIQUE AUTOMATIQUE -->

        <div class="col-md-6">

            <label class="form-label">
                Année académique
            </label>

            <?php if($anneeCourante): ?>

                <div class="form-control bg-light">

                    <i class="bi bi-calendar-check me-1 text-success"></i>

                    <strong>
                        <?= e($anneeCourante['libelle']) ?>
                    </strong>

                </div>

                <div class="form-text">
                    Déterminée automatiquement par STAGIA.
                </div>

            <?php else: ?>

                <div class="alert alert-danger mb-0 py-2">
                    Aucune année académique active.
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

        <!-- AUTRES TYPES D'ÉTABLISSEMENTS -->

        <div class="col-12">

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
     PREMIÈRE CONNEXION
========================================================= -->

<div class="px-4 pb-4">

    <div class="alert alert-light border mb-0">

        <div class="d-flex gap-3">

            <div class="fs-4 text-warning">
                <i class="bi bi-envelope-check"></i>
            </div>

            <div>

                <strong>
                    Première connexion de l'étudiant
                </strong>

                <div class="small text-muted mt-1">

                    Pour un nouveau compte, STAGIA génère
                    automatiquement son code étudiant puis
                    envoie une invitation par e-mail.

                    L'étudiant choisira lui-même son mot de passe
                    avant sa première connexion.

                </div>

            </div>

        </div>

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
        id="saveBtn"
        class="btn btn-primary-stagia"
    >
        <i class="bi bi-person-check me-1"></i>
        Inscrire l'étudiant
    </button>

    <?php if(!$anneeCourante): ?>
        <script>
            document.addEventListener('DOMContentLoaded',()=>{
                const b=document.getElementById('saveBtn');
                if(b)b.disabled=true;
            });
        </script>
    <?php endif; ?>

</div>

<!-- =========================================================
     PROGRESSION DE L'INSCRIPTION
========================================================= -->

<div
    id="enrollProgressCard"
    class="px-4 pb-4 d-none"
>

    <div class="border rounded-3 p-3 bg-light">

        <div class="d-flex
                    justify-content-between
                    align-items-center
                    gap-3
                    mb-2">

            <div>

                <strong>
                    <i class="bi bi-hourglass-split me-1"></i>
                    Traitement de l'inscription
                </strong>

                <div
                    id="enrollProgressStep"
                    class="small text-muted mt-1"
                >
                    Préparation...
                </div>

            </div>

            <strong id="enrollProgressPercent">
                0 %
            </strong>

        </div>

        <div
            class="progress"
            style="height:12px"
        >

            <div
                id="enrollProgressBar"
                class="progress-bar
                       progress-bar-striped
                       progress-bar-animated"
                role="progressbar"
                style="width:0%"
                aria-valuemin="0"
                aria-valuemax="100"
            ></div>

        </div>

        <div class="small text-muted mt-2">

            <i class="bi bi-info-circle me-1"></i>

            Le traitement continue sur le serveur même si
            vous quittez cette page.

        </div>

        <div
            id="enrollProgressError"
            class="alert alert-danger mt-3 mb-0 d-none"
        ></div>

    </div>

</div>

</form>

</div>
</div>


<!-- =========================================================
     COLONNE DROITE
========================================================= -->

<div class="col-lg-4">


<!-- IDENTITÉ STAGIA -->

<div class="stagia-list-card p-4 mb-3">

    <div class="d-flex gap-3">

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-person-badge"></i>
        </div>

        <div>

            <h6 class="mb-1">
                Identité STAGIA
            </h6>

            <p class="small text-muted mb-0">
                Un même étudiant possède une seule identité
                nationale STAGIA, même s'il fréquente plusieurs
                établissements.
            </p>

        </div>

    </div>

</div>


<!-- MULTI ÉTABLISSEMENTS -->

<div class="stagia-list-card p-4 mb-3">

    <div class="d-flex gap-3">

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-buildings"></i>
        </div>

        <div>

            <h6 class="mb-1">
                Multi-établissements
            </h6>

            <p class="small text-muted mb-0">
                Le matricule, la promotion et le parcours
                restent propres à chaque établissement.
            </p>

        </div>

    </div>

</div>


<!-- ACTIVATION -->

<div class="stagia-list-card p-4">

    <div class="d-flex gap-3">

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-envelope-check"></i>
        </div>

        <div>

            <h6 class="mb-1">
                Activation du compte
            </h6>

            <p class="small text-muted mb-0">
                Lorsqu'un nouveau compte est créé,
                l'étudiant reçoit son invitation par e-mail
                et définit personnellement son mot de passe.
            </p>

        </div>

    </div>

</div>

</div>

</div>

</main>


<script>
document.addEventListener('DOMContentLoaded',()=>{

    const BASE_URL='<?= BASE_URL ?>';
    const $=id=>document.getElementById(id);

    const form=$('studentForm');
    const mode=$('studentMode');
    const studentId=$('studentId');

    const existing=$('existingSection');
    const newSection=$('newSection');

    const search=$('studentSearch');
    const results=$('searchResults');
    const selected=$('selectedStudent');

    const email=$('studentEmail');
    const nom=$('studentNom');
    const postnom=$('studentPostnom');
    const prenom=$('studentPrenom');
    const birthDate=$('studentBirthDate');

    const MAX_BIRTH_DATE='<?= e($maxBirthDate) ?>';
    const HAS_ACTIVE_YEAR=<?= $anneeCourante?'true':'false' ?>;
    const IS_UNIVERSITY=<?= $isUniversite?'true':'false' ?>;
    const DEPARTMENTS=<?= json_encode($departements,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const PROMOTIONS=<?= json_encode($promotions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    const ENROLL_JOB_KEY='stagia_student_enroll_job';

    let timer=null,
        enrollPollTimer=null,
        currentEnrollJob=
            localStorage.getItem(
                ENROLL_JOB_KEY
            )||null,
        redirectScheduled=false;


    /* =====================================================
       MODE EXISTANT / NOUVEAU
    ====================================================== */

    function setMode(value){

        mode.value=value;

        document.querySelectorAll('[data-mode]')
            .forEach(tab=>{
                tab.classList.toggle(
                    'active',
                    tab.dataset.mode===value
                );
            });

        existing.classList.toggle(
            'd-none',
            value!=='existing'
        );

        newSection.classList.toggle(
            'd-none',
            value!=='new'
        );


        /*
         * Très important :
         * les champs du nouveau profil sont désactivés
         * lorsque l'on utilise un étudiant existant.
         */
        newSection
            .querySelectorAll('input,select,textarea')
            .forEach(field=>{
                field.disabled=value!=='new';
            });


        email.required=value==='new';
        nom.required=value==='new';

        studentId.value='';
        selected.classList.add('d-none');

        results.innerHTML='';

        if(value==='new')
            search.value='';
    }


    document.querySelectorAll('[data-mode]')
        .forEach(tab=>{
            tab.onclick=()=>setMode(tab.dataset.mode);
        });


    /* =====================================================
       RECHERCHE ÉTUDIANT
    ====================================================== */

    search.oninput=()=>{

        clearTimeout(timer);

        const q=search.value.trim();

        studentId.value='';
        selected.classList.add('d-none');

        if(q.length<2){
            results.innerHTML='';
            return;
        }

        results.innerHTML=`
            <div class="text-muted small py-2">
                <span class="spinner-border spinner-border-sm me-2"></span>
                Recherche...
            </div>
        `;

        timer=setTimeout(
            ()=>chercher(q),
            350
        );
    };


    async function chercher(q){

        try{

            const r=await STAGIA.request(
                BASE_URL+
                '/actions/etudiants/student-search.php?q='+
                encodeURIComponent(q)
            );

            const items=r.data.items||[];

            if(!items.length){

                results.innerHTML=`
                    <div class="alert alert-light border mb-0">
                        Aucun étudiant trouvé.
                        Vous pouvez créer un nouveau profil.
                    </div>
                `;

                return;
            }


            results.innerHTML=items.map((x,index)=>{

                const nom=[
                    x.nom,
                    x.postnom,
                    x.prenom
                ].filter(Boolean).join(' ');

                return `
                    <button
                        type="button"
                        class="btn w-100 text-start border rounded-3 mb-2 p-3 student-result"
                        data-index="${index}"
                    >

                        <div class="d-flex justify-content-between align-items-center gap-3">

                            <div>

                                <strong>
                                    ${STAGIA.escape(nom)}
                                </strong>

                                <div class="small text-muted">

                                    ${STAGIA.escape(x.stagia_code||'-')}

                                    ${
                                        x.email
                                        ?' • '+STAGIA.escape(x.email)
                                        :''
                                    }

                                </div>

                            </div>

                            ${
                                Number(x.deja_rattache)

                                ?`
                                <span class="badge bg-warning text-dark">
                                    Déjà inscrit ici
                                </span>
                                `

                                :`
                                <span class="badge bg-success">
                                    Sélectionner
                                </span>
                                `
                            }

                        </div>

                    </button>
                `;

            }).join('');


            document.querySelectorAll('.student-result')
                .forEach(btn=>{

                    btn.onclick=()=>{

                        const item=
                            items[Number(btn.dataset.index)];

                        if(Number(item.deja_rattache)){

                            STAGIA.toast(
                                'Cet étudiant est déjà rattaché à votre établissement.',
                                'warning'
                            );

                            return;
                        }

                        choisirEtudiant(item);
                    };
                });


        }catch(e){

            results.innerHTML='';
            STAGIA.toast(e.message,'danger');
        }
    }


    /* =====================================================
       SÉLECTION
    ====================================================== */

    function choisirEtudiant(item){

        studentId.value=item.id;

        const fullname=[
            item.nom,
            item.postnom,
            item.prenom
        ].filter(Boolean).join(' ');

        selected.innerHTML=`

            <div class="d-flex align-items-center justify-content-between gap-3">

                <div>

                    <i class="bi bi-check-circle-fill me-2"></i>

                    <strong>
                        ${STAGIA.escape(fullname)}
                    </strong>

                    <div class="small mt-1">

                        ${STAGIA.escape(
                            item.stagia_code||'Profil STAGIA'
                        )}

                        ${
                            item.email
                            ?' • '+STAGIA.escape(item.email)
                            :''
                        }

                    </div>

                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-success"
                    id="removeStudent"
                >
                    Modifier
                </button>

            </div>
        `;

        selected.classList.remove('d-none');
        results.innerHTML='';

        $('removeStudent').onclick=()=>{

            studentId.value='';
            selected.classList.add('d-none');
            search.focus();
        };
    }


    /* =====================================================
       PARCOURS UNIVERSITAIRE DÉPENDANT
       Faculté -> Département -> Promotion
    ====================================================== */

    if(IS_UNIVERSITY){

        const faculteSelect=$('faculteSelect');
        const departementSelect=$('departementSelect');
        const promotionSelect=$('promotionSelect');


        function resetDepartments(){

            departementSelect.innerHTML=`
                <option value="">
                    Choisissez d'abord la faculté...
                </option>
            `;

            departementSelect.disabled=true;
        }


        function resetPromotions(){

            promotionSelect.innerHTML=`
                <option value="">
                    Choisissez d'abord le département...
                </option>
            `;

            promotionSelect.disabled=true;
        }


        faculteSelect.addEventListener(
            'change',
            ()=>{

                resetDepartments();
                resetPromotions();

                const facultyId=
                    Number(
                        faculteSelect.value
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


                departementSelect.innerHTML=`
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


                departementSelect.disabled=
                    !items.length;
            }
        );


        departementSelect.addEventListener(
            'change',
            ()=>{

                resetPromotions();

                const departmentId=
                    Number(
                        departementSelect.value
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


                promotionSelect.innerHTML=`
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


                promotionSelect.disabled=
                    !items.length;
            }
        );
    }


    /* =====================================================
       NORMALISATION IDENTITÉ
    ====================================================== */

    function normalizeTitleCase(value){

        return String(value||'')
            .trim()
            .toLocaleLowerCase('fr-FR')
            .replace(
                /(^|[\s'-])([\p{L}])/gu,
                (_,sep,letter)=>
                    sep+
                    letter.toLocaleUpperCase('fr-FR')
            );
    }


    if(nom){

        nom.addEventListener(
            'input',
            ()=>{
                nom.value=
                    nom.value
                    .toLocaleUpperCase('fr-FR');
            }
        );
    }


    if(postnom){

        postnom.addEventListener(
            'input',
            ()=>{
                postnom.value=
                    postnom.value
                    .toLocaleUpperCase('fr-FR');
            }
        );
    }


    if(prenom){

        prenom.addEventListener(
            'blur',
            ()=>{
                prenom.value=
                    normalizeTitleCase(
                        prenom.value
                    );
            }
        );
    }


    if(birthDate){

        birthDate.max=
            MAX_BIRTH_DATE;


        birthDate.addEventListener(
            'change',
            ()=>{

                if(
                    birthDate.value &&
                    birthDate.value>MAX_BIRTH_DATE
                ){

                    STAGIA.toast(
                        "L'étudiant doit avoir au moins 18 ans.",
                        'danger'
                    );

                    birthDate.value='';
                    birthDate.focus();
                }
            }
        );
    }


    /* =====================================================
       PROGRESSION INSCRIPTION ASYNCHRONE
    ====================================================== */

    function enrollRunning(status){

        return [
            'PENDING',
            'RUNNING'
        ].includes(status);
    }


    function updateEnrollProgress(job){

        if(!job)
            return;


        currentEnrollJob=
            job.uuid;


        localStorage.setItem(
            ENROLL_JOB_KEY,
            job.uuid
        );


        $('enrollProgressCard')
            .classList
            .remove('d-none');


        const progress=
            Math.max(
                0,
                Math.min(
                    100,
                    Number(job.progress||0)
                )
            );


        $('enrollProgressBar').style.width=
            progress+'%';


        $('enrollProgressBar').setAttribute(
            'aria-valuenow',
            progress
        );


        $('enrollProgressPercent').textContent=
            progress+' %';


        $('enrollProgressStep').textContent=
            job.step_label
            ||'Traitement en cours...';


        $('enrollProgressBar').classList.toggle(
            'progress-bar-animated',
            enrollRunning(
                job.statut
            )
        );


        $('enrollProgressError').classList.add(
            'd-none'
        );


        if(IS_UNIVERSITY){

            if(
                !$('faculteSelect').value
            ){

                STAGIA.toast(
                    'Sélectionnez une faculté.',
                    'warning'
                );

                $('faculteSelect').focus();

                return;
            }


            if(
                !$('departementSelect').value
            ){

                STAGIA.toast(
                    'Sélectionnez un département.',
                    'warning'
                );

                $('departementSelect').focus();

                return;
            }


            if(
                !$('promotionSelect').value
            ){

                STAGIA.toast(
                    'Sélectionnez une promotion.',
                    'warning'
                );

                $('promotionSelect').focus();

                return;
            }
        }


        const btn=
            $('saveBtn');


        if(
            enrollRunning(
                job.statut
            )
        ){

            btn.disabled=true;

            btn.innerHTML=`

                <span class="spinner-border
                             spinner-border-sm
                             me-1">
                </span>

                Enregistrement ${progress} %

            `;

            return;
        }


        btn.disabled=
            !HAS_ACTIVE_YEAR;


        btn.innerHTML=`

            <i class="bi
                      bi-person-check
                      me-1">
            </i>

            Inscrire l'étudiant

        `;


        if(
            job.statut==='FAILED'
        ){

            localStorage.removeItem(
                ENROLL_JOB_KEY
            );

            currentEnrollJob=null;


            $('enrollProgressError').textContent=
                job.error_message
                ||"L'inscription a échoué.";


            $('enrollProgressError').classList.remove(
                'd-none'
            );


            STAGIA.toast(
                job.error_message
                ||"L'inscription a échoué.",
                'danger'
            );

            return;
        }


        if(
            [
                'COMPLETED',
                'COMPLETED_WITH_WARNING'
            ].includes(job.statut)
        ){

            localStorage.removeItem(
                ENROLL_JOB_KEY
            );

            currentEnrollJob=null;


            if(!redirectScheduled){

                redirectScheduled=true;


                STAGIA.toast(
                    job.message
                    ||'Étudiant inscrit avec succès.',
                    job.statut==='COMPLETED_WITH_WARNING'
                        ?'warning'
                        :'success'
                );


                setTimeout(
                    ()=>{

                        window.location.href=
                            BASE_URL+
                            '/views/etudiants/index.php';

                    },
                    1100
                );
            }
        }
    }


    async function loadEnrollStatus(){

        try{

            const q=
                currentEnrollJob

                ?'?job_uuid='+
                 encodeURIComponent(
                    currentEnrollJob
                 )

                :'';


            const r=
                await STAGIA.request(

                    BASE_URL+
                    '/actions/etudiants/student-enroll-status.php'+
                    q

                );


            const job=
                r.data.job
                ||null;


            if(!job){

                if(enrollPollTimer){

                    clearInterval(
                        enrollPollTimer
                    );

                    enrollPollTimer=null;
                }

                return;
            }


            updateEnrollProgress(
                job
            );


            if(
                enrollRunning(
                    job.statut
                )
            ){

                if(!enrollPollTimer){

                    enrollPollTimer=
                        setInterval(
                            loadEnrollStatus,
                            1200
                        );
                }

            }else if(enrollPollTimer){

                clearInterval(
                    enrollPollTimer
                );

                enrollPollTimer=null;
            }


        }catch(error){

            console.error(
                'Progression inscription :',
                error
            );
        }
    }


    /* =====================================================
       ENREGISTREMENT
    ====================================================== */

    form.onsubmit=async e=>{

        e.preventDefault();


        if(!HAS_ACTIVE_YEAR){

            STAGIA.toast(
                'Aucune année académique active.',
                'danger'
            );

            return;
        }


        if(
            mode.value==='existing' &&
            !studentId.value
        ){

            STAGIA.toast(
                'Sélectionnez d’abord un étudiant STAGIA.',
                'warning'
            );

            return;
        }


        if(
            mode.value==='new'
        ){

            if(
                !email.value.trim()
            ){

                STAGIA.toast(
                    "L'adresse e-mail personnelle est obligatoire pour l'activation du compte.",
                    'warning'
                );

                email.focus();

                return;
            }


            if(
                birthDate.value &&
                birthDate.value>MAX_BIRTH_DATE
            ){

                STAGIA.toast(
                    "Inscription refusée : l'étudiant doit avoir au moins 18 ans.",
                    'danger'
                );

                birthDate.focus();

                return;
            }


            nom.value=
                nom.value
                .trim()
                .toLocaleUpperCase('fr-FR');


            postnom.value=
                postnom.value
                .trim()
                .toLocaleUpperCase('fr-FR');


            prenom.value=
                normalizeTitleCase(
                    prenom.value
                );
        }


        const btn=
            $('saveBtn');


        btn.disabled=true;


        btn.innerHTML=`

            <span class="spinner-border
                         spinner-border-sm
                         me-1">
            </span>

            Préparation...

        `;


        try{

            const payload=
                new FormData(
                    form
                );


            const r=
                await STAGIA.post(

                    BASE_URL+
                    '/actions/etudiants/student-enroll-store.php',

                    payload

                );


            const data=
                r.data
                ||{};


            if(!data.job_uuid){

                throw new Error(
                    'La tâche d’inscription n’a pas été créée.'
                );
            }


            currentEnrollJob=
                data.job_uuid;


            localStorage.setItem(
                ENROLL_JOB_KEY,
                currentEnrollJob
            );


            redirectScheduled=
                false;


            updateEnrollProgress({
                uuid:data.job_uuid,
                statut:data.statut||'PENDING',
                progress:data.progress||5,
                step_label:
                    data.step_label
                    ||'Inscription mise en file d’attente.'
            });


            await loadEnrollStatus();


        }catch(error){

            btn.disabled=
                !HAS_ACTIVE_YEAR;


            btn.innerHTML=`

                <i class="bi
                          bi-person-check
                          me-1">
                </i>

                Inscrire l'étudiant

            `;


            STAGIA.toast(
                error.message,
                'danger'
            );
        }
    };


    /* Reprendre une inscription en arrière-plan après retour */
    loadEnrollStatus();


    /* Mode initial */
    setMode('existing');

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) exit('Aucun établissement associé.');

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

/* Référentiels filtres */
$refs=[
    'facultes'=>["SELECT id,nom FROM facultes WHERE etablissement_id=? AND actif=1 ORDER BY nom"],
    'departements'=>["SELECT id,faculte_id,nom FROM departements WHERE etablissement_id=? AND actif=1 ORDER BY nom"],
    'filieres'=>["SELECT id,departement_id,nom FROM filieres WHERE etablissement_id=? AND actif=1 ORDER BY nom"],
    'promotions'=>["SELECT id,filiere_id,nom FROM promotions WHERE etablissement_id=? AND actif=1 ORDER BY nom"]
];

foreach($refs as $key=>$sql){
    $stmt=$pdo->prepare($sql[0]);
    $stmt->execute([$etablissementId]);
    $$key=$stmt->fetchAll();
}

$pageTitle='Étudiants';
$activePage='etudiants';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- =========================================================
     ENTÊTE
========================================================= -->
<div class="stagia-page-head">

    <div>
        <h1>Liste des étudiants</h1>
        <p>Étudiants rattachés à votre établissement.</p>
    </div>

    <div class="d-flex flex-wrap gap-2">

        <a href="<?= BASE_URL ?>/views/etudiants/import.php"
           class="btn btn-light border px-3">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>
            Importer
        </a>

        <button type="button"
                id="sendInvitationsBtn"
                class="btn btn-outline-primary px-3">
            <i class="bi bi-envelope-arrow-up me-1"></i>
            Envoyer les invitations
        </button>

        <a href="<?= BASE_URL ?>/views/etudiants/inscrire.php"
           class="btn btn-primary-stagia px-4">
            <i class="bi bi-person-plus me-1"></i>
            Ajouter un étudiant
        </a>

    </div>

</div>


<!-- =========================================================
     PROGRESSION INVITATIONS
========================================================= -->
<div id="invitationProgressCard" class="stagia-list-card mb-3 d-none">
    <div class="p-3 p-md-4">

        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-envelope-check me-1"></i>
                    Envoi des invitations étudiants
                </h5>
                <small id="invitationProgressMessage" class="text-muted">
                    Préparation de l'envoi...
                </small>
            </div>

            <span id="invitationStatusBadge" class="badge bg-secondary">
                EN ATTENTE
            </span>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6 col-md">
                <div class="border rounded-3 p-2 h-100">
                    <small class="text-muted d-block">TOTAL À ENVOYER</small>
                    <strong id="invitationTotal" class="fs-5">0</strong>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="border rounded-3 p-2 h-100">
                    <small class="text-muted d-block">TRAITÉS</small>
                    <strong id="invitationProcessed" class="fs-5">0</strong>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="border rounded-3 p-2 h-100">
                    <small class="text-muted d-block">ENVOYÉS</small>
                    <strong id="invitationSent" class="fs-5 text-success">0</strong>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="border rounded-3 p-2 h-100">
                    <small class="text-muted d-block">ÉCHECS</small>
                    <strong id="invitationFailed" class="fs-5 text-danger">0</strong>
                </div>
            </div>

            <div class="col-6 col-md">
                <div class="border rounded-3 p-2 h-100">
                    <small class="text-muted d-block">RESTANTS</small>
                    <strong id="invitationRemaining" class="fs-5">0</strong>
                </div>
            </div>
        </div>

        <div class="progress" style="height:12px">
            <div id="invitationProgressBar"
                 class="progress-bar progress-bar-striped progress-bar-animated"
                 role="progressbar"
                 style="width:0%"
                 aria-valuemin="0"
                 aria-valuemax="100"></div>
        </div>

        <div class="d-flex justify-content-between gap-3 flex-wrap mt-2">
            <small id="invitationCurrentStudent" class="text-muted"></small>

            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                L'envoi continue sur le serveur même si vous quittez cette page.
            </small>
        </div>

        <div id="invitationErrors" class="mt-3 d-none"></div>
    </div>
</div>


<!-- =========================================================
     KPI
========================================================= -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>TOTAL ÉTUDIANTS</span>
            <strong id="statTotal">0</strong>
            <small>Rattachés à l’établissement</small>
        </div>
        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-people"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>ACTIFS</span>
            <strong id="statActifs">0</strong>
            <small>Étudiants actifs</small>
        </div>
        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-person-check"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>TERMINÉS</span>
            <strong id="statTermines">0</strong>
            <small>Parcours terminés</small>
        </div>
        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-mortarboard"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>ARCHIVÉS</span>
            <strong id="statArchives">0</strong>
            <small>Rattachements archivés</small>
        </div>
        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-archive"></i>
        </div>
    </div>

</div>


<!-- =========================================================
     LISTE
========================================================= -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div class="stagia-tabs">

            <button class="stagia-tab active" data-status="">
                Tous <span id="countTous">0</span>
            </button>

            <button class="stagia-tab" data-status="ACTIF">
                Actifs <span id="countActifs">0</span>
            </button>

            <button class="stagia-tab" data-status="SUSPENDU">
                Suspendus <span id="countSuspendus">0</span>
            </button>

            <button class="stagia-tab" data-status="ARCHIVE">
                Archivés <span id="countArchives">0</span>
            </button>

        </div>


        <div class="stagia-list-filters">

            <div class="input-group stagia-table-search">

                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search"></i>
                </span>

                <input id="studentSearch"
                       class="form-control border-start-0"
                       placeholder="Nom, matricule, identifiant STAGIA...">

            </div>

            <select id="perPage" class="form-select stagia-per-page">
                <option>10</option>
                <option>25</option>
                <option>50</option>
            </select>

        </div>

    </div>


    <!-- FILTRES ACADÉMIQUES -->
    <div class="p-3 border-bottom">

        <div class="row g-2">

            <div class="col-md-3">

                <select id="faculteFilter" class="form-select">
                    <option value="">Toutes les facultés</option>

                    <?php foreach($facultes as $x): ?>
                        <option value="<?= (int)$x['id'] ?>">
                            <?= htmlspecialchars($x['nom']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-3">

                <select id="departementFilter" class="form-select">
                    <option value="">Tous les départements</option>

                    <?php foreach($departements as $x): ?>
                        <option value="<?= (int)$x['id'] ?>"
                                data-parent="<?= (int)$x['faculte_id'] ?>">
                            <?= htmlspecialchars($x['nom']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-3">

                <select id="filiereFilter" class="form-select">
                    <option value="">Toutes les filières</option>

                    <?php foreach($filieres as $x): ?>
                        <option value="<?= (int)$x['id'] ?>"
                                data-parent="<?= (int)$x['departement_id'] ?>">
                            <?= htmlspecialchars($x['nom']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-3">

                <select id="promotionFilter" class="form-select">
                    <option value="">Toutes les promotions</option>

                    <?php foreach($promotions as $x): ?>
                        <option value="<?= (int)$x['id'] ?>"
                                data-parent="<?= (int)$x['filiere_id'] ?>">
                            <?= htmlspecialchars($x['nom']) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

            </div>

        </div>

    </div>


    <!-- TABLEAU -->
    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">

            <thead>
                <tr>
                    <th>IDENTIFIANT</th>
                    <th>ÉTUDIANT</th>
                    <th>MATRICULE</th>
                    <th>FILIÈRE / PROMOTION</th>
                    <th>ANNÉE</th>
                    <th>STATUT</th>
                    <th class="text-center">ACTIONS</th>
                </tr>
            </thead>

            <tbody id="studentsBody"></tbody>

        </table>

    </div>


    <!-- PAGINATION -->
    <div class="stagia-list-footer">

        <span id="studentInfo">
            Affichage 0 sur 0
        </span>

        <nav>
            <ul id="studentPagination"
                class="pagination pagination-sm mb-0">
            </ul>
        </nav>

    </div>

</div>

</main>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>';
const CSRF='<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>';
const $=id=>document.getElementById(id);

let page=1,
    limit=10,
    search='',
    statut='',
    faculte='',
    departement='',
    filiere='',
    promotion='',
    timer,
    invitationPollTimer=null,
    invitationHideTimer=null,
    currentInvitationJob=null,
    invitationFinishedRefreshed=false;


/* =========================================================
   CHARGEMENT
========================================================= */
async function charger(p=1){

    const body=$('studentsBody');

    body.innerHTML=`
        <tr>
            <td colspan="7" class="text-center py-5">
                <div class="spinner-border spinner-border-sm me-2"></div>
                Chargement...
            </td>
        </tr>
    `;

    try{

        const q=new URLSearchParams({
            page:p,
            per_page:limit,
            search,
            statut,
            faculte_id:faculte,
            departement_id:departement,
            filiere_id:filiere,
            promotion_id:promotion
        });

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-list.php?'+
            q
        );

        const items=r.data.items||[];
        const pg=r.data.pagination||{};
        const s=r.data.stats||{};

        page=Number(pg.page||1);


        /* KPI */
        $('statTotal').textContent=s.total||0;
        $('statActifs').textContent=s.actifs||0;
        $('statTermines').textContent=s.termines||0;
        $('statArchives').textContent=s.archives||0;

        $('countTous').textContent=s.total||0;
        $('countActifs').textContent=s.actifs||0;
        $('countSuspendus').textContent=s.suspendus||0;
        $('countArchives').textContent=s.archives||0;


        $('studentInfo').textContent=
            pg.total
            ?`Affichage ${pg.from}–${pg.to} sur ${pg.total}`
            :'Aucun résultat';


        body.innerHTML=items.length
        ?items.map(x=>`

            <tr>

                <td>
                    <strong>
                        ${STAGIA.escape(x.stagia_code||'-')}
                    </strong>
                </td>


                <td>

                    <strong class="table-main-text">
                        ${STAGIA.escape(
                            [x.nom,x.postnom,x.prenom]
                            .filter(Boolean)
                            .join(' ')
                        )}
                    </strong>

                    <br>

                    <small class="text-muted">
                        ${
                            x.sexe==='M'
                            ?'Masculin'
                            :x.sexe==='F'
                            ?'Féminin'
                            :'-'
                        }
                    </small>

                </td>


                <td>
                    ${STAGIA.escape(x.matricule||'-')}
                </td>


                <td>

                    ${STAGIA.escape(x.filiere_nom||'-')}

                    <br>

                    <small class="text-muted">
                        ${STAGIA.escape(x.promotion_nom||'-')}
                    </small>

                </td>


                <td>
                    ${STAGIA.escape(x.annee_academique||'-')}
                </td>


                <td>

                    <span class="badge ${badge(x.statut)}">
                        ${STAGIA.escape(x.statut)}
                    </span>

                </td>


                <td class="text-center">

                    <a href="${BASE_URL}/views/etudiants/show.php?id=${x.enrollment_id}"
                       class="btn btn-sm btn-outline-primary"
                       title="Consulter la fiche">

                        <i class="bi bi-eye"></i>

                    </a>

                </td>

            </tr>

        `).join('')

        :`

            <tr>

                <td colspan="7"
                    class="text-center py-5 text-muted">

                    <i class="bi bi-people fs-2 d-block mb-2"></i>

                    Aucun étudiant trouvé.

                </td>

            </tr>
        `;


        pagination(pg);

    }catch(e){

        body.innerHTML=`
            <tr>
                <td colspan="7"
                    class="text-center py-5 text-danger">
                    ${STAGIA.escape(e.message)}
                </td>
            </tr>
        `;

        STAGIA.toast(e.message,'danger');
    }
}


/* =========================================================
   BADGES
========================================================= */
function badge(s){

    return s==='ACTIF'
        ?'bg-success'
        :s==='SUSPENDU'
        ?'bg-warning text-dark'
        :s==='TERMINE'
        ?'bg-primary'
        :'bg-secondary';
}


/* =========================================================
   PAGINATION
========================================================= */
function pagination(p){

    const el=$('studentPagination');
    const current=Number(p.page||1);
    const pages=Number(p.pages||1);

    if(pages<=1){
        el.innerHTML='';
        return;
    }

    let html=`
        <li class="page-item ${current<=1?'disabled':''}">
            <button class="page-link"
                    data-page="${current-1}">
                ‹
            </button>
        </li>
    `;

    for(
        let i=Math.max(1,current-2);
        i<=Math.min(pages,current+2);
        i++
    ){

        html+=`
            <li class="page-item ${i===current?'active':''}">
                <button class="page-link"
                        data-page="${i}">
                    ${i}
                </button>
            </li>
        `;
    }

    html+=`
        <li class="page-item ${current>=pages?'disabled':''}">
            <button class="page-link"
                    data-page="${current+1}">
                ›
            </button>
        </li>
    `;

    el.innerHTML=html;

    el.querySelectorAll('[data-page]')
      .forEach(btn=>{
          btn.onclick=()=>charger(
              Number(btn.dataset.page)
          );
      });
}


/* =========================================================
   FILTRES DÉPENDANTS
========================================================= */
function limiter(select,parent){

    [...select.options].forEach(option=>{

        if(option.value){
            option.hidden=
                !!parent &&
                option.dataset.parent!==parent;
        }
    });

    select.value='';
}


$('faculteFilter').onchange=e=>{

    faculte=e.target.value;
    departement=filiere=promotion='';

    limiter(
        $('departementFilter'),
        faculte
    );

    limiter(
        $('filiereFilter'),
        ''
    );

    limiter(
        $('promotionFilter'),
        ''
    );

    charger(1);
};


$('departementFilter').onchange=e=>{

    departement=e.target.value;
    filiere=promotion='';

    limiter(
        $('filiereFilter'),
        departement
    );

    limiter(
        $('promotionFilter'),
        ''
    );

    charger(1);
};


$('filiereFilter').onchange=e=>{

    filiere=e.target.value;
    promotion='';

    limiter(
        $('promotionFilter'),
        filiere
    );

    charger(1);
};


$('promotionFilter').onchange=e=>{

    promotion=e.target.value;
    charger(1);
};


/* =========================================================
   RECHERCHE
========================================================= */
$('studentSearch').oninput=e=>{

    clearTimeout(timer);

    timer=setTimeout(()=>{

        search=e.target.value.trim();
        charger(1);

    },300);
};


$('perPage').onchange=e=>{

    limit=Number(e.target.value);
    charger(1);
};


/* =========================================================
   ONGLETS STATUT
========================================================= */
document.querySelectorAll('.stagia-tab')
.forEach(tab=>{

    tab.onclick=()=>{

        document.querySelectorAll('.stagia-tab')
        .forEach(x=>x.classList.remove('active'));

        tab.classList.add('active');

        statut=tab.dataset.status;
        charger(1);
    };
});


/* =========================================================
   INVITATIONS - PROGRESSION ARRIÈRE-PLAN
========================================================= */
function invitationIsRunning(status){
    return ['PENDING','RUNNING'].includes(status);
}

function invitationStatusLabel(status){
    return {
        PENDING:'EN ATTENTE',
        RUNNING:'EN COURS',
        COMPLETED:'TERMINÉ',
        COMPLETED_WITH_ERRORS:'TERMINÉ AVEC ERREURS',
        FAILED:'ÉCHEC'
    }[status]||status||'-';
}

function invitationBadge(status){
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

function afficherProgressionInvitations(job){
    if(!job){
        $('invitationProgressCard').classList.add('d-none');
        $('sendInvitationsBtn').disabled=false;
        return;
    }

    currentInvitationJob=job.uuid;
    $('invitationProgressCard').classList.remove('d-none');

    $('invitationTotal').textContent=Number(job.total||0);
    $('invitationProcessed').textContent=Number(job.processed||0);
    $('invitationSent').textContent=Number(job.sent||0);
    $('invitationFailed').textContent=Number(job.failed||0);
    $('invitationRemaining').textContent=Number(job.remaining||0);

    const progress=Math.max(0,Math.min(100,Number(job.progress||0)));
    const bar=$('invitationProgressBar');

    bar.style.width=progress+'%';
    bar.setAttribute('aria-valuenow',progress);
    bar.textContent=progress>=12?progress+'%':'';
    bar.classList.toggle('progress-bar-animated',invitationIsRunning(job.statut));

    const badge=$('invitationStatusBadge');
    badge.className='badge '+invitationBadge(job.statut);
    badge.textContent=invitationStatusLabel(job.statut);

    $('invitationProgressMessage').textContent=
        invitationIsRunning(job.statut)
        ?`${job.processed||0} étudiant(s) traité(s) sur ${job.total||0}.`
        :job.statut==='COMPLETED'
        ?'Toutes les invitations ont été traitées.'
        :job.statut==='COMPLETED_WITH_ERRORS'
        ?`Envoi terminé avec ${job.failed||0} échec(s).`
        :job.statut==='FAILED'
        ?"Le traitement s'est arrêté sur une erreur."
        :'';

    $('invitationCurrentStudent').textContent=
        job.current_student
        ?`Envoi en cours : ${job.current_student}`
        :job.skipped
        ?`${job.skipped} étudiant(s) ignoré(s).`
        :'';

    const errors=Array.isArray(job.errors)?job.errors:[];
    const errorBox=$('invitationErrors');

    if(errors.length){
        errorBox.classList.remove('d-none');
        errorBox.innerHTML=`
            <div class="alert alert-warning mb-0">
                <strong>Derniers échecs</strong>
                <ul class="mb-0 mt-2">
                    ${errors.map(x=>`
                        <li>
                            ${STAGIA.escape(x.student||'-')}
                            ${x.email?' · '+STAGIA.escape(x.email):''}
                            — ${STAGIA.escape(x.error_message||'Échec')}
                        </li>
                    `).join('')}
                </ul>
            </div>
        `;
    }else{
        errorBox.classList.add('d-none');
        errorBox.innerHTML='';
    }

    const running=invitationIsRunning(job.statut);
    $('sendInvitationsBtn').disabled=running;

    if(running){

        if(invitationHideTimer){
            clearTimeout(invitationHideTimer);
            invitationHideTimer=null;
        }

        $('sendInvitationsBtn').innerHTML=`
            <span class="spinner-border spinner-border-sm me-1"></span>
            Envoi en cours...
        `;
    }else{
        $('sendInvitationsBtn').innerHTML=`
            <i class="bi bi-envelope-arrow-up me-1"></i>
            Envoyer les invitations
        `;

        if(!invitationFinishedRefreshed){
            invitationFinishedRefreshed=true;
            charger(page);
        }

        if(!invitationHideTimer){
            invitationHideTimer=setTimeout(()=>{
                $('invitationProgressCard').classList.add('d-none');
                currentInvitationJob=null;
                invitationHideTimer=null;
            },4000);
        }
    }
}

async function chargerStatutInvitations(){
    try{
        const q=currentInvitationJob
            ?'?job_uuid='+encodeURIComponent(currentInvitationJob)
            :'';

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-invitations-status.php'+
            q
        );

        const job=r.data.job||null;

        if(!job){
            $('invitationProgressCard').classList.add('d-none');
            currentInvitationJob=null;
            $('sendInvitationsBtn').disabled=false;
        }else{
            afficherProgressionInvitations(job);
        }

        if(job && invitationIsRunning(job.statut)){
            if(!invitationPollTimer){
                invitationPollTimer=setInterval(chargerStatutInvitations,1500);
            }
        }else if(invitationPollTimer){
            clearInterval(invitationPollTimer);
            invitationPollTimer=null;
        }

    }catch(e){
        console.error('Statut invitations :',e);
    }
}

$('sendInvitationsBtn').onclick=async function(){
    if(!confirm(
        "Créer les comptes STAGIA manquants et envoyer les liens d'activation aux étudiants concernés ?"
    )){
        return;
    }

    this.disabled=true;

    try{
        const form=new FormData();
        form.append('csrf',CSRF);

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/etudiants/student-invitations-send.php',
            form
        );

        const data=r.data||{};
        STAGIA.toast(r.message,'success');

        if(!data.job_uuid){
            this.disabled=false;
            await charger(page);
            return;
        }

        currentInvitationJob=data.job_uuid;
        invitationFinishedRefreshed=false;

        afficherProgressionInvitations({
            uuid:data.job_uuid,
            statut:data.statut||'PENDING',
            total:data.total||0,
            processed:data.processed||0,
            sent:data.sent||0,
            failed:data.failed||0,
            skipped:data.skipped||0,
            remaining:data.remaining??data.total??0,
            progress:data.progress||0,
            current_student:null,
            errors:[]
        });

        await chargerStatutInvitations();

    }catch(e){
        this.disabled=false;
        this.innerHTML=`
            <i class="bi bi-envelope-arrow-up me-1"></i>
            Envoyer les invitations
        `;
        STAGIA.toast(e.message,'danger');
    }
};

/* Reprendre automatiquement un job après retour sur cette page. */
chargerStatutInvitations();

/* Premier chargement */
charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
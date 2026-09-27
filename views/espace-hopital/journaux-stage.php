<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL','ENCADREUR','EVALUATEUR_CLINIQUE']);
requirePermission($pdo,'supervision.hosting.view');

if(!contextHostEnabled()){
    http_response_code(403);
    exit("Cet établissement n'est pas une structure d'accueil.");
}

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Journaux de stage';
$activePage='hospital-logbook';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.logbook-summary{max-width:330px}
.logbook-detail{background:#f8fafc;border:1px solid #e5eaf0;border-radius:10px;padding:13px}
.logbook-activity{border:1px solid #e3e8ee;border-radius:10px;padding:12px;margin-bottom:9px}
.logbook-activity:last-child{margin-bottom:0}
</style>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Journaux de stage</h1>
        <p>Consultez et validez les activités quotidiennes des stagiaires.</p>
    </div>
</div>

<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>JOURNAUX</span>
            <strong id="statTotal">0</strong>
            <small>Entrées enregistrées</small>
        </div>
        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-journal-medical"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>À VALIDER</span>
            <strong id="statSubmitted">0</strong>
            <small>En attente de validation</small>
        </div>
        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>VALIDÉS</span>
            <strong id="statValidated">0</strong>
            <small>Journaux approuvés</small>
        </div>
        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-patch-check"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>REJETÉS</span>
            <strong id="statRejected">0</strong>
            <small>À corriger par l'étudiant</small>
        </div>
        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-arrow-counterclockwise"></i>
        </div>
    </div>

</div>

<!-- LISTE -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">
        <div>
            <h5 class="mb-1">Suivi des journaux</h5>
            <small class="text-muted">Entrées soumises par les stagiaires.</small>
        </div>

        <div style="max-width:320px;width:100%">
            <input type="search"
                   id="search"
                   class="form-control"
                   placeholder="Rechercher un stagiaire...">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
            <tr>
                <th>STAGIAIRE</th>
                <th>DATE</th>
                <th>ROTATION / SERVICE</th>
                <th>RÉSUMÉ</th>
                <th>ACTIVITÉS</th>
                <th>STATUT</th>
                <th class="text-center">ACTION</th>
            </tr>
            </thead>

            <tbody id="journalBody">
            <tr>
                <td colspan="7" class="text-center py-5">
                    Chargement...
                </td>
            </tr>
            </tbody>
        </table>
    </div>

</div>

</main>


<!-- =========================================================
     MODAL CONSULTATION / VALIDATION
========================================================= -->
<div class="modal fade" id="journalModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content" style="max-height:calc(100vh - 30px);overflow:hidden">

    <div class="modal-header">
        <div>
            <h5 class="modal-title">Journal de stage</h5>
            <small id="studentLabel" class="text-muted"></small>
        </div>

        <button type="button"
                class="btn-close"
                data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body" style="overflow-y:auto;max-height:calc(100vh - 180px)">

        <input type="hidden" id="journalId">

        <div class="row g-3 mb-3">

            <div class="col-md-4">
                <div class="logbook-detail">
                    <small class="text-muted">Date</small>
                    <div class="fw-bold" id="journalDate">-</div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="logbook-detail">
                    <small class="text-muted">Rotation</small>
                    <div class="fw-bold" id="journalRotation">-</div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="logbook-detail">
                    <small class="text-muted">Statut</small>
                    <div id="journalStatus">-</div>
                </div>
            </div>

        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Résumé des activités</label>
            <div class="logbook-detail" id="journalSummary">-</div>
        </div>

        <div class="row g-3 mb-3">

            <div class="col-md-6">
                <label class="form-label fw-semibold">Apprentissages</label>
                <div class="logbook-detail" id="journalLearning">-</div>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Difficultés</label>
                <div class="logbook-detail" id="journalDifficulties">-</div>
            </div>

        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Observation de l'étudiant</label>
            <div class="logbook-detail" id="journalObservation">-</div>
        </div>

        <hr>

        <div class="mb-3">
            <h6>Activités réalisées</h6>
            <div id="activityContainer"></div>
        </div>

        <div id="reviewBox" class="d-none">
            <hr>

            <label class="form-label fw-semibold">
                Commentaire de l'encadreur
            </label>

            <textarea id="reviewComment"
                      class="form-control"
                      rows="3"
                      placeholder="Commentaire facultatif pour validation, obligatoire en cas de rejet."></textarea>
        </div>

        <div id="existingCommentBox" class="d-none">
            <hr>

            <label class="form-label fw-semibold">
                Commentaire de l'encadreur
            </label>

            <div class="logbook-detail" id="existingComment"></div>
        </div>

    </div>

    <div class="modal-footer" id="modalFooter">
        <button type="button"
                class="btn btn-light"
                data-bs-dismiss="modal">
            Fermer
        </button>

        <button type="button"
                class="btn btn-outline-danger d-none"
                id="rejectBtn">
            <i class="bi bi-x-lg me-1"></i>
            Rejeter
        </button>

        <button type="button"
                class="btn btn-success d-none"
                id="validateBtn">
            <i class="bi bi-check-lg me-1"></i>
            Valider
        </button>
    </div>

</div>
</div>
</div>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      CSRF='<?= $_SESSION['csrf'] ?>',
      $=id=>document.getElementById(id),
      modal=new bootstrap.Modal($('journalModal'));

let items=[];

/* CHARGEMENT */
async function charger(){
    try{
        const r=await STAGIA.request(
            BASE_URL+'/actions/stages/host-logbook-list.php'
        );

        items=r.data.items||[];
        const s=r.data.stats||{};

        $('statTotal').textContent=s.total||0;
        $('statSubmitted').textContent=s.soumis||0;
        $('statValidated').textContent=s.valides||0;
        $('statRejected').textContent=s.rejetes||0;

        afficher();

    }catch(e){
        $('journalBody').innerHTML=`
            <tr><td colspan="7" class="text-center py-5 text-danger">
                ${STAGIA.escape(e.message)}
            </td></tr>`;
    }
}

/* RECHERCHE */
function afficher(){
    const q=$('search').value.trim().toLowerCase();

    render(items.filter(x=>{
        if(!q) return true;

        return [
            x.nom,x.postnom,x.prenom,x.stagia_code,
            x.university_name,x.unit_name,x.campaign_title,
            x.resume_activites,x.statut
        ]
        .filter(Boolean).join(' ').toLowerCase().includes(q);
    }));
}

/* TABLEAU */
function render(list){
    if(!list.length){
        $('journalBody').innerHTML=`
            <tr><td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-journal-medical fs-2 d-block mb-2"></i>
                Aucun journal disponible.
            </td></tr>`;
        return;
    }

    $('journalBody').innerHTML=list.map(x=>`
        <tr>
            <td>
                <strong>${STAGIA.escape(
                    [x.nom,x.postnom,x.prenom].filter(Boolean).join(' ')
                )}</strong>
                <div class="small text-muted">
                    ${STAGIA.escape(x.stagia_code||'-')}
                </div>
            </td>

            <td>${dateFr(x.date_journal)}</td>

            <td>
                <strong>${STAGIA.escape(x.unit_name||'-')}</strong>
                <div class="small text-muted">
                    Rotation ${Number(x.sequence_no||0)}
                    ${x.unit_code?' • '+STAGIA.escape(x.unit_code):''}
                </div>
            </td>

            <td>
                <div class="text-truncate logbook-summary">
                    ${STAGIA.escape(x.resume_activites||'-')}
                </div>
            </td>

            <td>
                <span class="badge bg-light text-dark">
                    ${Number(x.activities_count||0)} activité(s)
                </span>
            </td>

            <td>${badge(x.statut)}</td>

            <td class="text-center">
                <button type="button"
                        class="btn btn-sm btn-outline-secondary btn-view"
                        data-id="${Number(x.id)}">
                    <i class="bi bi-eye me-1"></i>
                    Voir
                </button>
            </td>
        </tr>
    `).join('');
}

/* OUVRIR */
$('journalBody').addEventListener('click',e=>{
    const btn=e.target.closest('.btn-view');
    if(!btn) return;

    const x=items.find(v=>Number(v.id)===Number(btn.dataset.id));
    if(x) ouvrir(x);
});

function ouvrir(x){
    $('journalId').value=x.id;

    $('studentLabel').textContent=
        [x.nom,x.postnom,x.prenom].filter(Boolean).join(' ');

    $('journalDate').textContent=dateFr(x.date_journal);
    $('journalRotation').textContent=
        `Rotation ${Number(x.sequence_no||0)} - ${x.unit_name||'-'}`;

    $('journalStatus').innerHTML=badge(x.statut);
    $('journalSummary').textContent=x.resume_activites||'-';
    $('journalLearning').textContent=x.apprentissages||'-';
    $('journalDifficulties').textContent=x.difficultes||'-';
    $('journalObservation').textContent=x.observation_etudiant||'-';

    renderActivities(x.activities||[]);

    const submitted=x.statut==='SOUMIS' && Number(x.can_review)===1;

    $('reviewBox').classList.toggle('d-none',!submitted);
    $('validateBtn').classList.toggle('d-none',!submitted);
    $('rejectBtn').classList.toggle('d-none',!submitted);

    $('reviewComment').value='';

    const hasComment=!!x.commentaire_encadreur && x.statut!=='SOUMIS';

    $('existingCommentBox').classList.toggle('d-none',!hasComment);
    $('existingComment').textContent=x.commentaire_encadreur||'';

    modal.show();
}

/* ACTIVITÉS */
function renderActivities(list){
    if(!list.length){
        $('activityContainer').innerHTML=`
            <div class="text-muted border rounded-3 p-3">
                Aucune activité enregistrée.
            </div>`;
        return;
    }

    $('activityContainer').innerHTML=list.map((a,i)=>`
        <div class="logbook-activity">
            <div class="d-flex justify-content-between gap-2 mb-2">
                <strong>
                    ${i+1}. ${STAGIA.escape(a.intitule||'-')}
                </strong>
                <span class="badge bg-light text-dark">
                    ${STAGIA.escape(typeLabel(a.type_activite))}
                </span>
            </div>

            ${a.description?`
                <div class="small mb-2">
                    ${STAGIA.escape(a.description)}
                </div>`:''}

            <div class="small text-muted">
                Implication :
                <strong>${STAGIA.escape(levelLabel(a.niveau_implication))}</strong>
                • Quantité :
                <strong>${Number(a.quantite||1)}</strong>
            </div>

            ${a.observation?`
                <div class="small text-muted mt-1">
                    ${STAGIA.escape(a.observation)}
                </div>`:''}
        </div>
    `).join('');
}

/* VALIDATION / REJET */
$('validateBtn').addEventListener('click',()=>review('VALIDER'));
$('rejectBtn').addEventListener('click',()=>review('REJETER'));

async function review(decision){
    const commentaire=$('reviewComment').value.trim();

    if(decision==='REJETER' && !commentaire){
        STAGIA.toast(
            'Le commentaire est obligatoire pour rejeter le journal.',
            'danger'
        );
        return;
    }

    const message=decision==='VALIDER'
        ?'Valider définitivement ce journal ?'
        :'Renvoyer ce journal à l’étudiant pour correction ?';

    if(!confirm(message)) return;

    const f=new FormData();
    f.append('csrf',CSRF);
    f.append('id',$('journalId').value);
    f.append('decision',decision);
    f.append('commentaire',commentaire);

    const btn=decision==='VALIDER' ? $('validateBtn') : $('rejectBtn');
    STAGIA.loading(btn,true);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/stages/host-logbook-review.php',
            f
        );

        modal.hide();
        STAGIA.toast(r.message);
        await charger();

    }catch(e){
        STAGIA.toast(e.message,'danger');

    }finally{
        STAGIA.loading(btn,false);
    }
}

/* HELPERS */
function badge(s){
    return {
        BROUILLON:'<span class="badge bg-secondary">BROUILLON</span>',
        SOUMIS:'<span class="badge bg-warning text-dark">À VALIDER</span>',
        VALIDE:'<span class="badge bg-success">VALIDÉ</span>',
        REJETE:'<span class="badge bg-danger">REJETÉ</span>'
    }[s]||'<span class="badge bg-secondary">-</span>';
}

function typeLabel(v){
    return {
        OBSERVATION:'Observation',
        PARTICIPATION:'Participation',
        REALISATION:'Réalisation',
        GARDE:'Garde',
        CONSULTATION:'Consultation',
        AUTRE:'Autre'
    }[v]||v||'-';
}

function levelLabel(v){
    return {
        OBSERVE:'Observé',
        ASSISTE:'Assisté',
        REALISE_SUPERVISE:'Réalisé sous supervision',
        REALISE_AUTONOME:'Réalisé en autonomie'
    }[v]||'-';
}

function dateFr(v){
    if(!v) return '-';
    const p=String(v).substring(0,10).split('-');
    return p.length===3 ? `${p[2]}/${p[1]}/${p[0]}` : v;
}

$('search').addEventListener('input',afficher);
charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
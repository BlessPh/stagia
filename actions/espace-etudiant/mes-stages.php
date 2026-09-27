<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'stage.self.view');

$pageTitle='Mes stages';
$activePage='student-internships';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Mes stages</h1>
        <p>Consultez vos stages planifiés, en cours et terminés, ainsi que votre parcours de rotations.</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stagia-list-card p-3 h-100">
            <small class="text-muted">TOTAL</small>
            <h3 id="kTotal" class="mb-1">0</h3>
            <small class="text-muted">Stages enregistrés</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stagia-list-card p-3 h-100">
            <small class="text-muted">PLANIFIÉS</small>
            <h3 id="kPlanned" class="mb-1">0</h3>
            <small class="text-muted">À venir</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stagia-list-card p-3 h-100">
            <small class="text-muted">EN COURS</small>
            <h3 id="kActive" class="mb-1">0</h3>
            <small class="text-muted">Stages actifs</small>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stagia-list-card p-3 h-100">
            <small class="text-muted">VALIDÉS</small>
            <h3 id="kValidated" class="mb-1">0</h3>
            <small class="text-muted">Stages terminés</small>
        </div>
    </div>
</div>

<div class="stagia-list-card">
    <div class="p-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="mb-1">Historique de mes stages</h5>
            <small class="text-muted">Retrouvez vos stages réellement placés et leur parcours publié.</small>
        </div>

        <div style="min-width:260px">
            <input id="search" class="form-control" placeholder="Rechercher un stage...">
        </div>
    </div>

    <div id="stageList" class="p-3"></div>
</div>

</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>';
const $=id=>document.getElementById(id);
const esc=v=>STAGIA.escape(v??'');

let data={summary:{total:0,planned:0,active:0,validated:0},stages:[]};

function frDate(v){
    if(!v)return '—';
    const [y,m,d]=String(v).slice(0,10).split('-');
    return `${d}/${m}/${y}`;
}

function stageStatusBadge(status){
    const map={
        PLANIFIE:['Planifié','bg-warning-subtle text-warning'],
        EN_COURS:['Stage en cours','bg-success-subtle text-success'],
        A_CLOTURER:['À clôturer','bg-info-subtle text-info'],
        VALIDE:['Validé','bg-success text-white'],
        PLACEMENT_CONFIRME:['Placement confirmé','bg-primary-subtle text-primary']
    };
    const x=map[status]||[status,'bg-light text-dark'];
    return `<span class="badge ${x[1]}">${esc(x[0])}</span>`;
}

function rotationBadge(status){
    const map={
        PLANIFIEE:['Planifiée','bg-light text-dark'],
        ACTIVE:['Active','bg-success-subtle text-success'],
        TERMINEE:['Terminée','bg-primary-subtle text-primary']
    };
    const x=map[status]||[status,'bg-light text-dark'];
    return `<span class="badge ${x[1]}">${esc(x[0])}</span>`;
}

function render(){
    $('kTotal').textContent=data.summary.total||0;
    $('kPlanned').textContent=data.summary.planned||0;
    $('kActive').textContent=data.summary.active||0;
    $('kValidated').textContent=data.summary.validated||0;

    const q=$('search').value.trim().toLowerCase();

    const stages=(data.stages||[]).filter(s=>{
        if(!q)return true;
        return [
            s.campaign_code,s.campaign_title,s.host_name,
            s.initial_unit_name,s.promotion_name,s.group_name
        ].filter(Boolean).join(' ').toLowerCase().includes(q);
    });

    $('stageList').innerHTML=stages.length
        ?stages.map(stageCard).join('')
        :'<div class="text-center text-muted py-5">Aucun stage correspondant.</div>';
}

function stageCard(s){
    const isD4=s.stage_type_code==='MEDICAL_D4';
    const location=[s.host_city,s.host_province].filter(Boolean).join(' · ');

    const rotations=(s.rotations||[]);

    return `<div class="border rounded-3 mb-4 overflow-hidden bg-white">
        <div class="p-3 border-bottom">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <small class="text-muted">${esc(s.campaign_code)}</small>
                    <h5 class="mb-2">${esc(s.campaign_title)}</h5>

                    <div class="small mb-1">
                        <i class="bi bi-hospital text-warning me-1"></i>
                        <strong>${esc(s.host_name)}</strong>
                        ${location?' · '+esc(location):''}
                    </div>

                    <div class="small">
                        <i class="bi bi-diagram-3 text-warning me-1"></i>
                        Affectation initiale :
                        <strong>${esc(s.initial_unit_name||'Non renseignée')}</strong>
                    </div>

                    ${isD4 && s.group_name
                        ?`<div class="small mt-1">
                            <i class="bi bi-people text-warning me-1"></i>
                            Groupe :
                            <strong>${esc(s.group_name)}</strong>
                            ${s.group_code?' · '+esc(s.group_code):''}
                          </div>`
                        :''}
                </div>

                <div>${stageStatusBadge(s.effective_status)}</div>
            </div>
        </div>

        <div class="p-3">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <small class="text-muted">Période</small>
                        <div class="fw-semibold mt-1">
                            ${frDate(s.date_debut)} → ${frDate(s.date_fin)}
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <small class="text-muted">Statut</small>
                        <div class="mt-2">${stageStatusBadge(s.effective_status)}</div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <small class="text-muted">Taux de présence</small>
                        <div class="fw-semibold mt-1">
                            ${s.taux_presence!==null && s.taux_presence!==undefined
                                ?esc(s.taux_presence)+' %'
                                :'—'}
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="border rounded p-3 h-100">
                        <small class="text-muted">Note finale</small>
                        <div class="fw-semibold mt-1">
                            ${s.note_finale!==null && s.note_finale!==undefined
                                ?esc(s.note_finale)
                                :'—'}
                        </div>
                    </div>
                </div>
            </div>

            ${isD4 ? d4Path(s,rotations) : ''}

            ${executionPanel(s)}

            <div class="d-flex flex-wrap gap-2 mt-3">
                ${executionButton(
                    s,
                    'logbook',
                    'bi-journal-medical',
                    'Journal',
                    BASE_URL+'/views/espace-etudiant/journal-stage.php'
                )}

                ${executionButton(
                    s,
                    'attendance',
                    'bi-calendar-check',
                    'Présences',
                    BASE_URL+'/views/espace-etudiant/presences.php'
                )}

                ${executionButton(
                    s,
                    'evaluation',
                    'bi-clipboard-check',
                    'Évaluations',
                    BASE_URL+'/views/espace-etudiant/evaluations.php'
                )}
            </div>
        </div>
    </div>`;
}

function executionButton(s,type,icon,label,url){
    const a=s.execution_access||{};

    const allowed=
        type==='logbook' ? !!a.can_logbook :
        type==='attendance' ? !!a.can_attendance :
        !!a.can_evaluation;

    if(allowed){
        return `<a class="btn btn-sm btn-outline-primary"
                   href="${url}?assignment_id=${encodeURIComponent(s.assignment_id||'')}">
                    <i class="bi ${icon} me-1"></i>${label}
                </a>`;
    }

    return `<button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    disabled
                    title="${esc(a.reason||'Indisponible pour le moment')}">
                <i class="bi bi-lock-fill me-1"></i>${label}
            </button>`;
}

function executionPanel(s){
    if(s.stage_type_code!=='MEDICAL_D4' || !s.assignment_id){
        return '';
    }

    const a=s.execution_access||{};
    const current=a.current_rotation||null;
    const next=a.next_rotation||null;

    if(current){
        return `<div class="alert alert-success py-2 mt-3 mb-0">
            <strong>
                <i class="bi bi-play-circle-fill me-1"></i>
                Rotation active :
                ${esc(current.unit_name)}
            </strong>
            <span class="ms-2">
                ${frDate(current.date_debut)} → ${frDate(current.date_fin)}
            </span>
            ${current.supervisor_name
                ?`<span class="ms-2">· Encadreur : ${esc(current.supervisor_name)}</span>`
                :''}
        </div>`;
    }

    if(next){
        return `<div class="alert alert-warning py-2 mt-3 mb-0">
            <strong>
                <i class="bi bi-lock-fill me-1"></i>
                Suivi verrouillé
            </strong>
            jusqu’au <strong>${frDate(next.date_debut)}</strong>.
            Prochaine rotation :
            <strong>${esc(next.unit_name)}</strong>.
        </div>`;
    }

    const hasEnded=(s.rotations||[]).some(r=>r.statut==='TERMINEE');

    if(hasEnded){
        return `<div class="alert alert-info py-2 mt-3 mb-0">
            Les rotations planifiées sont terminées.
            Les journaux et présences ne sont plus ouverts en écriture.
        </div>`;
    }

    return `<div class="alert alert-light border py-2 mt-3 mb-0">
        Aucune rotation active aujourd’hui.
    </div>`;
}

function d4Path(s,rotations){
    if(!s.group_id){
        return `<div class="alert alert-warning mb-0">
            Votre placement D4 est confirmé, mais aucun groupe publié ne vous est encore associé.
        </div>`;
    }

    if(!rotations.length){
        return `<div class="alert alert-warning mb-0">
            Groupe <strong>${esc(s.group_name)}</strong> : le calendrier de rotations n'est pas encore disponible.
        </div>`;
    }

    return `<div class="border rounded-3 overflow-hidden mt-3">
        <div class="p-3 bg-light border-bottom">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>
                        <i class="bi bi-arrow-repeat text-warning me-1"></i>
                        Parcours D4 publié
                    </strong>
                    <small class="d-block text-muted mt-1">
                        ${esc(s.group_name)}
                        ${s.group_code?' · '+esc(s.group_code):''}
                        · ${rotations.length} rotation(s)
                    </small>
                </div>
            </div>
        </div>

        <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
            <tr>
                <th style="width:60px">#</th>
                <th>SERVICE / UNITÉ</th>
                <th>PÉRIODE</th>
                <th>ENCADREUR</th>
                <th>STATUT</th>
            </tr>
            </thead>
            <tbody>
            ${rotations.map(r=>`<tr>
                <td><strong>${r.sequence_no}</strong></td>

                <td>
                    <strong>${esc(r.unit_name)}</strong>
                    <small class="d-block text-muted">
                        ${esc(r.unit_code||'')} ${r.unit_type?'· '+esc(r.unit_type):''}
                    </small>
                </td>

                <td>
                    ${frDate(r.date_debut)} → ${frDate(r.date_fin)}
                </td>

                <td>
                    ${r.supervisor_name
                        ?`<strong>${esc(r.supervisor_name)}</strong>
                          <small class="d-block text-muted">${esc(r.supervisor_function||'Encadreur')}</small>`
                        :'—'}
                </td>

                <td>${rotationBadge(r.statut)}</td>
            </tr>`).join('')}
            </tbody>
        </table>
        </div>
    </div>`;
}

async function load(){
    try{
        const r=await STAGIA.request(
            BASE_URL+'/actions/etudiants/student-my-stages.php'
        );
        data=r.data;
        render();
    }catch(e){
        STAGIA.toast(e.message,'danger');
        $('stageList').innerHTML=
            `<div class="alert alert-danger">${esc(e.message)}</div>`;
    }
}

$('search').oninput=render;
load();
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

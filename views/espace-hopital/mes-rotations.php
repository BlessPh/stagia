<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requireRole(['ENCADREUR','EVALUATEUR_CLINIQUE']);

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Mes stagiaires / rotations';
$activePage='encadreur-mes-rotations';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Mes stagiaires / rotations</h1>
        <p>Liste des stagiaires et rotations qui vous sont confiés.</p>
    </div>
    <button class="btn btn-outline-primary" id="refreshBtn">
        <i class="bi bi-arrow-clockwise me-1"></i>Actualiser
    </button>
</div>

<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card">
        <div><span>À SUIVRE</span><strong id="kFollow">0</strong><small>Actives + planifiées</small></div>
        <div class="stagia-kpi-icon kpi-blue"><i class="bi bi-person-lines-fill"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>ACTIVES</span><strong id="kActive">0</strong><small>En cours</small></div>
        <div class="stagia-kpi-icon kpi-green"><i class="bi bi-play-circle"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>PLANIFIÉES</span><strong id="kPlanned">0</strong><small>À venir</small></div>
        <div class="stagia-kpi-icon kpi-orange"><i class="bi bi-clock"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>TERMINÉES</span><strong id="kDone">0</strong><small>Clôturées</small></div>
        <div class="stagia-kpi-icon kpi-purple"><i class="bi bi-check2-circle"></i></div>
    </div>
</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div class="stagia-list-filters w-100">
            <select id="status" class="form-select" style="max-width:220px">
                <option value="A_SUIVRE" selected>À suivre</option>
                <option value="ACTIVE">Actives</option>
                <option value="PLANIFIEE">Planifiées</option>
                <option value="TERMINEE">Terminées</option>
            </select>

            <div class="input-group stagia-table-search flex-grow-1">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                <input id="search" class="form-control border-start-0" placeholder="Nom, matricule, session, service...">
            </div>

            <button class="btn btn-primary" id="filterBtn">
                <i class="bi bi-search me-1"></i>Filtrer
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
            <tr>
                <th>Stagiaire</th>
                <th>Université / session</th>
                <th>Service</th>
                <th>Rotation</th>
                <th>Période</th>
                <th>Statut</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody id="rows">
                <tr><td colspan="7" class="text-center py-5">Chargement...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="stagia-list-footer d-flex justify-content-between align-items-center">
        <span id="footerCount">Page 1/1 · 0 résultat(s)</span>
        <div>
            <button class="btn btn-sm btn-light border" disabled>Précédent</button>
            <button class="btn btn-sm btn-light border" disabled>Suivant</button>
        </div>
    </div>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
let timer=null,items=[];

function dateFr(v){
    if(!v)return '—';
    const p=String(v).substring(0,10).split('-');
    return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:esc(v);
}

function badge(s){
    return {
        ACTIVE:'<span class="badge bg-success">ACTIVE</span>',
        PLANIFIEE:'<span class="badge bg-warning text-dark">PLANIFIÉE</span>',
        TERMINEE:'<span class="badge bg-secondary">TERMINÉE</span>',
        ANNULEE:'<span class="badge bg-danger">ANNULÉE</span>'
    }[s]||`<span class="badge bg-light text-dark border">${esc(s||'—')}</span>`;
}

async function load(){
    const qs=new URLSearchParams({
        status:$('status').value||'A_SUIVRE',
        q:$('search').value.trim()
    });

    try{
        const r=await STAGIA.request(BASE+'/actions/espace-hopital/encadreur-rotations-list.php?'+qs);
        const d=r.data||{},s=d.stats||{};
        items=d.items||[];

        $('kFollow').textContent=s.a_suivre||0;
        $('kActive').textContent=s.actives||0;
        $('kPlanned').textContent=s.planifiees||0;
        $('kDone').textContent=s.terminees||0;
        $('footerCount').textContent=`Page 1/1 · ${items.length} résultat(s)`;

        render();
    }catch(e){
        $('rows').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

function render(){
    if(!items.length){
        $('rows').innerHTML='<tr><td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-person-lines-fill fs-2 d-block mb-2"></i>Aucune rotation confiée.</td></tr>';
        return;
    }

    $('rows').innerHTML=items.map(x=>{
        const rid=Number(x.rotation_id||0);
        const aid=Number(x.assignment_id||0);
        const date=encodeURIComponent(String(x.date_debut||''));
        const baseParams=`rotation_id=${rid}&assignment_id=${aid}`;
        return `
        <tr>
            <td>
                <strong>${esc(x.stagiaire||'—')}</strong>
                <small class="d-block text-muted">${esc(x.matricule||x.stagia_code||'')}</small>
            </td>
            <td>
                <strong>${esc(x.universite||'—')}</strong>
                <small class="d-block text-muted">${esc(x.campaign_code||'—')} · ${esc(x.campaign_title||'')}</small>
            </td>
            <td>
                <strong>${esc(x.service||'—')}</strong>
                <small class="d-block text-muted">${esc(x.service_code||x.service_type||'')}</small>
            </td>
            <td>
                <strong>Rotation ${Number(x.sequence_no||0)}</strong>
                <small class="d-block text-muted">${esc(x.role_supervision||'ENCADREUR')}${Number(x.principal)===1?' · Principal':''}</small>
            </td>
            <td>${dateFr(x.date_debut)} → ${dateFr(x.date_fin)}</td>
            <td>${badge(x.statut)}</td>
                    <td class="text-end">
                ${(()=>{
                    const rid=Number(x.rotation_id||x.id||0);
                    const aid=Number(x.assignment_id||0);

                    if(!rid){
                        return '<span class="badge bg-danger">Rotation introuvable</span>';
                    }

                    return `<div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-primary"
                        href="${BASE}/views/espace-hopital/journaux-stage.php?rotation_id=${rid}&assignment_id=${aid}">
                            Journal
                        </a>

                        <a class="btn btn-outline-secondary"
                        href="${BASE}/views/espace-hopital/presences.php?rotation_id=${rid}&assignment_id=${aid}&date=${encodeURIComponent(x.date_debut||'')}">
                            Présence
                        </a>

                        <a class="btn btn-outline-success"
                        href="${BASE}/views/espace-hopital/evaluations.php?rotation_id=${rid}&assignment_id=${aid}">
                            Évaluer
                        </a>
                    </div>`;
                })()}
            </td>
        </tr>`;
    }).join('');
}

$('filterBtn').onclick=load;
$('refreshBtn').onclick=load;
$('status').onchange=load;
$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};

load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

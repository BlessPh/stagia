<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['CHEF_SERVICE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Stagiaires reçus';
$activePage='chef-service-stagiaires';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Stagiaires reçus</h1>
        <p>Les stagiaires affectés aux services de votre département / coordination apparaissent ici.</p>
    </div>
    <button class="btn btn-outline-primary" id="refreshBtn">
        <i class="bi bi-arrow-clockwise me-1"></i>Actualiser
    </button>
</div>

<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card">
        <div><span>TOTAL</span><strong id="kTotal">0</strong><small>Stagiaires du périmètre</small></div>
        <div class="stagia-kpi-icon kpi-blue"><i class="bi bi-people"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>ACTIFS</span><strong id="kActifs">0</strong><small>Affectations en cours</small></div>
        <div class="stagia-kpi-icon kpi-green"><i class="bi bi-play-circle"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>PLANIFIÉS</span><strong id="kPlanifies">0</strong><small>Affectations à venir</small></div>
        <div class="stagia-kpi-icon kpi-orange"><i class="bi bi-clock"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>TERMINÉS</span><strong id="kTermines">0</strong><small>Affectations clôturées</small></div>
        <div class="stagia-kpi-icon kpi-purple"><i class="bi bi-check2-circle"></i></div>
    </div>
</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div class="stagia-list-filters w-100">
            <select id="status" class="form-select" style="max-width:220px">
                <option value="A_SUIVRE" selected>À suivre</option>
                <option value="ACTIVE">Actifs</option>
                <option value="PLANIFIEE">Planifiés</option>
                <option value="TERMINEE">Terminés</option>
            </select>

            <div class="input-group stagia-table-search flex-grow-1">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search"></i>
                </span>
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
                    <th>Université</th>
                    <th>Session</th>
                    <th>Service</th>
                    <th>Période</th>
                    <th>Statut</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody id="rows">
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="spinner-border spinner-border-sm me-2"></div>Chargement...
                    </td>
                </tr>
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
let timer=null;

function badge(s){
    if(s==='ACTIVE')return '<span class="badge bg-success">ACTIVE</span>';
    if(s==='PLANIFIEE')return '<span class="badge bg-warning text-dark">PLANIFIÉE</span>';
    if(s==='TERMINEE')return '<span class="badge bg-secondary">TERMINÉE</span>';
    return `<span class="badge bg-light text-dark border">${esc(s||'—')}</span>`;
}

function frDate(d){
    if(!d)return '—';
    const x=new Date(String(d)+'T00:00:00');
    return isNaN(x)?esc(d):x.toLocaleDateString('fr-FR');
}

async function load(){
    const qs=new URLSearchParams({
        status:$('status').value||'A_SUIVRE',
        q:$('search').value.trim()
    });

    try{
        const r=await STAGIA.request(BASE+'/actions/espace-hopital/chef-service-stagiaires-list.php?'+qs);
        const d=r.data||{},items=d.items||[],s=d.stats||{};

        $('kTotal').textContent=s.total||0;
        $('kActifs').textContent=s.actifs||0;
        $('kPlanifies').textContent=s.planifies||0;
        $('kTermines').textContent=s.termines||0;
        $('footerCount').textContent=`Page 1/1 · ${items.length} résultat(s)`;

        $('rows').innerHTML=items.length?items.map(x=>`
            <tr>
                <td>
                    <strong>${esc(x.stagiaire||x.student_name||'—')}</strong>
                    <small class="d-block text-muted">${esc(x.matricule||x.stagia_code||'')}</small>
                </td>
                <td>${esc(x.universite||x.university_name||'—')}</td>
                <td>
                    ${esc(x.campaign_code||'—')}
                    <small class="d-block text-muted">${esc(x.campaign_title||'')}</small>
                </td>
                <td>${esc(x.service||x.service_name||'—')}</td>
                <td>${frDate(x.starts_at)} → ${frDate(x.ends_at)}</td>
                <td>${badge(x.statut||x.status)}</td>
                <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="${BASE}/views/espace-hopital/rotations.php?assignment_id=${Number(x.assignment_id||x.id||0)}">
                        <i class="bi bi-journal-text me-1"></i>Suivi
                    </a>
                </td>
            </tr>
        `).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucun stagiaire reçu.</td></tr>';
    }catch(e){
        $('rows').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

$('filterBtn').onclick=load;
$('refreshBtn').onclick=load;
$('status').onchange=load;
$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};

load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
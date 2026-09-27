<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'campaign.supervision.view');

$pageTitle='Supervision des stages';
$activePage='admin-stage-supervision';

require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.super-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.super-kpi,.super-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px}
.super-kpi{padding:16px}.super-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}
.super-kpi strong{display:block;font-size:27px;margin-top:4px}.super-card{overflow:hidden}
@media(max-width:900px){.super-kpis{grid-template-columns:repeat(2,1fr)}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Supervision des stages</h1>
        <p>Vue nationale en lecture seule. Les décisions opérationnelles restent du ressort des établissements de formation et d'accueil.</p>
    </div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-eye text-primary me-1"></i>
    Cet espace permet de suivre les campagnes et leur progression.
    Il ne permet ni de créer une campagne universitaire, ni d'accepter une demande, ni d'affecter un stagiaire.
</div>

<div class="super-kpis mb-4">
    <div class="super-kpi"><small>Total campagnes</small><strong id="kTotal">0</strong></div>
    <div class="super-kpi"><small>Brouillons</small><strong id="kDraft">0</strong></div>
    <div class="super-kpi"><small>En préparation</small><strong id="kPreparing">0</strong></div>
    <div class="super-kpi"><small>Ouvertes</small><strong id="kOpen">0</strong></div>
</div>

<div class="super-card">
<div class="p-3 border-bottom">
<div class="row g-2">
    <div class="col-lg-5">
        <input id="search" class="form-control" placeholder="Université, campagne ou code...">
    </div>
    <div class="col-lg-3">
        <select id="typeFilter" class="form-select"><option value="">Tous les types</option></select>
    </div>
    <div class="col-lg-4">
        <select id="statusFilter" class="form-select">
            <option value="">Tous les statuts</option>
            <option value="BROUILLON">Brouillon</option>
            <option value="EN_PREPARATION">En préparation</option>
            <option value="OUVERTE">Ouverte</option>
            <option value="CLOTUREE">Clôturée</option>
            <option value="TERMINEE">Terminée</option>
            <option value="ANNULEE">Annulée</option>
        </select>
    </div>
</div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>ÉTABLISSEMENT</th>
    <th>CAMPAGNE</th>
    <th>TYPE</th>
    <th>ANNÉE</th>
    <th>PROMOTIONS / ÉTUDIANTS</th>
    <th>D4</th>
    <th>STATUT</th>
</tr>
</thead>
<tbody id="rows">
<tr><td colspan="7" class="text-center py-5 text-muted">Chargement...</td></tr>
</tbody>
</table>
</div>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id),
      esc=STAGIA.escape;

let timer;

function badge(s){
    const m={
        BROUILLON:['Brouillon','bg-secondary-subtle text-secondary'],
        EN_PREPARATION:['En préparation','bg-warning-subtle text-warning'],
        OUVERTE:['Ouverte','bg-success-subtle text-success'],
        CLOTUREE:['Clôturée','bg-info-subtle text-info'],
        TERMINEE:['Terminée','bg-dark-subtle text-dark'],
        ANNULEE:['Annulée','bg-danger-subtle text-danger']
    }[s]||[s,'bg-light text-dark'];
    return `<span class="badge ${m[1]}">${esc(m[0])}</span>`;
}

async function load(){
    const q=new URLSearchParams();
    if($('search').value.trim())q.set('search',$('search').value.trim());
    if($('typeFilter').value)q.set('stage_type_id',$('typeFilter').value);
    if($('statusFilter').value)q.set('statut',$('statusFilter').value);

    try{
        const r=await STAGIA.request(BASE_URL+'/actions/admin/stage-campaign-supervision-list.php?'+q);
        const d=r.data;

        $('kTotal').textContent=d.kpi.total||0;
        $('kDraft').textContent=d.kpi.draft||0;
        $('kPreparing').textContent=d.kpi.preparing||0;
        $('kOpen').textContent=d.kpi.opened||0;

        const old=$('typeFilter').value;
        $('typeFilter').innerHTML=
            '<option value="">Tous les types</option>'+
            (d.types||[]).map(t=>`<option value="${t.id}">${esc(t.libelle)}</option>`).join('');
        $('typeFilter').value=old;

        $('rows').innerHTML=(d.items||[]).length?(d.items||[]).map(x=>`<tr>
            <td>
                <strong>${esc(x.etablissement_nom)}</strong>
                <small class="d-block text-muted">${esc(x.province||'')}</small>
            </td>
            <td>
                <strong>${esc(x.titre)}</strong>
                <small class="d-block text-muted">${esc(x.code||'—')}</small>
            </td>
            <td>${esc(x.stage_type_libelle)}</td>
            <td>${esc(x.annee_libelle||'—')}</td>
            <td>
                ${Number(x.promotions_count)||0} promotion(s)
                <small class="d-block text-muted">${Number(x.eligible_students)||0} étudiant(s) éligible(s)</small>
            </td>
            <td>
                ${x.stage_type_code==='MEDICAL_D4'
                    ?`${Number(x.d4_requests)||0} sollicitation(s)<small class="d-block text-muted">${Number(x.d4_accepted)||0} acceptée(s)</small>`
                    :'—'}
            </td>
            <td>${badge(x.statut)}</td>
        </tr>`).join('')
        :'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune campagne.</td></tr>';
    }catch(e){
        $('rows').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};
$('typeFilter').onchange=load;
$('statusFilter').onchange=load;

load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

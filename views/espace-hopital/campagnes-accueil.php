<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'campaign.hosting.view');
if(!contextHostEnabled()){http_response_code(403);exit("Cet établissement n'est pas une structure d'accueil.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle="Sessions d'accueil";
$activePage='hospital-campaigns';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.host-campaign-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px;overflow:hidden}
#modal .modal-dialog{height:calc(100vh - 24px);max-height:calc(100vh - 24px);margin:12px auto}
#modal .modal-content{height:100%;max-height:100%;overflow:hidden}
#modal form{height:100%;min-height:0;display:flex;flex-direction:column}
#modal .modal-header,#modal .modal-footer{flex:0 0 auto;background:#fff;z-index:4}
#modal .modal-body{flex:1 1 auto;min-height:0;overflow-y:auto!important;overflow-x:hidden;scrollbar-gutter:stable}
#modal .modal-footer{box-shadow:0 -6px 18px rgba(15,23,42,.08)}
@media(max-width:767.98px){#modal .modal-dialog{height:100vh;max-height:100vh;margin:0}#modal .modal-content{border-radius:0}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Sessions d'accueil</h1>
        <p>Créez les stages et périodes d'accueil proposés par votre établissement.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?=BASE_URL?>/views/stages/types-stage.php" class="btn btn-light border" id="typesBtn">
            <i class="bi bi-tags me-1"></i> Types de stage
        </a>
        <button id="addBtn" class="btn btn-primary-stagia d-none">
            <i class="bi bi-plus-lg me-1"></i> Lancer une session d'accueil
        </button>
    </div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-info-circle text-primary me-1"></i>
    Une session d'accueil appartient à votre établissement et peut être associée à plusieurs sessions de stage compatibles.
    Les <strong>capacités réelles</strong> restent gérées dans le module <strong>Capacités d'accueil</strong> afin d'éviter les doublons.
</div>

<div class="host-campaign-card">
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr>
    <th>SESSION</th><th>TYPE</th><th>PÉRIODE</th><th>CONDITION</th><th>PARTICIPATIONS</th><th>STATUT</th><th class="text-end">ACTION</th>
</tr></thead>
<tbody id="rows"><tr><td colspan="7" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="modal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content"><form id="form">
<div class="modal-header">
    <div><h5 class="modal-title" id="titleModal">Lancer une session d'accueil</h5><small class="text-muted">Organisation des périodes et conditions d'accueil.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><input type="hidden" name="id" id="id">
<div class="row g-3">
    <div class="col-md-8"><label class="form-label">Titre *</label><input name="titre" id="title" class="form-control" maxlength="200" required></div>
    <div class="col-md-4">
        <label class="form-label">Type de stage *</label>
        <select name="stage_type_id" id="stageType" class="form-select" required></select>
        <a href="<?=BASE_URL?>/views/stages/types-stage.php" class="small text-decoration-none d-inline-block mt-1"><i class="bi bi-plus-circle me-1"></i>Gérer mes types</a>
    </div>
    <div class="col-md-6"><label class="form-label">Début *</label><input type="date" name="date_debut" id="start" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">Fin *</label><input type="date" name="date_fin" id="end" class="form-control" required></div>
    <div class="col-12"><label class="form-label">Objectif du stage</label><input name="objectif_stage" id="objective" class="form-control" maxlength="500"></div>
    <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="description" class="form-control" rows="3"></textarea></div>

    <div class="col-12"><div class="border rounded-3 p-3">
        <h6 class="mb-2"><i class="bi bi-wallet2 me-1"></i> Condition financière héritée du type</h6>
        <div id="typeFinanceSummary" class="text-muted">Sélectionnez un type de stage.</div>
        <small class="text-muted d-block mt-2">La campagne conserve ce montant même si le type est modifié plus tard. La capacité d'accueil reste gérée séparément dans le module Capacités d'accueil.</small>
    </div></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('modal'));
let items=[],types=[],permissions={};

function badge(s){const m={BROUILLON:['Brouillon','bg-secondary-subtle text-secondary'],OUVERTE:['Ouverte','bg-success-subtle text-success'],CLOTUREE:['Clôturée','bg-info-subtle text-info'],ANNULEE:['Annulée','bg-danger-subtle text-danger'],TERMINEE:['Terminée','bg-dark-subtle text-dark']}[s]||[s,'bg-light text-dark'];return `<span class="badge ${m[1]}">${esc(m[0])}</span>`;}
function money(x){const f=x.financial||{};return f.required?`<strong>${esc(f.amount||'0')} ${esc(f.currency||'')}</strong><small class="d-block text-muted">Payant</small>`:'<span class="badge bg-success-subtle text-success">GRATUIT</span>';}
function fillTypes(selected=''){const current=String(selected||$('stageType').value||'');$('stageType').innerHTML='<option value="">Sélectionner...</option>'+types.map(t=>`<option value="${t.id}">${esc(t.libelle)}${t.local?' · Mon établissement':''}</option>`).join('');$('stageType').value=current;}
function currentType(){return types.find(t=>String(t.id)===$('stageType').value);}
function financeSummary(f,legacy=false){if(legacy&&!f?.configured)return '<span class="badge bg-light text-dark border">Historique STAGIA</span> <strong class="ms-1">GRATUIT par défaut</strong>';if(!f?.configured)return '<span class="badge bg-danger-subtle text-danger">À CONFIGURER</span><small class="d-block text-muted mt-1">Modifiez ce type avant de créer la campagne.</small>';if(f?.mode==='PAYANT'||f?.required)return `<span class="badge bg-warning-subtle text-warning">PAYANT</span> <strong class="ms-1">${esc(f.amount||'—')} ${esc(f.currency||'')}</strong>`;return '<span class="badge bg-success-subtle text-success">GRATUIT</span>';}
function renderFinance(financial=null){const t=currentType(),f=financial||t?.financial;$('typeFinanceSummary').innerHTML=(t||financial)?financeSummary(f,!!t?.legacy):'<span class="text-muted">Sélectionnez un type de stage.</span>';}

async function load(){
    try{
        const r=await STAGIA.request(BASE+'/actions/espace-hopital/host-campaign-list.php');
        items=r.data.items||[];types=r.data.types||[];permissions=r.data.permissions||{};
        $('addBtn').classList.toggle('d-none',!permissions.create);
        $('typesBtn').classList.toggle('d-none',!permissions.types_manage);
        fillTypes();renderFinance();
        $('rows').innerHTML=items.length?items.map(x=>`<tr>
            <td><strong>${esc(x.titre)}</strong><small class="d-block text-muted">${esc(x.code||'—')}</small>${x.objectif_stage?`<small class="d-block text-muted">${esc(x.objectif_stage)}</small>`:''}</td>
            <td><strong>${esc(x.stage_type_libelle||'—')}</strong><small class="d-block text-muted">${esc(x.stage_type_code||'')}</small></td>
            <td>${esc(x.date_debut)} → ${esc(x.date_fin)}</td>
            <td>${money(x)}</td>
            <td>${Number(x.participations_count)||0} liée(s)<small class="d-block text-muted">${Number(x.accepted_count)||0} acceptée(s)</small></td>
            <td>${badge(x.statut)}${x.cancellation_reason?`<small class="d-block text-danger mt-1">${esc(x.cancellation_reason)}</small>`:''}</td>
            <td class="text-end">
                ${permissions.update&&x.statut==='BROUILLON'?`<button class="btn btn-sm btn-outline-primary edit" data-id="${x.id}" title="Modifier"><i class="bi bi-pencil"></i></button>`:''}
                ${permissions.publish&&x.statut==='BROUILLON'?`<button class="btn btn-sm btn-outline-success action" data-id="${x.id}" data-action="PUBLISH" title="Ouvrir"><i class="bi bi-send-check"></i></button>`:''}
                ${permissions.publish&&x.statut==='OUVERTE'?`<button class="btn btn-sm btn-outline-info action" data-id="${x.id}" data-action="CLOSE" title="Clôturer"><i class="bi bi-lock"></i></button>`:''}
                ${permissions.publish&&!['ANNULEE','TERMINEE'].includes(x.statut)?`<button class="btn btn-sm btn-outline-danger action" data-id="${x.id}" data-action="CANCEL" title="Annuler"><i class="bi bi-x-circle"></i></button>`:''}
            </td>
        </tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune session d’accueil.</td></tr>';
        document.querySelectorAll('.edit').forEach(b=>b.onclick=()=>openEdit(Number(b.dataset.id)));
        document.querySelectorAll('.action').forEach(b=>b.onclick=()=>status(Number(b.dataset.id),b.dataset.action));
    }catch(e){STAGIA.toast(e.message,'danger');}
}

$('addBtn').onclick=()=>{$('form').reset();$('id').value='';fillTypes();renderFinance();$('titleModal').textContent="Lancer une session d'accueil";modal.show();};
function openEdit(id){const x=items.find(v=>Number(v.id)===id);if(!x)return;$('form').reset();$('id').value=x.id;$('title').value=x.titre||'';fillTypes(x.stage_type_id);$('start').value=x.date_debut||'';$('end').value=x.date_fin||'';$('objective').value=x.objectif_stage||'';$('description').value=x.description||'';renderFinance(x.financial||{});$('titleModal').textContent="Modifier la session d'accueil";modal.show();}
$('stageType').onchange=()=>renderFinance();

$('form').onsubmit=async e=>{e.preventDefault();STAGIA.loading($('saveBtn'),true);try{const fd=new FormData(e.currentTarget),url=$('id').value?BASE+'/actions/espace-hopital/host-campaign-update.php':BASE+'/actions/espace-hopital/host-campaign-store.php';const r=await STAGIA.post(url,fd);STAGIA.toast(r.message);modal.hide();await load();}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}};
async function status(id,action){let reason='';if(action==='CANCEL'){reason=prompt("Motif d'annulation :")||'';if(!reason.trim())return;}else if(!STAGIA.confirm(action==='PUBLISH'?"Ouvrir cette session d'accueil ?":"Clôturer cette session d'accueil ?"))return;const fd=new FormData();fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'])?>');fd.append('id',id);fd.append('action',action);fd.append('reason',reason);try{const r=await STAGIA.post(BASE+'/actions/espace-hopital/host-campaign-status.php',fd);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}}
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

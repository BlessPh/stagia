<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-type.php';

try{requireStageTypeManage($pdo);}catch(Throwable $e){http_response_code(403);exit($e->getMessage());}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Types de stage';
$activePage='stage-types';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.type-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.type-kpi,.type-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px}.type-kpi{padding:16px}.type-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.type-kpi strong{display:block;font-size:27px;margin-top:4px}.type-card{overflow:hidden}.levels-box{max-height:190px;overflow:auto;border:1px solid #dee2e6;border-radius:10px;padding:10px}.origin-local{background:#e8f7ef;color:#198754}.origin-legacy{background:#f1f5f9;color:#475569}.finance-config{background:#fffaf5;border:1px solid #fed7aa;border-radius:12px;padding:14px}
@media(max-width:900px){.type-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.type-kpis{grid-template-columns:1fr}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Types de stage</h1>
        <p>Créez et gérez les types de stage propres à votre établissement.</p>
    </div>
    <button class="btn btn-primary-stagia" id="addTypeBtn"><i class="bi bi-plus-lg me-1"></i> Nouveau type</button>
</div>
<div class="alert alert-light border mb-3">
    <i class="bi bi-shield-check text-primary me-1"></i>
    Chaque établissement gère ses propres types. La condition <strong>Gratuit / Payant</strong> est définie ici et sera automatiquement reprise par les nouvelles campagnes. Une campagne déjà créée conserve toujours la condition financière enregistrée lors de sa création.
</div>
<div class="type-kpis mb-4">
    <div class="type-kpi"><small>Mes types</small><strong id="kTotal">0</strong></div>
    <div class="type-kpi"><small>Actifs</small><strong id="kActive">0</strong></div>
    <div class="type-kpi"><small>Supprimés / inactifs</small><strong id="kInactive">0</strong></div>
    <div class="type-kpi"><small>Déjà utilisés</small><strong id="kUsed">0</strong></div>
</div>
<div class="type-card">
<div class="p-3 border-bottom"><div class="row g-2">
    <div class="col-lg-7"><input id="search" class="form-control" placeholder="Rechercher un type de stage..."></div>
    <div class="col-lg-3"><select id="statusFilter" class="form-select"><option value="">Tous les statuts</option><option value="ACTIF">Actifs</option><option value="INACTIF">Supprimés / inactifs</option></select></div>
    <div class="col-lg-2"><button class="btn btn-light border w-100" id="refreshBtn"><i class="bi bi-arrow-clockwise me-1"></i> Actualiser</button></div>
</div></div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>TYPE</th><th>ORIGINE</th><th>NIVEAUX</th><th>CONDITION FINANCIÈRE</th><th>UTILISATION</th><th>STATUT</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="rows"><tr><td colspan="7" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="typeModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form id="typeForm">
<div class="modal-header"><div><h5 class="modal-title" id="typeModalTitle">Nouveau type de stage</h5><small class="text-muted">Le code technique est généré automatiquement.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><input type="hidden" name="id" id="typeId">
<div class="row g-3">
    <div class="col-12"><label class="form-label">Libellé *</label><input name="libelle" id="typeLabel" class="form-control" maxlength="150" required placeholder="Ex. Stage d'observation"></div>

    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" id="typeDescription" class="form-control" rows="3" maxlength="1000"></textarea>
    </div>

    <div class="col-12">
        <label class="form-label">Objectif <span class="text-muted fw-normal">(facultatif)</span></label>
        <textarea name="objectif" id="typeObjective" class="form-control" rows="2" maxlength="1000" placeholder="Ex. Permettre à l’étudiant de découvrir le fonctionnement d’un service hospitalier."></textarea>
        <small class="text-muted">Objectif général associé à ce type de stage.</small>
    </div>

    <div class="col-12">
        <div class="finance-config">
            <h6 class="mb-3"><i class="bi bi-cash-coin me-1"></i> Condition financière *</h6>
            <div class="d-flex gap-4 flex-wrap mb-3">
                <div class="form-check"><input class="form-check-input financial-mode" type="radio" name="financial_mode" id="typeFree" value="GRATUIT" checked><label class="form-check-label" for="typeFree">Gratuit</label></div>
                <div class="form-check"><input class="form-check-input financial-mode" type="radio" name="financial_mode" id="typePaid" value="PAYANT"><label class="form-check-label" for="typePaid">Payant</label></div>
            </div>
            <div class="row g-3 d-none" id="financialFields">
                <div class="col-md-6"><label class="form-label">Montant *</label><input type="number" min="0.01" step="0.01" name="financial_amount" id="typeAmount" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Devise *</label><select name="financial_currency" id="typeCurrency" class="form-select"><option value="USD">USD</option><option value="CDF">CDF</option><option value="EUR">EUR</option></select></div>
            </div>
            <small class="d-block text-muted mt-2">Toute nouvelle campagne créée avec ce type héritera automatiquement de cette condition. Les anciennes campagnes ne seront pas modifiées.</small>
        </div>
    </div>

    <div class="col-12 d-none" id="levelsWrap"><label class="form-label">Niveaux académiques éligibles</label><div class="levels-box" id="levelChecks"></div><small class="text-muted">Laissez tout décoché pour ne pas limiter ce type à des niveaux particuliers.</small></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary-stagia" id="saveTypeBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('typeModal'));
let items=[],levels=[],timer;

function levelValues(x){const v=x?.policies?.eligible_level_codes??[];return (Array.isArray(v)?v:[v]).filter(Boolean).map(String);}
function renderLevels(selected=[]){const set=new Set(selected.map(x=>String(x).toUpperCase()));$('levelsWrap').classList.toggle('d-none',!levels.length);$('levelChecks').innerHTML=levels.length?levels.map(l=>`<label class="d-flex gap-2 py-1"><input class="form-check-input level-choice" type="checkbox" name="eligible_level_codes[]" value="${esc(l.code)}" ${set.has(String(l.code).toUpperCase())?'checked':''}><span><strong>${esc(l.code)}</strong> <small class="text-muted">${esc(l.libelle||'')}</small></span></label>`).join(''):'<span class="text-muted small">Aucun niveau académique configuré.</span>';}
function financeText(x){const f=x?.financial||{};if(x?.legacy&&!f.configured)return '<span class="badge bg-light text-dark border">Historique</span><small class="d-block text-muted mt-1">Gratuit par défaut pour compatibilité</small>';if(!f.configured)return '<span class="badge bg-danger-subtle text-danger">À CONFIGURER</span>';if(f.mode==='PAYANT')return `<span class="badge bg-warning-subtle text-warning">PAYANT</span><small class="d-block mt-1"><strong>${esc(f.amount||'—')} ${esc(f.currency||'')}</strong></small>`;return '<span class="badge bg-success-subtle text-success">GRATUIT</span>';}
function actions(x){if(!x.local)return '<span class="text-muted">Lecture seule</span>';const edit=`<button class="btn btn-sm btn-outline-primary edit-btn" data-id="${x.id}" title="Modifier"><i class="bi bi-pencil"></i></button>`;const status=x.actif?`<button class="btn btn-sm btn-outline-danger status-btn" data-id="${x.id}" data-action="DELETE" title="Supprimer"><i class="bi bi-trash"></i></button>`:`<button class="btn btn-sm btn-outline-success status-btn" data-id="${x.id}" data-action="RESTORE" title="Réactiver"><i class="bi bi-arrow-counterclockwise"></i></button>`;return `<div class="btn-group btn-group-sm">${edit}${status}</div>`;}
function render(){
 $('rows').innerHTML=items.length?items.map(x=>{const lv=levelValues(x);return `<tr><td><strong>${esc(x.libelle)}</strong><small class="d-block text-muted">${esc(x.code)}</small><small class="d-block text-muted">${esc(x.description||'')}</small>${x.objectif?`<small class="d-block text-muted"><strong>Objectif :</strong> ${esc(x.objectif)}</small>`:''}</td><td><span class="badge ${x.local?'origin-local':'origin-legacy'}">${x.local?'Mon établissement':'Historique STAGIA'}</span></td><td>${lv.length?lv.map(v=>`<span class="badge bg-light text-dark border me-1">${esc(v)}</span>`).join(''):'<span class="text-muted">Tous / non limité</span>'}</td><td>${financeText(x)}</td><td><strong>${Number(x.usage_count)||0}</strong><small class="d-block text-muted">campagne(s)</small></td><td><span class="badge ${x.actif?'bg-success-subtle text-success':'bg-secondary-subtle text-secondary'}">${x.actif?'ACTIF':'INACTIF'}</span></td><td class="text-end">${actions(x)}</td></tr>`}).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucun type de stage.</td></tr>';
 document.querySelectorAll('.edit-btn').forEach(b=>b.onclick=()=>edit(Number(b.dataset.id)));document.querySelectorAll('.status-btn').forEach(b=>b.onclick=()=>changeStatus(Number(b.dataset.id),b.dataset.action));
}
async function load(){const q=new URLSearchParams();if($('search').value.trim())q.set('search',$('search').value.trim());if($('statusFilter').value)q.set('status',$('statusFilter').value);try{const r=await STAGIA.request(BASE+'/actions/stages/stage-type-list.php?'+q);items=r.data.items||[];levels=r.data.levels||[];const k=r.data.kpi||{};$('kTotal').textContent=k.total||0;$('kActive').textContent=k.actifs||0;$('kInactive').textContent=k.inactifs||0;$('kUsed').textContent=k.utilises||0;render();}catch(e){$('rows').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;STAGIA.toast(e.message,'danger');}}
function toggleFinancial(){const paid=$('typePaid').checked;$('financialFields').classList.toggle('d-none',!paid);$('typeAmount').required=paid;$('typeCurrency').required=paid;if(!paid)$('typeAmount').value='';}
function setFinancial(f={}){$('typePaid').checked=f.mode==='PAYANT';$('typeFree').checked=f.mode!=='PAYANT';$('typeAmount').value=f.mode==='PAYANT'?(f.amount||''):'';$('typeCurrency').value=f.currency||'USD';toggleFinancial();}
function openCreate(){$('typeForm').reset();$('typeId').value='';$('typeModalTitle').textContent='Nouveau type de stage';renderLevels([]);setFinancial({mode:'GRATUIT'});modal.show();}
function edit(id){const x=items.find(v=>Number(v.id)===id);if(!x||!x.local)return;$('typeForm').reset();$('typeId').value=x.id;$('typeLabel').value=x.libelle||'';$('typeDescription').value=x.description||'';$('typeObjective').value=x.objectif||'';$('typeModalTitle').textContent='Modifier le type de stage';renderLevels(levelValues(x));setFinancial(x.financial||{mode:'GRATUIT'});modal.show();}
$('typeForm').onsubmit=async e=>{e.preventDefault();toggleFinancial();STAGIA.loading($('saveTypeBtn'),true);try{const id=$('typeId').value,url=id?BASE+'/actions/stages/stage-type-update.php':BASE+'/actions/stages/stage-type-store.php';const r=await STAGIA.post(url,new FormData(e.currentTarget));STAGIA.toast(r.message);modal.hide();await load();}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveTypeBtn'),false);}};
async function changeStatus(id,action){const msg=action==='DELETE'?'Supprimer ce type de vos choix ? Les campagnes déjà liées seront conservées.':'Réactiver ce type de stage ?';if(!STAGIA.confirm(msg))return;const fd=new FormData();fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'])?>');fd.append('id',id);fd.append('action',action);try{const r=await STAGIA.post(BASE+'/actions/stages/stage-type-status.php',fd);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}}
document.querySelectorAll('.financial-mode').forEach(x=>x.addEventListener('change',toggleFinancial));$('addTypeBtn').onclick=openCreate;$('refreshBtn').onclick=load;$('statusFilter').onchange=load;$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

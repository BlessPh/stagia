<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Permissions';$activePage='admin-permissions';
require_once __DIR__.'/../../../includes/app-header.php';
?>
<style>
#permissionRolesModal .modal-dialog{height:calc(100vh - 2rem);max-height:calc(100vh - 2rem)}
#permissionRolesModal .modal-content{max-height:calc(100vh - 2rem)}
#permissionRolesModal .modal-body{overflow-y:scroll!important;max-height:calc(100vh - 185px);scrollbar-gutter:stable}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Permissions</h1>
        <p>Catalogue technique central des actions autorisables dans STAGIA-RDC.</p>
    </div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-shield-lock text-primary me-1"></i>
    Une permission correspond à une capacité réellement contrôlée par le code de STAGIA.
    Son <strong>code technique est immuable</strong>. Les nouvelles permissions doivent être introduites avec la fonctionnalité correspondante dans le code et la migration ; elles ne sont donc pas créées librement depuis cette page.
</div>

<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card"><div><span>PERMISSIONS</span><strong id="kTotal">0</strong><small>Capacités enregistrées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-shield"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIVES</span><strong id="kActive">0</strong><small>Utilisables par les rôles</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-shield-check"></i></div></div>
    <div class="stagia-kpi-card"><div><span>INACTIVES</span><strong id="kInactive">0</strong><small>Capacités suspendues</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-shield-x"></i></div></div>
    <div class="stagia-kpi-card"><div><span>MODULES</span><strong id="kModules">0</strong><small>Domaines fonctionnels</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-grid"></i></div></div>
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar">
<div class="stagia-list-filters w-100">
    <div class="input-group stagia-table-search flex-grow-1">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
        <input id="search" class="form-control border-start-0" placeholder="Code, permission, module...">
    </div>
    <select id="moduleFilter" class="form-select"><option value="">Tous les modules</option></select>
    <select id="statusFilter" class="form-select">
        <option value="">Tous les statuts</option>
        <option value="ACTIVE">Actives</option>
        <option value="INACTIVE">Inactives</option>
    </select>
    <button class="btn btn-light border" id="resetBtn" title="Réinitialiser"><i class="bi bi-arrow-clockwise"></i></button>
</div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>MODULE</th><th>CODE TECHNIQUE</th><th>PERMISSION</th><th>RÔLES AUTORISÉS</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
<tbody id="body"><tr><td colspan="6" class="text-center py-5"><div class="spinner-border spinner-border-sm"></div></td></tr></tbody>
</table>
</div>
<div class="stagia-list-footer"><span id="count">0 permission(s)</span></div>
</div>
</main>

<div class="modal fade" id="permissionEditModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="editForm">
<div class="modal-header">
    <div><h5 class="modal-title">Modifier la permission</h5><small class="text-muted" id="editCode"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="id" id="editId">

<div class="mb-3">
    <label class="form-label">Code technique</label>
    <input class="form-control" id="editCodeInput" disabled>
    <small class="text-muted">Le code est utilisé par les contrôles <code>hasPermission()</code> / <code>requirePermission()</code> et ne peut pas être modifié ici.</small>
</div>
<div class="row g-3">
    <div class="col-md-6"><label class="form-label">Nom *</label><input class="form-control" name="nom" id="editName" maxlength="150" required></div>
    <div class="col-md-6"><label class="form-label">Module *</label><input class="form-control text-uppercase" name="module" id="editModule" maxlength="60" required></div>
    <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" id="editDescription" maxlength="255" rows="4"></textarea></div>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="editSave"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>

<div class="modal fade" id="permissionRolesModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow">
<form id="rolesForm">
<div class="modal-header">
    <div><h5 class="modal-title">Rôles autorisés</h5><small class="text-muted" id="rolesPermission"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="permission_id" id="rolesPermissionId">

<div class="alert alert-primary">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Super administrateur</strong> n’apparaît pas dans cette liste : il possède automatiquement toutes les permissions STAGIA.
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <strong>Sélectionnez les rôles qui reçoivent cette permission</strong>
    <span class="text-muted small" id="selectedCount"></span>
</div>
<div class="row g-3" id="rolesGrid"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Fermer</button>
    <button class="btn btn-primary-stagia" id="rolesSave"><i class="bi bi-shield-check me-1"></i> Enregistrer les rôles</button>
</div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape,csrf='<?= $_SESSION['csrf'] ?>';
const editModal=new bootstrap.Modal($('permissionEditModal')),rolesModal=new bootstrap.Modal($('permissionRolesModal'));
let items=[],modules=[],timer=null;

async function load(){
    const qs=new URLSearchParams({q:$('search').value.trim(),module:$('moduleFilter').value,status:$('statusFilter').value});
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/admin/permissions/list.php?'+qs);
        items=r.data.items||[];modules=r.data.modules||[];
        $('kTotal').textContent=r.data.stats.total;$('kActive').textContent=r.data.stats.actives;
        $('kInactive').textContent=r.data.stats.inactives;$('kModules').textContent=r.data.stats.modules;
        fillModules();render();
    }catch(e){STAGIA.toast(e.message,'danger');}
}
function fillModules(){
    const old=$('moduleFilter').value;
    $('moduleFilter').innerHTML='<option value="">Tous les modules</option>'+modules.map(m=>`<option value="${esc(m)}">${esc(m)}</option>`).join('');
    $('moduleFilter').value=old;
}
function render(){
    $('count').textContent=`${items.length} permission(s)`;
    $('body').innerHTML=items.length?items.map(p=>`<tr>
        <td><span class="badge bg-light text-dark border">${esc(p.module)}</span></td>
        <td><code>${esc(p.code)}</code></td>
        <td><strong>${esc(p.nom)}</strong><small class="d-block text-muted">${esc(p.description||'')}</small></td>
        <td>
            <strong>${Number(p.nb_roles)} rôle(s)</strong>
            ${p.roles?`<small class="d-block text-muted">${esc(p.roles)}</small>`:'<small class="d-block text-muted">Aucun rôle hors Super Admin</small>'}
        </td>
        <td><span class="badge bg-${Number(p.actif)===1?'success':'secondary'}">${Number(p.actif)===1?'Active':'Inactive'}</span></td>
        <td class="text-center text-nowrap">
            <button class="btn btn-sm btn-outline-primary edit" data-id="${p.id}" title="Modifier le libellé"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-primary roles" data-id="${p.id}" title="Gérer les rôles"><i class="bi bi-people"></i></button>
            <button class="btn btn-sm btn-outline-${Number(p.actif)===1?'danger':'success'} status" data-id="${p.id}" title="${Number(p.actif)===1?'Désactiver':'Réactiver'}"><i class="bi bi-${Number(p.actif)===1?'pause-circle':'play-circle'}"></i></button>
        </td>
    </tr>`).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucune permission trouvée.</td></tr>';

    document.querySelectorAll('.edit').forEach(b=>b.onclick=()=>editPermission(Number(b.dataset.id)));
    document.querySelectorAll('.roles').forEach(b=>b.onclick=()=>manageRoles(Number(b.dataset.id)));
    document.querySelectorAll('.status').forEach(b=>b.onclick=()=>toggleStatus(Number(b.dataset.id)));
}
function editPermission(id){
    const p=items.find(x=>Number(x.id)===id);if(!p)return;
    $('editForm').reset();$('editId').value=p.id;$('editCode').textContent=p.code;$('editCodeInput').value=p.code;
    $('editName').value=p.nom||'';$('editModule').value=p.module||'';$('editDescription').value=p.description||'';
    editModal.show();
}
$('editForm').onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('editSave'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/permissions/update.php',new FormData(e.target));
        editModal.hide();STAGIA.toast(r.message);await load();
    }catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('editSave'),false);}
};

async function manageRoles(id){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/admin/permissions/roles.php?id='+id);
        const p=r.data.permission,selected=new Set((r.data.selected||[]).map(Number));
        $('rolesPermissionId').value=p.id;$('rolesPermission').textContent=p.nom+' · '+p.code;
        $('rolesSave').disabled=Number(p.actif)!==1;
        $('rolesGrid').innerHTML=(r.data.roles||[]).map(role=>`
            <div class="col-md-6">
                <label class="d-flex gap-2 border rounded-3 p-3 h-100 ${Number(role.actif)!==1?'bg-light opacity-75':''}">
                    <input class="form-check-input role-check mt-1" type="checkbox" name="role_ids[]" value="${role.id}"
                        ${selected.has(Number(role.id))?'checked':''} ${Number(role.actif)!==1||Number(p.actif)!==1?'disabled':''}>
                    <span><strong class="d-block">${esc(role.nom)}</strong><code class="small">${esc(role.code)}</code>
                    <small class="d-block text-muted mt-1">${Number(role.systeme)===1?'Rôle système STAGIA':'Rôle additionnel'}${Number(role.actif)!==1?' · Inactif':''}</small></span>
                </label>
            </div>`).join('');
        document.querySelectorAll('.role-check').forEach(c=>c.onchange=updateCount);updateCount();rolesModal.show();
    }catch(e){STAGIA.toast(e.message,'danger');}
}
function updateCount(){$('selectedCount').textContent=document.querySelectorAll('.role-check:checked').length+' rôle(s) sélectionné(s)';}
$('rolesForm').onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('rolesSave'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/permissions/sync-roles.php',new FormData(e.target));
        rolesModal.hide();STAGIA.toast(r.message);await load();
    }catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('rolesSave'),false);}
};

async function toggleStatus(id){
    const p=items.find(x=>Number(x.id)===id);if(!p)return;
    const action=Number(p.actif)===1?'désactiver':'réactiver';
    if(!STAGIA.confirm(`Voulez-vous ${action} la permission « ${p.nom} » ?`))return;
    const d=new FormData();d.append('csrf',csrf);d.append('id',id);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/permissions/status.php',d);
        STAGIA.toast(r.message);await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

['moduleFilter','statusFilter'].forEach(id=>$(id).onchange=load);
$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300)};
$('resetBtn').onclick=()=>{$('search').value='';$('moduleFilter').value='';$('statusFilter').value='';load()};
load();
});
</script>
<?php require_once __DIR__.'/../../../includes/app-footer.php'; ?>

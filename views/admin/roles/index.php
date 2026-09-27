<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$contexteRoles=exigerAdministrationRoles($pdo);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Rôles';$activePage='admin-roles';
require_once __DIR__.'/../../../includes/app-header.php';
?>

<style>
/* Scroll toujours visible dans le modal des permissions */
#permissionModal .modal-dialog{
    height:calc(100vh - 2rem);
    max-height:calc(100vh - 2rem);
}
#permissionModal .modal-content{
    max-height:calc(100vh - 2rem);
}
#permissionModal .modal-body{
    overflow-y:scroll !important;
    max-height:calc(100vh - 185px);
    scrollbar-gutter:stable;
    scrollbar-width:auto;
}
#permissionModal .modal-header,
#permissionModal .modal-footer{
    flex:0 0 auto;
}
#permissionModal .modal-body::-webkit-scrollbar{
    width:12px;
}

</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Rôles</h1>
    <p>Les rôles système sont communs à STAGIA ; les rôles locaux restent strictement propres à votre établissement.</p>
    </div>
    <button class="btn btn-primary-stagia" onclick="newRole()"><i class="bi bi-plus-lg me-1"></i> Nouveau rôle</button>
</div>

<div class="alert alert-light border mb-4">
    <i class="bi bi-shield-lock text-primary me-1"></i>
    Les permissions appartiennent au catalogue central STAGIA. Les codes des rôles sont générés à la création puis restent immuables.
    Les rôles système sont protégés. Un administrateur principal ne peut modifier que les rôles créés dans son propre établissement.
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar">
    <div class="input-group stagia-table-search">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
        <input id="roleSearch" class="form-control border-start-0" placeholder="Rechercher un rôle...">
    </div>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr><th>CODE</th><th>RÔLE</th><th>TYPE</th><th>PERMISSIONS</th><th>UTILISATEURS</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr>
</thead>
<tbody id="rolesBody"><tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm"></div></td></tr></tbody>
</table>
</div>
<div class="stagia-list-footer"><span id="roleCount">0 rôle(s)</span></div>
</div>
</main>

<div class="modal fade" id="roleModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow">
<form id="roleForm">
<div class="modal-header">
    <div><h5 class="modal-title" id="roleModalTitle">Nouveau rôle</h5><small class="text-muted" id="roleCodeInfo">Le code sera généré automatiquement.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
    <input type="hidden" name="id" id="roleId">
    <div class="mb-3"><label class="form-label">Nom du rôle *</label><input class="form-control" name="nom" id="roleName" maxlength="100" required></div>
    <div><label class="form-label">Description</label><textarea class="form-control" name="description" id="roleDescription" rows="4" maxlength="255"></textarea></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="roleSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>

<div class="modal fade" id="permissionModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow">
<form id="permissionForm">
<div class="modal-header">
    <div><h5 class="modal-title">Permissions du rôle</h5><small class="text-muted" id="permissionRoleName"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
    <input type="hidden" name="role_id" id="permissionRoleId">
    <div id="superAdminInfo" class="alert alert-primary d-none"><i class="bi bi-info-circle me-1"></i> Le Super Admin possède automatiquement toutes les permissions de la plateforme.</div>
    <div id="permissionGroups"></div>
</div>
<div class="modal-footer">
    <div class="me-auto text-muted small" id="permissionSelectionCount"></div>
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Fermer</button>
    <button class="btn btn-primary-stagia" id="permissionSaveBtn"><i class="bi bi-shield-check me-1"></i> Enregistrer les permissions</button>
</div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const csrf='<?= $_SESSION['csrf'] ?>';
const roleModal=new bootstrap.Modal($('roleModal')),permissionModal=new bootstrap.Modal($('permissionModal'));
let roles=[],permissions=[],query='';

async function load(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/admin/roles/list.php');
        roles=r.data.roles||[];permissions=r.data.permissions||[];render();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

function render(){
    const q=query.toLowerCase();
    const data=roles.filter(r=>[r.code,r.nom,r.description].some(v=>(v||'').toLowerCase().includes(q)));
    $('roleCount').textContent=`${data.length} rôle(s)`;
    $('rolesBody').innerHTML=data.length?data.map(r=>`<tr>
        <td><code>${esc(r.code)}</code></td>
        <td><strong>${esc(r.nom)}</strong><small class="d-block text-muted">${esc(r.description||'')}</small></td>
        <td>${Number(r.systeme)===1?'<span class="badge bg-light text-dark border">Système STAGIA</span>':`<span class="badge bg-info-subtle text-info-emphasis border">Local · ${esc(r.etablissement_nom||'établissement')}</span>`}</td>
        <td><span class="badge bg-light text-dark border">${Number(r.nb_permissions)}</span></td>
        <td>${Number(r.nb_utilisateurs)}</td>
        <td><span class="badge bg-${Number(r.actif)===1?'success':'secondary'}">${Number(r.actif)===1?'Actif':'Inactif'}</span></td>
        <td class="text-center text-nowrap">
            ${Number(r.modifiable)===1?`<button class="btn btn-sm btn-outline-primary role-edit" data-id="${r.id}" title="Modifier"><i class="bi bi-pencil"></i></button>`:''}
            <button class="btn btn-sm btn-outline-primary role-permissions" data-id="${r.id}" title="${Number(r.modifiable)===1?'Gérer':'Consulter'} les permissions"><i class="bi bi-shield-check"></i></button>
            ${Number(r.modifiable)===1&&Number(r.systeme)===0?`<button class="btn btn-sm btn-outline-primary role-status" data-id="${r.id}" title="${Number(r.actif)===1?'Désactiver':'Réactiver'}"><i class="bi bi-${Number(r.actif)===1?'pause-circle':'play-circle'}"></i></button>`:''}
        </td>
    </tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucun rôle trouvé.</td></tr>';

    document.querySelectorAll('.role-edit').forEach(b=>b.onclick=()=>editRole(Number(b.dataset.id)));
    document.querySelectorAll('.role-permissions').forEach(b=>b.onclick=()=>managePermissions(Number(b.dataset.id)));
    document.querySelectorAll('.role-status').forEach(b=>b.onclick=()=>toggleStatus(Number(b.dataset.id)));
}

window.newRole=()=>{
    $('roleForm').reset();$('roleId').value='';$('roleModalTitle').textContent='Nouveau rôle';
    $('roleCodeInfo').textContent='Le code STAGIA sera généré automatiquement puis verrouillé.';
    roleModal.show();
};

function editRole(id){
    const r=roles.find(x=>Number(x.id)===id);if(!r)return;
    $('roleForm').reset();$('roleId').value=r.id;$('roleName').value=r.nom||'';$('roleDescription').value=r.description||'';
    $('roleModalTitle').textContent='Modifier le rôle';$('roleCodeInfo').textContent='Code immuable : '+r.code;roleModal.show();
}

$('roleForm').onsubmit=async e=>{
    e.preventDefault();const edit=!!$('roleId').value,btn=$('roleSaveBtn');STAGIA.loading(btn,true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/roles/'+(edit?'update.php':'store.php'),new FormData(e.target));
        roleModal.hide();STAGIA.toast(r.message);await load();
    }catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading(btn,false);}
};

async function toggleStatus(id){
    const role=roles.find(r=>Number(r.id)===id);if(!role)return;
    const action=Number(role.actif)===1?'désactiver':'réactiver';
    if(!STAGIA.confirm(`Voulez-vous ${action} le rôle « ${role.nom} » ?`))return;
    const d=new FormData();d.append('csrf',csrf);d.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/admin/roles/status.php',d);STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}
}

async function managePermissions(id){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/admin/roles/permissions.php?id='+id);
        const role=r.data.role,selected=new Set((r.data.selected||[]).map(Number)),perms=r.data.permissions||[];
        $('permissionRoleId').value=role.id;$('permissionRoleName').textContent=role.nom+' · '+role.code;
        const locked=Number(role.modifiable)!==1;$('superAdminInfo').classList.toggle('d-none',!locked);
        $('superAdminInfo').innerHTML='<i class="bi bi-info-circle me-1"></i> Rôle système partagé : consultation uniquement dans votre contexte.';
        $('permissionSaveBtn').classList.toggle('d-none',locked);

        const groups={};perms.forEach(p=>(groups[p.module]??=[]).push(p));
        $('permissionGroups').innerHTML=Object.entries(groups).map(([module,list])=>`
            <div class="border rounded-3 mb-3 overflow-hidden">
                <div class="bg-light px-3 py-2 d-flex justify-content-between align-items-center">
                    <strong>${esc(module)}</strong>
                    ${!locked?`<button type="button" class="btn btn-sm btn-link module-toggle text-decoration-none" data-module="${esc(module)}">Tout sélectionner</button>`:''}
                </div>
                <div class="p-3 row g-3">
                    ${list.map(p=>`<div class="col-md-6">
                        <label class="d-flex gap-2 border rounded-3 p-3 h-100 ${locked?'bg-light':''}">
                            <input class="form-check-input permission-check mt-1" type="checkbox" name="permission_ids[]" value="${p.id}" data-module="${esc(module)}" ${selected.has(Number(p.id))?'checked':''} ${locked?'disabled':''}>
                            <span><strong class="d-block">${esc(p.nom)}</strong><code class="small">${esc(p.code)}</code>${p.description?`<small class="d-block text-muted mt-1">${esc(p.description)}</small>`:''}</span>
                        </label>
                    </div>`).join('')}
                </div>
            </div>`).join('');

        document.querySelectorAll('.permission-check').forEach(c=>c.onchange=updatePermissionCount);
        document.querySelectorAll('.module-toggle').forEach(b=>b.onclick=()=>{
            const boxes=[...document.querySelectorAll(`.permission-check[data-module="${CSS.escape(b.dataset.module)}"]`)];
            const all=boxes.length&&boxes.every(x=>x.checked);boxes.forEach(x=>x.checked=!all);updatePermissionCount();
        });
        updatePermissionCount();permissionModal.show();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

function updatePermissionCount(){
    const total=document.querySelectorAll('.permission-check:checked').length;
    $('permissionSelectionCount').textContent=`${total} permission(s) sélectionnée(s)`;
}

$('permissionForm').onsubmit=async e=>{
    e.preventDefault();const btn=$('permissionSaveBtn');STAGIA.loading(btn,true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/roles/sync-permissions.php',new FormData(e.target));
        permissionModal.hide();STAGIA.toast(r.message);await load();
    }catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading(btn,false);}
};

$('roleSearch').oninput=e=>{query=e.target.value.trim();render();};
load();
});
</script>
<?php require_once __DIR__.'/../../../includes/app-footer.php'; ?>

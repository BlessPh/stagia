<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);
requirePermission($pdo,'user.view');
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$role=$_SESSION['role_code']??'';
$pageTitle='Utilisateurs';
$activePage=$role==='ADMIN_ACCUEIL'?'hopital-utilisateurs':'utilisateurs';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Utilisateurs</h1><p>Gérez uniquement les comptes internes de votre établissement. Les étudiants/stagiaires restent gérés depuis leur module dédié.</p></div>
    <button class="btn btn-primary-stagia" onclick="nouvelUtilisateur()"><i class="bi bi-plus-lg me-1"></i> Nouvel utilisateur</button>
</div>

<div class="alert alert-light border mb-4"><i class="bi bi-shield-check me-2 text-primary"></i><strong>Périmètre établissement.</strong> Aucun compte national ni rôle plateforme ne peut être créé ici.</div>

<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card"><div><span>UTILISATEURS</span><strong id="kpiTotal">0</strong><small>Comptes de l'établissement</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-people"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIFS</span><strong id="kpiActifs">0</strong><small>Comptes opérationnels</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-person-check"></i></div></div>
    <div class="stagia-kpi-card"><div><span>À ACTIVER</span><strong id="kpiActivation">0</strong><small>Invitations en attente</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-envelope"></i></div></div>
    <div class="stagia-kpi-card"><div><span>SUSPENDUS</span><strong id="kpiSuspendus">0</strong><small>Accès bloqués</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-person-x"></i></div></div>
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar"><div class="stagia-list-filters w-100">
    <div class="input-group stagia-table-search flex-grow-1"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span><input id="searchInput" class="form-control border-start-0" placeholder="Nom, e-mail, identifiant, fonction..."></div>
    <select id="roleFilter" class="form-select"><option value="">Tous les rôles</option></select>
    <select id="statusFilter" class="form-select"><option value="">Tous les comptes</option><option value="ACTIF">Actifs</option><option value="A_ACTIVER">À activer</option><option value="SUSPENDU">Suspendus</option></select>
    <button class="btn btn-light border" id="resetBtn" title="Réinitialiser"><i class="bi bi-arrow-clockwise"></i></button>
</div></div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>UTILISATEUR</th><th>IDENTIFIANT</th><th>RÔLE(S)</th><th>FONCTION LOCALE</th><th>COMPTE</th><th>DERNIÈRE CONNEXION</th><th class="text-center">ACTIONS</th></tr></thead>
<tbody id="usersBody"><tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
</table>
</div>
<div class="stagia-list-footer"><span id="footerCount">0 utilisateur(s)</span></div>
</div>
</main>

<div class="modal fade" id="userModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="userForm">
<div class="modal-header">
    <div><h5 class="modal-title" id="userTitle">Nouvel utilisateur</h5><small class="text-muted" id="userSubtitle">Choisissez l’activation par invitation ou par mot de passe temporaire.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>">
<input type="hidden" name="id" id="userId">
<div class="row g-3">
    <div class="col-md-4"><label class="form-label">Nom *</label><input name="nom" id="userNom" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Post-nom</label><input name="postnom" id="userPostnom" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">Prénom</label><input name="prenom" id="userPrenom" class="form-control"></div>
    <div class="col-md-6"><label class="form-label">E-mail *</label><input type="email" name="email" id="userEmail" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">Téléphone</label><input name="telephone" id="userTelephone" class="form-control"></div>
</div>

<div id="createFields" class="mt-4">
<hr><div class="row g-3">
    <div class="col-md-6"><label class="form-label">Rôle *</label><select name="role_id" id="userRole" class="form-select" required></select></div>
    <div class="col-md-6"><label class="form-label">Fonction locale</label><input name="fonction" id="userFonctionCreate" class="form-control" placeholder="Ex. Médecin encadreur, Secrétaire..."></div>
</div>
<div id="unitScopeBox" class="mt-3 d-none">
    <label class="form-label" id="unitScopeLabel">Département / coordination</label>
    <select name="scope_unit_id" id="scopeUnitId" class="form-select"></select>
    <small class="text-muted" id="unitScopeHelp">Sélectionnez le département ou la coordination concernée.</small>
</div>
<div class="mt-3">
    <label class="form-label">Mode d’activation *</label>
    <select name="activation_mode" id="activationMode" class="form-select">
        <option value="INVITATION" selected>Invitation e-mail (recommandé)</option>
        <option value="PASSWORD">Mot de passe temporaire — sans invitation</option>
    </select>
</div>

<div id="passwordModeBox" class="row g-3 mt-1 d-none">
    <div class="col-md-6">
        <label class="form-label">Mot de passe temporaire *</label>
        <div class="input-group">
            <input type="text" name="temporary_password" id="temporaryPassword" class="form-control" autocomplete="new-password">
            <button type="button" class="btn btn-light border" id="generatePasswordBtn" title="Générer"><i class="bi bi-shuffle"></i></button>
        </div>
        <small class="text-muted">8 caractères minimum. À communiquer directement à l’utilisateur.</small>
    </div>
    <div class="col-md-6">
        <label class="form-label">Confirmer le mot de passe *</label>
        <input type="text" name="temporary_password_confirm" id="temporaryPasswordConfirm" class="form-control" autocomplete="new-password">
    </div>
    <div class="col-12">
        <div class="alert alert-warning py-2 mb-0">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Le compte sera immédiatement <strong>ACTIF</strong> et aucun e-mail d’invitation ne sera envoyé.
            Utilisez surtout ce mode pour les tests locaux.
        </div>
    </div>
</div>

<div class="alert alert-light border mt-3 mb-0"><i class="bi bi-building me-1"></i> Le compte sera automatiquement rattaché à votre établissement.</div>
</div>

<div id="editFields" class="mt-4 d-none">
<hr><label class="form-label">Fonction locale</label><input name="fonction_edit" id="userFonctionEdit" class="form-control">
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button id="saveBtn" class="btn btn-primary-stagia"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?= BASE_URL ?>',ACTIONS=`${BASE}/actions/etablissements/utilisateurs`,$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('userModal')),form=$('userForm'),csrf='<?= $_SESSION['csrf'] ?>';
let users=[],roles=[],hostUnits=[],unitsLoaded=false,timer;
const badge=s=>s==='ACTIF'?'<span class="badge bg-success">Actif</span>':s==='A_ACTIVER'?'<span class="badge bg-warning text-dark">À activer</span>':'<span class="badge bg-secondary">Suspendu</span>';

async function loadScopeUnits(){
    if(unitsLoaded)return;
    unitsLoaded=true;
    try{
        const r=await STAGIA.request(`${BASE}/actions/stages/host-unit-list.php`);
        hostUnits=(r.data?.items||[]).filter(isCoordUnit);
    }catch(e){hostUnits=[];}
}
function norm(s){return String(s||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toUpperCase();}
function isCoordUnit(x){
    const t=norm(x.type);
    const parent=Number(x.parent_id||0);
    return parent===0 && ['COORDINATION','DEPARTEMENT','DEPARTMENT','DIRECTION','UNITE'].includes(t);
}
async function load(){
    const q=new URLSearchParams({q:$('searchInput').value.trim(),status:$('statusFilter').value,role_id:$('roleFilter').value});
    try{
        const r=await STAGIA.request(`${ACTIONS}/list.php?${q}`),s=r.data?.stats||{};
        users=r.data?.items||[];roles=r.data?.roles||[];
        $('kpiTotal').textContent=s.total||0;$('kpiActifs').textContent=s.actifs||0;$('kpiActivation').textContent=s.a_activer||0;$('kpiSuspendus').textContent=s.suspendus||0;
        await loadScopeUnits();fillRoles();render();updateUnitScope();
    }catch(e){$('usersBody').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;STAGIA.toast(e.message,'danger');}
}
function fillRoles(){
    const v=$('roleFilter').value,cur=$('userRole').value;
    $('roleFilter').innerHTML='<option value="">Tous les rôles</option>'+roles.map(r=>`<option value="${r.id}">${esc(r.nom)}</option>`).join('');
    $('roleFilter').value=v;
    $('userRole').innerHTML='<option value="">Sélectionner...</option>'+roles.map(r=>`<option value="${r.id}" data-code="${esc(r.code||r.role_code||'')}">${esc(r.nom)}</option>`).join('');
    if([...$('userRole').options].some(o=>o.value===cur))$('userRole').value=cur;
}
function render(){
    $('footerCount').textContent=`${users.length} utilisateur(s)`;
    if(!users.length){$('usersBody').innerHTML='<tr><td colspan="7" class="text-center py-5 text-muted">Aucun utilisateur trouvé.</td></tr>';return;}
    $('usersBody').innerHTML=users.map(u=>`<tr>
        <td><strong>${esc([u.prenom,u.nom,u.postnom].filter(Boolean).join(' '))}</strong><small class="d-block text-muted">${esc(u.email||'—')}</small></td>
        <td>${esc(u.identifiant||'—')}</td><td><span class="badge bg-light text-dark border">${esc(u.roles||'—')}</span></td><td>${esc(u.fonction||'—')}</td>
        <td>${badge(u.statut_compte)}</td><td>${u.derniere_connexion?esc(new Date(u.derniere_connexion.replace(' ','T')).toLocaleString('fr-FR')):'Jamais'}</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-primary edit-user" data-id="${u.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
            ${u.statut_compte==='A_ACTIVER'
                ?`<button type="button" class="btn btn-sm btn-outline-warning resend-user" data-id="${u.id}" title="Renvoyer l'invitation"><i class="bi bi-envelope-arrow-up"></i></button>`
                :`<button type="button" class="btn btn-sm ${u.statut_compte==='ACTIF'?'btn-outline-danger':'btn-outline-success'} status-user" data-id="${u.id}" data-status="${u.statut_compte}" title="${u.statut_compte==='ACTIF'?'Suspendre':'Réactiver'}"><i class="bi bi-${u.statut_compte==='ACTIF'?'person-dash':'person-check'}"></i></button>`}
        </td></tr>`).join('');

}
function currentRoleKey(){
    const o=$('userRole').selectedOptions[0];
    const txt=norm((o?.dataset.code||'')+' '+(o?.textContent||''));
    if(txt.includes('CHEF')&&txt.includes('SERVICE'))return 'CHEF_SERVICE';
    if(txt.includes('COORDINATEUR'))return 'COORDINATEUR_STAGES';
    if(txt.includes('ENCADREUR'))return 'ENCADREUR';
    if(txt.includes('EVALUATEUR')||txt.includes('EVALUATEUR'))return 'EVALUATEUR_CLINIQUE';
    return txt;
}
function roleNeedsUnitScope(k){return ['CHEF_SERVICE','COORDINATEUR_STAGES'].includes(k);}
function roleUsesUnitScope(k){return roleNeedsUnitScope(k)||['ENCADREUR','EVALUATEUR_CLINIQUE'].includes(k);}
function fillScopeUnits(){
    $('scopeUnitId').innerHTML='<option value="">Sélectionner...</option>'+hostUnits.map(u=>`<option value="${Number(u.id)}">${esc((u.code?u.code+' - ':'')+(u.nom||''))}</option>`).join('');
}
function updateUnitScope(){
    const k=currentRoleKey(),show=roleUsesUnitScope(k),required=roleNeedsUnitScope(k);
    $('unitScopeBox').classList.toggle('d-none',!show);
    $('scopeUnitId').required=show&&required;
    $('unitScopeLabel').innerHTML='Département / coordination '+(required?'<span class="text-danger">*</span>':'');
    $('unitScopeHelp').textContent=required?'Ce rôle doit être rattaché à un département ou une coordination.':'Rattachement facultatif à un département ou une coordination.';
    if(show)fillScopeUnits();else $('scopeUnitId').value='';
}
window.nouvelUtilisateur=()=>{
    form.reset();$('userId').value='';$('userTitle').textContent='Nouvel utilisateur';$('userSubtitle').textContent='Choisissez l’activation par invitation ou mot de passe temporaire.';
    $('createFields').classList.remove('d-none');$('editFields').classList.add('d-none');$('userRole').required=true;fillRoles();$('scopeUnitId').value='';updateUnitScope();
    $('activationMode').value='INVITATION';updateActivationMode();modal.show();
};
function editUser(u){
    if(!u)return;form.reset();$('userId').value=u.id;$('userNom').value=u.nom||'';$('userPostnom').value=u.postnom||'';$('userPrenom').value=u.prenom||'';
    $('userEmail').value=u.email||'';$('userTelephone').value=u.telephone||'';$('userFonctionEdit').value=u.fonction||'';
    $('userTitle').textContent='Modifier l’utilisateur';$('userSubtitle').textContent=u.identifiant||'';$('createFields').classList.add('d-none');$('editFields').classList.remove('d-none');$('userRole').required=false;$('scopeUnitId').required=false;$('unitScopeBox').classList.add('d-none');modal.show();
}

function generatePassword(){
    const chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#$%';
    let p='Stg@';
    for(let i=0;i<8;i++)p+=chars[Math.floor(Math.random()*chars.length)];
    $('temporaryPassword').value=p;
    $('temporaryPasswordConfirm').value=p;
}
function updateActivationMode(){
    const passwordMode=$('activationMode').value==='PASSWORD';
    $('passwordModeBox').classList.toggle('d-none',!passwordMode);
    $('temporaryPassword').required=passwordMode;
    $('temporaryPasswordConfirm').required=passwordMode;
    if(passwordMode&&!$('temporaryPassword').value)generatePassword();
}
$('usersBody').addEventListener('click',e=>{
    const edit=e.target.closest('.edit-user'),resendBtn=e.target.closest('.resend-user'),statusBtn=e.target.closest('.status-user');
    if(edit){editUser(users.find(u=>Number(u.id)===Number(edit.dataset.id)));return;}
    if(resendBtn){resend(Number(resendBtn.dataset.id));return;}
    if(statusBtn)toggleStatus(Number(statusBtn.dataset.id),statusBtn.dataset.status);
});
$('activationMode').addEventListener('change',updateActivationMode);
$('generatePasswordBtn').addEventListener('click',generatePassword);
$('userRole').addEventListener('change',updateUnitScope);

form.onsubmit=async e=>{
    e.preventDefault();const edit=!!$('userId').value,d=new FormData(form);STAGIA.loading($('saveBtn'),true);
    if(edit){d.delete('role_id');d.delete('scope_unit_id');d.delete('fonction');d.set('fonction',d.get('fonction_edit')||'');}d.delete('fonction_edit');
    try{const r=await STAGIA.post(`${ACTIONS}/${edit?'update.php':'store.php'}`,d);modal.hide();STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}
};
async function toggleStatus(id,status){
    if(!STAGIA.confirm(status==='ACTIF'?'Suspendre cet utilisateur ?':'Réactiver cet utilisateur ?'))return;
    const d=new FormData();d.append('csrf',csrf);d.append('id',id);
    try{const r=await STAGIA.post(`${ACTIONS}/status.php`,d);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}
}
async function resend(id){
    if(!STAGIA.confirm("Renvoyer l'invitation d'activation ?"))return;
    const d=new FormData();d.append('csrf',csrf);d.append('id',id);
    try{const r=await STAGIA.post(`${ACTIONS}/resend-activation.php`,d);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}
}
$('roleFilter').onchange=load;$('statusFilter').onchange=load;$('searchInput').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300)};
$('resetBtn').onclick=()=>{$('searchInput').value='';$('roleFilter').value='';$('statusFilter').value='';load()};load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

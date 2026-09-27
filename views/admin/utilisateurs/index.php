<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Utilisateurs';$activePage='admin-utilisateurs';
require_once __DIR__.'/../../../includes/app-header.php';
?>
<main class="dashboard-content">

<div class="stagia-page-head">
    <div><h1>Utilisateurs</h1><p>Gérez les comptes STAGIA. Les rôles supplémentaires et périmètres avancés seront gérés dans « Affectations de rôles ».</p></div>
    <button class="btn btn-primary-stagia" onclick="nouvelUtilisateur()"><i class="bi bi-plus-lg me-1"></i> Nouvel utilisateur</button>
</div>

<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card"><div><span>UTILISATEURS</span><strong id="kpiTotal">0</strong><small>Comptes enregistrés</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-people"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIFS</span><strong id="kpiActifs">0</strong><small>Comptes opérationnels</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-person-check"></i></div></div>
    <div class="stagia-kpi-card"><div><span>À ACTIVER</span><strong id="kpiActivation">0</strong><small>Invitation en attente</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-envelope"></i></div></div>
    <div class="stagia-kpi-card"><div><span>SUSPENDUS</span><strong id="kpiSuspendus">0</strong><small>Accès bloqués</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-person-x"></i></div></div>
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar">
<div class="stagia-list-filters w-100">
    <div class="input-group stagia-table-search flex-grow-1">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
        <input id="searchInput" class="form-control border-start-0" placeholder="Nom, e-mail, identifiant...">
    </div>
    <select id="roleFilter" class="form-select"><option value="">Tous les rôles</option></select>
    <select id="etabFilter" class="form-select"><option value="">Tous les établissements</option></select>
    <select id="statusFilter" class="form-select">
        <option value="">Tous les comptes</option><option value="ACTIF">Actifs</option>
        <option value="A_ACTIVER">À activer</option><option value="SUSPENDU">Suspendus</option>
    </select>
    <button class="btn btn-light border" id="resetBtn" title="Réinitialiser"><i class="bi bi-arrow-clockwise"></i></button>
</div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>UTILISATEUR</th><th>IDENTIFIANT</th><th>RÔLE(S)</th><th>ÉTABLISSEMENT(S)</th><th>COMPTE</th><th>DERNIÈRE CONNEXION</th><th class="text-center">ACTIONS</th></tr></thead>
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
    <div><h5 class="modal-title" id="userTitle">Nouvel utilisateur</h5><small class="text-muted" id="userSubtitle">Le compte sera activé par invitation e-mail.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="id" id="userId">

<div class="row g-3">
    <div class="col-md-4"><label class="form-label">Nom *</label><input name="nom" id="userNom" class="form-control" required></div>
    <div class="col-md-4"><label class="form-label">Post-nom</label><input name="postnom" id="userPostnom" class="form-control"></div>
    <div class="col-md-4"><label class="form-label">Prénom</label><input name="prenom" id="userPrenom" class="form-control"></div>
    <div class="col-md-6"><label class="form-label">E-mail *</label><input type="email" name="email" id="userEmail" class="form-control" required></div>
    <div class="col-md-6"><label class="form-label">Téléphone</label><input name="telephone" id="userTelephone" class="form-control"></div>
</div>

<div id="createFields" class="mt-4">
    <hr>
    <h6 class="mb-3">Accès principal</h6>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Rôle principal *</label><select name="role_id" id="userRole" class="form-select"></select></div>
        <div class="col-md-6" id="etabWrap"><label class="form-label" id="etabLabel">Établissement *</label><select name="etablissement_id" id="userEtab" class="form-select"></select></div>
        <div class="col-12" id="fonctionWrap"><label class="form-label">Fonction locale</label><input name="fonction" id="userFonctionCreate" class="form-control" placeholder="Ex. Secrétaire académique, Médecin encadreur..."></div>
    </div>
    <div class="alert alert-light border mt-3 mb-0" id="scopeInfo"></div>
</div>

<div id="editFields" class="mt-4 d-none">
    <hr>
    <label class="form-label">Fonction locale</label>
    <input name="fonction_edit" id="userFonctionEdit" class="form-control">
    <div class="alert alert-light border mt-3 mb-0"><i class="bi bi-info-circle me-1"></i> Les rôles et périmètres se gèrent séparément dans <strong>Affectations de rôles</strong>.</div>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button id="saveBtn" class="btn btn-primary-stagia"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),modal=new bootstrap.Modal($('userModal')),form=$('userForm'),esc=STAGIA.escape;
const csrf='<?= $_SESSION['csrf'] ?>';
let users=[],roles=[],etablissements=[],timer=null;

function badge(status){
    if(status==='ACTIF')return '<span class="badge bg-success">Actif</span>';
    if(status==='A_ACTIVER')return '<span class="badge bg-warning text-dark">À activer</span>';
    return '<span class="badge bg-secondary">Suspendu</span>';
}

async function load(){
    const qs=new URLSearchParams({
        q:$('searchInput').value.trim(),
        status:$('statusFilter').value,
        role_id:$('roleFilter').value,
        etablissement_id:$('etabFilter').value
    });
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/admin/utilisateurs/list.php?${qs}`);
        users=r.data.items||[];roles=r.data.roles||[];etablissements=r.data.etablissements||[];
        $('kpiTotal').textContent=r.data.stats.total;$('kpiActifs').textContent=r.data.stats.actifs;
        $('kpiActivation').textContent=r.data.stats.a_activer;$('kpiSuspendus').textContent=r.data.stats.suspendus;
        fillFilters();render();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

function fillFilters(){
    const rf=$('roleFilter').value,ef=$('etabFilter').value;
    $('roleFilter').innerHTML='<option value="">Tous les rôles</option>'+roles.map(r=>`<option value="${r.id}">${esc(r.nom)}</option>`).join('');
    $('etabFilter').innerHTML='<option value="">Tous les établissements</option>'+etablissements.map(e=>`<option value="${e.id}">${esc(e.nom)}</option>`).join('');
    $('roleFilter').value=rf;$('etabFilter').value=ef;
}

function render(){
    $('footerCount').textContent=`${users.length} utilisateur(s)`;
    $('usersBody').innerHTML=users.length?users.map(u=>`<tr>
        <td><strong>${esc([u.prenom,u.nom,u.postnom].filter(Boolean).join(' '))}</strong><small class="d-block text-muted">${esc(u.email||'—')}</small></td>
        <td>${esc(u.identifiant||'—')}</td>
        <td><span class="badge bg-light text-dark border">${esc(u.roles||'—')}</span></td>
        <td>${esc(u.etablissements||'Plateforme / Aucun')}</td>
        <td>${badge(u.statut_compte)}</td>
        <td>${u.derniere_connexion?esc(new Date(u.derniere_connexion.replace(' ','T')).toLocaleString('fr-FR')):'Jamais'}</td>
        <td class="text-center">
            <button class="btn btn-sm btn-outline-primary edit-user" data-id="${u.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
            ${u.statut_compte==='A_ACTIVER'?`<button class="btn btn-sm btn-outline-primary resend-user" data-id="${u.id}" title="Renvoyer l'invitation"><i class="bi bi-envelope-arrow-up"></i></button>`:''}
            ${u.statut_compte!=='A_ACTIVER'?`<button class="btn btn-sm btn-outline-primary status-user" data-id="${u.id}" data-status="${u.statut_compte}" title="${u.statut_compte==='ACTIF'?'Suspendre':'Réactiver'}"><i class="bi bi-${u.statut_compte==='ACTIF'?'person-dash':'person-check'}"></i></button>`:''}
        </td>
    </tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucun utilisateur trouvé.</td></tr>';

    document.querySelectorAll('.edit-user').forEach(b=>b.onclick=()=>editUser(users.find(x=>Number(x.id)===Number(b.dataset.id))));
    document.querySelectorAll('.resend-user').forEach(b=>b.onclick=()=>resend(Number(b.dataset.id)));
    document.querySelectorAll('.status-user').forEach(b=>b.onclick=()=>toggleStatus(Number(b.dataset.id),b.dataset.status));
}

function fillCreateSelects(){
    $('userRole').innerHTML='<option value="">Sélectionner...</option>'+roles.map(r=>`<option value="${r.id}" data-code="${r.code}">${esc(r.nom)}</option>`).join('');
    renderEtablissements();
}

function renderEtablissements(type=''){
    const ancienneValeur=$('userEtab').value;
    const liste=type?etablissements.filter(e=>e.type_etablissement===type):etablissements;
    $('userEtab').innerHTML='<option value="">Sélectionner...</option>'+liste.map(e=>`<option value="${e.id}">${esc(e.nom)} · ${esc(e.type_etablissement)}</option>`).join('');
    if(liste.some(e=>String(e.id)===ancienneValeur))$('userEtab').value=ancienneValeur;
}

function adaptRole(){
    const o=$('userRole').options[$('userRole').selectedIndex],code=o?.dataset.code||'';
    const isMinistry=code==='MINISTERE';
    const isPlatform=['SUPER_ADMIN','ORDRE_MEDECINS'].includes(code);
    const isSelf=code==='STAGIAIRE';
    const needsEtab=code!==''&&!isPlatform&&!isSelf;

    renderEtablissements(isMinistry?'MINISTERE':'');
    $('etabLabel').textContent=isMinistry?'Ministère représenté *':'Établissement *';

    $('etabWrap').classList.toggle('d-none',!needsEtab);
    $('fonctionWrap').classList.toggle('d-none',!needsEtab);
    $('userEtab').required=needsEtab;

    if(!needsEtab){
        $('userEtab').value='';
        $('userFonctionCreate').value='';
    }

    if(isMinistry){
        $('scopeInfo').innerHTML='<i class="bi bi-building-check me-1"></i> Sélection obligatoire : ce responsable sera rattaché au <strong>ministère choisi</strong> et limité à ses organisations autorisées.';
    }else if(isPlatform){
        $('scopeInfo').innerHTML='<i class="bi bi-globe me-1"></i> Périmètre automatique : <strong>Plateforme (PLATFORM)</strong>. Aucun établissement requis.';
    }else if(isSelf){
        $('scopeInfo').innerHTML='<i class="bi bi-person me-1"></i> Périmètre automatique : <strong>Personnel (SELF)</strong>.';
    }else if(code){
        $('scopeInfo').innerHTML='<i class="bi bi-building me-1"></i> Périmètre automatique : <strong>Établissement (ORGANIZATION)</strong>.';
    }else{
        $('scopeInfo').innerHTML='Sélectionnez un rôle principal.';
    }
}

window.nouvelUtilisateur=()=>{
    form.reset();$('userId').value='';$('userTitle').textContent='Nouvel utilisateur';$('userSubtitle').textContent='Le compte sera activé par invitation e-mail.';
    $('createFields').classList.remove('d-none');$('editFields').classList.add('d-none');fillCreateSelects();adaptRole();modal.show();
};

function editUser(u){
    if(!u)return;form.reset();$('userId').value=u.id;$('userNom').value=u.nom||'';$('userPostnom').value=u.postnom||'';
    $('userPrenom').value=u.prenom||'';$('userEmail').value=u.email||'';$('userTelephone').value=u.telephone||'';
    $('userFonctionEdit').value=u.fonction||'';$('userTitle').textContent='Modifier l’utilisateur';$('userSubtitle').textContent=u.identifiant||'';
    $('createFields').classList.add('d-none');$('editFields').classList.remove('d-none');modal.show();
}
$('userRole').onchange=adaptRole;

form.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('saveBtn'),true);
    const edit=!!$('userId').value,data=new FormData(form);
    if(edit){
        data.delete('role_id');data.delete('etablissement_id');data.delete('fonction');
        data.set('fonction',data.get('fonction_edit')||'');
    }else data.delete('fonction_edit');
    const url=BASE_URL+'/actions/admin/utilisateurs/'+(edit?'update.php':'store.php');
    try{const r=await STAGIA.post(url,data);modal.hide();STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}
};

async function toggleStatus(id,status){
    if(!STAGIA.confirm(status==='ACTIF'?'Suspendre cet utilisateur ?':'Réactiver cet utilisateur ?'))return;
    const d=new FormData();d.append('csrf',csrf);d.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/admin/utilisateurs/status.php',d);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}
}

async function resend(id){
    if(!STAGIA.confirm("Renvoyer une nouvelle invitation d'activation ?"))return;
    const d=new FormData();d.append('csrf',csrf);d.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/admin/utilisateurs/resend-activation.php',d);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}
}

['roleFilter','etabFilter','statusFilter'].forEach(id=>$(id).onchange=load);
$('searchInput').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};
$('resetBtn').onclick=()=>{$('searchInput').value='';$('roleFilter').value='';$('etabFilter').value='';$('statusFilter').value='';load();};
load();
});
</script>
<?php require_once __DIR__.'/../../../includes/app-footer.php'; ?>

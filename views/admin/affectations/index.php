<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/admin-scope.php';

$adminContexte=exigerAdministrationRoles($pdo);
requirePermission($pdo,'user.role.assign');
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$isSuper=$adminContexte['super'];
$pageTitle='Affectations de rôles';$activePage='admin-affectations';
require_once __DIR__.'/../../../includes/app-header.php';
?>
<style>
#assignmentModal .modal-dialog{height:calc(100vh - 2rem);max-height:calc(100vh - 2rem)}
#assignmentModal .modal-content{max-height:calc(100vh - 2rem)}
#assignmentModal .modal-body{overflow-y:scroll!important;max-height:calc(100vh - 185px);scrollbar-gutter:stable}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Affectations de rôles</h1><p>Attribuez un rôle dans un périmètre explicite. Un rôle indique <strong>quoi faire</strong> ; le périmètre indique <strong>où</strong>.</p></div>
    <button class="btn btn-primary-stagia" onclick="newAssignment()"><i class="bi bi-plus-lg me-1"></i> Nouvelle affectation</button>
</div>

<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card"><div><span>TOTAL</span><strong id="kTotal">0</strong><small>Affectations enregistrées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-person-badge"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIVES</span><strong id="kActive">0</strong><small>Droits actuellement applicables</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-shield-check"></i></div></div>
    <div class="stagia-kpi-card"><div><span>PLANIFIÉES</span><strong id="kPlanned">0</strong><small>Début prévu ultérieurement</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-clock"></i></div></div>
    <div class="stagia-kpi-card"><div><span>RÉVOQUÉES</span><strong id="kRevoked">0</strong><small>Historique conservé</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-shield-x"></i></div></div>
</div>

<div class="alert alert-light border">
<i class="bi bi-info-circle text-primary me-1"></i>
Les affectations ne sont jamais supprimées : elles sont révoquées et restent traçables. Les actions métier sensibles devront encore vérifier le contexte et la politique métier.
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar"><div class="stagia-list-filters w-100">
    <div class="input-group stagia-table-search flex-grow-1"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span><input id="search" class="form-control border-start-0" placeholder="Utilisateur, e-mail, rôle..."></div>
    <select id="roleFilter" class="form-select"><option value="">Tous les rôles</option></select>
    <select id="scopeFilter" class="form-select">
        <option value="">Tous les périmètres</option><option value="PLATFORM">Plateforme</option>
        <option value="ORGANIZATION">Organisation</option><option value="UNIT">Unité</option>
        <option value="CAMPAIGN">Campagne</option><option value="INTERNSHIP">Stage</option><option value="SELF">Personnel</option>
    </select>
    <select id="statusFilter" class="form-select">
        <option value="">Tous les statuts</option><option value="ACTIVE">Actives</option>
        <option value="PLANNED">Planifiées</option><option value="EXPIRED">Expirées</option><option value="REVOKED">Révoquées</option>
    </select>
    <button class="btn btn-light border" id="resetBtn"><i class="bi bi-arrow-clockwise"></i></button>
</div></div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>UTILISATEUR</th><th>RÔLE</th><th>PÉRIMÈTRE</th><th>CONTEXTE</th><th>VALIDITÉ</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
<tbody id="body"><tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm"></div></td></tr></tbody>
</table>
</div>
<div class="stagia-list-footer"><span id="count">0 affectation(s)</span></div>
</div>
</main>

<div class="modal fade" id="assignmentModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content border-0 shadow">
<form id="form">
<div class="modal-header"><div><h5 class="modal-title">Nouvelle affectation de rôle</h5><small class="text-muted">Utilisateur + rôle + périmètre + contexte.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">

<div class="row g-3">
<div class="col-md-6"><label class="form-label">Utilisateur *</label><select name="user_id" id="userId" class="form-select" required></select></div>
<div class="col-md-6"><label class="form-label">Rôle *</label><select name="role_id" id="roleId" class="form-select" required></select></div>

<div class="col-md-6"><label class="form-label">Périmètre *</label>
<select name="scope_type" id="scopeType" class="form-select" required>
<option value="">Sélectionner...</option><option value="PLATFORM">PLATFORM — Toute la plateforme</option>
<option value="ORGANIZATION">ORGANIZATION — Un établissement</option><option value="UNIT">UNIT — Une unité précise</option>
<option value="CAMPAIGN">CAMPAIGN — Une campagne précise</option><option value="INTERNSHIP">INTERNSHIP — Un stage précis</option>
<option value="SELF">SELF — Données personnelles</option>
</select></div>

<div class="col-md-6 d-none" id="entityWrap"><label class="form-label">Type d’unité *</label>
<select name="scope_entity" id="scopeEntity" class="form-select">
<option value="">Sélectionner...</option>
<option value="ACADEMIC_UNIT">Unité académique — Faculté / Institut / École...</option>
<option value="DEPARTMENT">Département académique</option>
<option value="PROGRAM">Filière / Programme</option>
<option value="HOST_UNIT">Service / Unité d’accueil</option>
</select></div>

<div class="col-md-6 d-none" id="etabWrap"><label class="form-label">Établissement *</label><select name="etablissement_id" id="etabId" class="form-select"></select></div>
<div class="col-md-6 d-none" id="contextWrap"><label class="form-label" id="contextLabel">Contexte *</label><select name="scope_id" id="scopeId" class="form-select"></select></div>
</div>

<div class="alert alert-light border mt-3 mb-0" id="scopeHelp">Sélectionnez un périmètre.</div>

<hr class="my-4">
<h6>Durée de l’affectation</h6>
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Début</label><input type="datetime-local" name="starts_at" class="form-control"></div>
<div class="col-md-6"><label class="form-label">Fin</label><input type="datetime-local" name="ends_at" class="form-control"></div>
<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="principal" value="1" id="principal"><label class="form-check-label" for="principal"><strong>Rôle principal</strong> — utilisé comme rôle par défaut pendant la transition avec l’ancien système.</label></div></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',SUPER=<?= $isSuper?'true':'false' ?>,$=id=>document.getElementById(id),esc=STAGIA.escape,csrf='<?= $_SESSION['csrf'] ?>';
const modal=new bootstrap.Modal($('assignmentModal'));
let items=[],roles=[],users=[],etabs=[],timer=null,hostDeps=[],hostServices=[];
const allScopeOptions=$('scopeType').innerHTML;

function renderAssignmentEstablishments(type=''){
    const old=$('etabId').value;
    const list=type?etabs.filter(e=>e.type_etablissement===type):etabs;
    $('etabId').innerHTML='<option value="">Sélectionner...</option>'+list.map(e=>`<option value="${e.id}">${esc(e.nom)} · ${esc(e.type_etablissement)}</option>`).join('');
    if(list.some(e=>String(e.id)===old))$('etabId').value=old;
}

function ensureChefFields(){
    if($('chefScopeWrap'))return;

    $('contextWrap').insertAdjacentHTML('afterend',`
        <div class="col-12 d-none" id="chefScopeWrap">
            <div class="alert alert-light border mb-2">
                <i class="bi bi-info-circle me-1 text-primary"></i>
                Pour <strong>Chef de service</strong>, le département et le service sont facultatifs.
                Service choisi = périmètre service. Département seul = périmètre département. Aucun choix = tout l’établissement.
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Département / coordination</label>
                    <select name="departement_id" id="departementId" class="form-select">
                        <option value="">Tout l’établissement</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Service / unité</label>
                    <select name="service_id" id="serviceId" class="form-select">
                        <option value="">Facultatif</option>
                    </select>
                </div>
            </div>
        </div>
    `);

    $('departementId').onchange=()=>{
        fillServices();
        prepareChefScope();
    };

    $('serviceId').onchange=prepareChefScope;
}

function showChefFields(show){
    ensureChefFields();
    $('chefScopeWrap').classList.toggle('d-none',!show);
    if(!show){
        $('departementId').value='';
        $('serviceId').value='';
    }
}

async function loadHospitalUnits(){
    ensureChefFields();

    const eid=SUPER?$('etabId').value:(etabs[0]?.id||'');
    if(!eid)return;

    try{
        const r=await STAGIA.request(BASE_URL+'/actions/admin/affectations/host-units.php?'+new URLSearchParams({etablissement_id:eid}));
        hostDeps=r.data.departements||[];
        hostServices=r.data.services||[];

        $('departementId').innerHTML='<option value="">Tout l’établissement</option>'+hostDeps.map(x=>`<option value="${x.id}">${esc(x.label)}</option>`).join('');
        fillServices();
    }catch(e){
        hostDeps=[];
        hostServices=[];
        $('departementId').innerHTML='<option value="">Tout l’établissement</option>';
        $('serviceId').innerHTML='<option value="">Aucun service trouvé</option>';
    }
}

function fillServices(){
    const dep=Number($('departementId')?.value||0);
    const list=dep?hostServices.filter(s=>Number(s.parent_id)===dep):hostServices;

    $('serviceId').innerHTML='<option value="">Facultatif</option>'+list.map(x=>`<option value="${x.id}">${esc(x.label)}</option>`).join('');
}

function prepareChefScope(){
    if(!$('chefScopeWrap')||$('chefScopeWrap').classList.contains('d-none'))return;

    const dep=Number($('departementId').value||0);
    const serv=Number($('serviceId').value||0);

    $('scopeEntity').value='HOST_UNIT';

    if(serv){
        $('scopeType').value='UNIT';
        $('scopeId').innerHTML=`<option value="${serv}" selected>${serv}</option>`;
    }else if(dep){
        $('scopeType').value='UNIT';
        $('scopeId').innerHTML=`<option value="${dep}" selected>${dep}</option>`;
    }else{
        $('scopeType').value='ORGANIZATION';
        $('scopeId').innerHTML='<option value="">Sélectionner...</option>';
    }
}

const scopeNames={PLATFORM:'Plateforme',ORGANIZATION:'Organisation',UNIT:'Unité',CAMPAIGN:'Campagne',INTERNSHIP:'Stage',SELF:'Personnel'};
const statusBadge=s=>({
ACTIVE:'<span class="badge bg-success">Active</span>',
PLANNED:'<span class="badge bg-warning text-dark">Planifiée</span>',
EXPIRED:'<span class="badge bg-secondary">Expirée</span>',
REVOKED:'<span class="badge bg-danger">Révoquée</span>'
}[s]||s);

async function load(){
    const qs=new URLSearchParams({q:$('search').value.trim(),role_id:$('roleFilter').value,scope_type:$('scopeFilter').value,status:$('statusFilter').value});

    try{
        const r=await STAGIA.request(BASE_URL+'/actions/admin/affectations/list.php?'+qs);

        items=r.data.items||[];
        roles=r.data.roles||[];
        users=r.data.users||[];
        etabs=r.data.etablissements||[];

        if(!SUPER)$('scopeFilter').classList.add('d-none');

        $('kTotal').textContent=r.data.stats.total;
        $('kActive').textContent=r.data.stats.actives;
        $('kPlanned').textContent=r.data.stats.planned;
        $('kRevoked').textContent=r.data.stats.revoked;

        fillFilters();
        render();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

function fillFilters(){
    const old=$('roleFilter').value;
    $('roleFilter').innerHTML='<option value="">Tous les rôles</option>'+roles.map(r=>`<option value="${r.id}">${esc(r.nom)}</option>`).join('');
    $('roleFilter').value=old;
}

function render(){
    $('count').textContent=`${items.length} affectation(s)`;

    $('body').innerHTML=items.length?items.map(a=>{
        const valid=[
            a.starts_at?'Début : '+esc(a.starts_at.replace(' ',' · ')):'Immédiate',
            a.ends_at?'Fin : '+esc(a.ends_at.replace(' ',' · ')):'Sans échéance'
        ];

        return `<tr>
            <td>
                <strong>${esc([a.prenom,a.nom,a.postnom].filter(Boolean).join(' '))}</strong>
                <small class="d-block text-muted">${esc(a.email||a.identifiant||'')}</small>
            </td>
            <td>
                <strong>${esc(a.role_nom)}</strong>
                <small class="d-block text-muted">${esc(a.role_code)}</small>
                ${Number(a.principal)===1?'<span class="badge bg-primary mt-1">Principal</span>':''}
            </td>
            <td>
                <span class="badge bg-light text-dark border">${esc(scopeNames[a.scope_type]||a.scope_type)}</span>
                <small class="d-block text-muted mt-1">${esc(a.scope_entity||'')}</small>
            </td>
            <td>
                <strong>${esc(a.context_label||'—')}</strong>
                ${a.etablissement_nom&&a.scope_type!=='ORGANIZATION'?`<small class="d-block text-muted">${esc(a.etablissement_nom)}</small>`:''}
            </td>
            <td><small>${valid.join('<br>')}</small></td>
            <td>${statusBadge(a.effective_status)}</td>
            <td class="text-center text-nowrap">
                ${a.effective_status==='ACTIVE'&&Number(a.principal)!==1?`<button class="btn btn-sm btn-outline-primary make-principal" data-id="${a.id}" title="Définir comme principal"><i class="bi bi-star"></i></button>`:''}
                ${Number(a.actif)===1?`<button class="btn btn-sm btn-outline-danger revoke" data-id="${a.id}" title="Révoquer"><i class="bi bi-shield-x"></i></button>`:
                `<button class="btn btn-sm btn-outline-success reactivate" data-id="${a.id}" title="Réactiver"><i class="bi bi-arrow-counterclockwise"></i></button>`}
            </td>
        </tr>`;
    }).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune affectation trouvée.</td></tr>';

    document.querySelectorAll('.make-principal').forEach(b=>b.onclick=()=>makePrincipal(Number(b.dataset.id)));
    document.querySelectorAll('.revoke').forEach(b=>b.onclick=()=>revoke(Number(b.dataset.id)));
    document.querySelectorAll('.reactivate').forEach(b=>b.onclick=()=>reactivate(Number(b.dataset.id)));
}

window.newAssignment=()=>{
    $('form').reset();
    ensureChefFields();
    showChefFields(false);

    $('userId').innerHTML='<option value="">Sélectionner...</option>'+users.map(u=>`<option value="${u.id}">${esc([u.prenom,u.nom,u.postnom].filter(Boolean).join(' '))} · ${esc(u.email||u.identifiant||'')}</option>`).join('');
    $('roleId').innerHTML='<option value="">Sélectionner...</option>'+roles.map(r=>`<option value="${r.id}">${esc(r.nom)} · ${esc(r.code)}</option>`).join('');

    renderAssignmentEstablishments();

    if(!SUPER&&etabs.length){
        $('etabId').value=etabs[0].id;
        $('scopeType').closest('.col-md-6').classList.add('d-none');
        $('etabWrap').classList.add('d-none');
        $('entityWrap').classList.add('d-none');
        $('contextWrap').classList.add('d-none');
        $('scopeType').innerHTML='<option value="ORGANIZATION">Organisation</option><option value="UNIT">Unité</option>';
        $('scopeType').value='ORGANIZATION';
        $('scopeEntity').value='HOST_UNIT';
        $('scopeHelp').innerHTML='<i class="bi bi-person-badge me-1"></i> Sélectionnez le rôle à attribuer.';
    }else{
        $('scopeType').innerHTML=allScopeOptions;
        adaptScope();
    }

    modal.show();
};

async function adaptScope(){
    showChefFields(false);

    if(!SUPER){
        const selected=roles.find(r=>Number(r.id)===Number($('roleId').value));
        const code=selected?.code||'';

        if(etabs.length)$('etabId').value=etabs[0].id;

        $('etabWrap').classList.add('d-none');
        $('entityWrap').classList.add('d-none');
        $('contextWrap').classList.add('d-none');

        if(code==='CHEF_SERVICE'){
            $('scopeType').innerHTML='<option value="ORGANIZATION">Organisation</option><option value="UNIT">Département / service</option>';
            $('scopeType').value='ORGANIZATION';
            $('scopeEntity').value='HOST_UNIT';
            $('scopeType').closest('.col-md-6').classList.add('d-none');

            showChefFields(true);
            await loadHospitalUnits();
            prepareChefScope();

            $('scopeHelp').innerHTML='<i class="bi bi-diagram-3 me-1"></i> Chef de service : département et service facultatifs.';
        }else if(code==='EVALUATEUR_CLINIQUE'){
            $('scopeType').innerHTML='<option value="UNIT">Service / unité précise</option>';
            $('scopeType').value='UNIT';
            $('scopeEntity').value='HOST_UNIT';
            $('scopeType').closest('.col-md-6').classList.remove('d-none');
            $('contextWrap').classList.remove('d-none');
            $('contextLabel').textContent='Service / unité *';
            $('scopeHelp').innerHTML='<i class="bi bi-person-badge me-1"></i> Ce rôle clinique exige un service / une unité.';
            await loadContext();
        }else if(code==='POINTEUR'){
            const old=['ORGANIZATION','UNIT'].includes($('scopeType').value)?$('scopeType').value:'ORGANIZATION';

            $('scopeType').innerHTML='<option value="ORGANIZATION">Tout l’établissement</option><option value="UNIT">Service / unité précise</option>';
            $('scopeType').value=old;
            $('scopeEntity').value='HOST_UNIT';
            $('scopeType').closest('.col-md-6').classList.remove('d-none');
            $('contextWrap').classList.toggle('d-none',$('scopeType').value!=='UNIT');

            if($('scopeType').value==='UNIT'){
                $('contextLabel').textContent='Service / unité *';
                $('scopeHelp').innerHTML='<i class="bi bi-diagram-3 me-1"></i> Le pointeur ne verra que l’unité sélectionnée.';
                await loadContext();
            }else{
                $('scopeId').innerHTML='<option value="">Sélectionner...</option>';
                $('scopeHelp').innerHTML='<i class="bi bi-building me-1"></i> Le pointeur pourra intervenir dans tout l’établissement.';
            }
        }else{
            $('scopeType').innerHTML='<option value="ORGANIZATION">Organisation</option>';
            $('scopeType').value='ORGANIZATION';
            $('scopeEntity').value='';
            $('scopeId').innerHTML='<option value="">Sélectionner...</option>';
            $('scopeType').closest('.col-md-6').classList.add('d-none');
            $('scopeHelp').innerHTML=selected?'<i class="bi bi-building me-1"></i> Périmètre imposé : '+esc(etabs[0]?.nom||'établissement'):'<i class="bi bi-person-badge me-1"></i> Sélectionnez un rôle.';
        }

        return;
    }

    const selectedRole=roles.find(r=>Number(r.id)===Number($('roleId').value));

    if(selectedRole&&selectedRole.code==='MINISTERE'){
        $('scopeType').innerHTML='<option value="ORGANIZATION">ORGANIZATION — Ministère représenté</option>';
        $('scopeType').value='ORGANIZATION';

        renderAssignmentEstablishments('MINISTERE');

        $('entityWrap').classList.add('d-none');
        $('contextWrap').classList.add('d-none');
        $('etabWrap').classList.remove('d-none');
        $('scopeHelp').innerHTML='<i class="bi bi-building-check me-1"></i> Le rôle MINISTERE exige un rattachement à un établissement de type <strong>MINISTERE</strong>.';
        return;
    }

    if($('scopeType').options.length===1&&$('scopeType').value==='ORGANIZATION')
        $('scopeType').innerHTML=allScopeOptions;

    renderAssignmentEstablishments();

    const scope=$('scopeType').value;

    $('entityWrap').classList.toggle('d-none',scope!=='UNIT');
    $('etabWrap').classList.toggle('d-none',!['ORGANIZATION','UNIT','CAMPAIGN','INTERNSHIP'].includes(scope));
    $('contextWrap').classList.toggle('d-none',!['UNIT','CAMPAIGN','INTERNSHIP'].includes(scope));
    $('scopeId').innerHTML='<option value="">Sélectionner...</option>';

    if(scope==='PLATFORM')$('scopeHelp').innerHTML='<i class="bi bi-globe me-1"></i> Accès sur toute la plateforme.';
    else if(scope==='SELF')$('scopeHelp').innerHTML='<i class="bi bi-person me-1"></i> Accès uniquement aux propres données.';
    else if(scope==='ORGANIZATION')$('scopeHelp').innerHTML='<i class="bi bi-building me-1"></i> Accès limité à l’établissement sélectionné.';
    else if(scope==='UNIT')$('scopeHelp').innerHTML='<i class="bi bi-diagram-3 me-1"></i> Choisissez l’établissement puis le type d’unité.';
    else if(scope==='CAMPAIGN'){
        $('contextLabel').textContent='Campagne *';
        $('scopeHelp').innerHTML='<i class="bi bi-calendar3 me-1"></i> Accès limité à une campagne.';
        await loadContext();
    }else if(scope==='INTERNSHIP'){
        $('contextLabel').textContent='Stage *';
        $('scopeHelp').innerHTML='<i class="bi bi-briefcase me-1"></i> Accès limité à une affectation.';
        await loadContext();
    }else $('scopeHelp').textContent='Sélectionnez un périmètre.';
}

async function loadContext(){
    const scope=$('scopeType').value;
    const entity=!SUPER&&scope==='UNIT'?'HOST_UNIT':$('scopeEntity').value;
    const etab=SUPER?$('etabId').value:(etabs[0]?.id||'');

    if(!SUPER&&scope!=='UNIT')return;

    if(scope==='UNIT'&&(!entity||!etab)){
        $('scopeId').innerHTML='<option value="">Sélectionnez d’abord l’établissement et le type d’unité...</option>';
        return;
    }

    if(scope==='UNIT')$('contextLabel').textContent=SUPER?'Unité / Ressource *':'Service / unité *';
    if(!['UNIT','CAMPAIGN','INTERNSHIP'].includes(scope))return;

    try{
        const qs=new URLSearchParams({scope_type:scope,scope_entity:entity,etablissement_id:etab});
        const r=await STAGIA.request(BASE_URL+'/actions/admin/affectations/options.php?'+qs);

        $('scopeId').innerHTML='<option value="">Sélectionner...</option>'+(r.data.items||[]).map(x=>`<option value="${x.id}">${esc(x.label)}</option>`).join('');
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

$('scopeType').onchange=adaptScope;
$('roleId').onchange=adaptScope;
$('scopeEntity').onchange=loadContext;
$('etabId').onchange=async()=>{
    await loadContext();
    if(!$('chefScopeWrap')?.classList.contains('d-none'))await loadHospitalUnits();
};

$('form').onsubmit=async e=>{
    e.preventDefault();

    const selected=roles.find(r=>Number(r.id)===Number($('roleId').value));
    if(selected?.code==='CHEF_SERVICE')prepareChefScope();

    STAGIA.loading($('saveBtn'),true);

    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/affectations/store.php',new FormData(e.target));
        modal.hide();
        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('saveBtn'),false);
    }
};

async function makePrincipal(id){
    if(!STAGIA.confirm('Définir cette affectation comme rôle principal ?'))return;

    const d=new FormData();
    d.append('csrf',csrf);
    d.append('id',id);

    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/affectations/principal.php',d);
        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

async function revoke(id){
    const reason=prompt('Motif de révocation :');

    if(reason===null)return;
    if(!reason.trim()){
        STAGIA.toast('Le motif est obligatoire.','warning');
        return;
    }

    const d=new FormData();
    d.append('csrf',csrf);
    d.append('id',id);
    d.append('reason',reason.trim());

    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/affectations/status.php',d);
        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

async function reactivate(id){
    if(!STAGIA.confirm('Réactiver cette affectation ?'))return;

    const d=new FormData();
    d.append('csrf',csrf);
    d.append('id',id);

    try{
        const r=await STAGIA.post(BASE_URL+'/actions/admin/affectations/status.php',d);
        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

['roleFilter','scopeFilter','statusFilter'].forEach(id=>$(id).onchange=load);
$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300)};
$('resetBtn').onclick=()=>{
    $('search').value='';
    $('roleFilter').value='';
    $('scopeFilter').value='';
    $('statusFilter').value='';
    load();
};

load();
});
</script>
<?php require_once __DIR__.'/../../../includes/app-footer.php'; ?>

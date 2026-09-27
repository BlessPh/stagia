<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'group.hosting.view');

if(!contextHostEnabled()){
    http_response_code(403);
    exit("Cet établissement n'est pas une structure d'accueil.");
}

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Groupes & rotations D4';
$activePage='hospital-rotations';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Groupes & rotations D4</h1>
        <p>Regrouper les stagiaires par campagne et promotion, puis construire leur parcours ordonné entre services et sous-unités.</p>
    </div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-info-circle text-primary me-1"></i>
    <strong>Le D4 utilise des groupes configurables.</strong>
    Un même groupe suit un calendrier commun de rotations. La publication génère ensuite les rotations individuelles de chaque stagiaire.
</div>

<div class="stagia-list-card p-3 mb-4">
<div class="row g-3">
    <div class="col-lg-6">
        <label class="form-label">Campagne D4 *</label>
        <select id="campaign" class="form-select"></select>
    </div>
    <div class="col-lg-6">
        <label class="form-label">Promotion *</label>
        <select id="promotion" class="form-select"></select>
    </div>
</div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">STAGIAIRES ADMIS/AFFECTÉS</small><h3 id="kStudents">0</h3></div></div>
    <div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">SANS GROUPE</small><h3 id="kUngrouped">0</h3></div></div>
    <div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">GROUPES</small><h3 id="kGroups">0</h3></div></div>
    <div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">ROTATIONS PLANIFIÉES</small><h3 id="kRotations">0</h3></div></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1">Groupes de stagiaires</h5>
        <small class="text-muted">Un étudiant ne peut appartenir qu’à un seul groupe pour une même campagne.</small>
    </div>
    <button id="newGroupBtn" class="btn btn-primary-stagia d-none">
        <i class="bi bi-plus-lg me-1"></i>Nouveau groupe
    </button>
</div>

<div id="groups"></div>
</main>

<div class="modal fade" id="groupModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form id="groupForm">
<div class="modal-header">
    <div><h5 class="modal-title">Créer un groupe</h5><small class="text-muted" id="groupContext"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="campaign_id" id="groupCampaign">
<input type="hidden" name="promotion_id" id="groupPromotion">

<div class="mb-3">
    <label class="form-label">Nom du groupe *</label>
    <input name="nom" class="form-control" placeholder="Ex. Groupe A" required>
</div>

<div class="mb-3">
    <label class="form-label">Capacité du groupe</label>
    <input type="number" min="1" name="capacite" class="form-control" placeholder="Ex. 50">
    <small class="text-muted">Laisser vide si aucune limite interne n’est imposée.</small>
</div>

<div>
    <label class="form-label">Description</label>
    <textarea name="description" class="form-control" rows="3"></textarea>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="saveGroupBtn">Créer le groupe</button>
</div>
</form>
</div>
</div>
</div>

<div class="modal fade" id="membersModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
<div class="modal-content">
<div class="modal-header">
    <div><h5 class="modal-title" id="membersTitle">Membres du groupe</h5><small class="text-muted" id="membersMeta"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <button class="btn btn-sm btn-light border" id="selectAllUngrouped">
            <i class="bi bi-check2-square me-1"></i>Sélectionner tous les disponibles
        </button>
        <span class="small text-muted" id="memberSelection">0 sélectionné</span>
    </div>

    <div class="table-responsive">
    <table class="table stagia-modern-table align-middle mb-0">
    <thead><tr><th></th><th>STAGIAIRE</th><th>AFFECTATION ACTUELLE</th><th>ÉTAT GROUPE</th></tr></thead>
    <tbody id="memberRows"></tbody>
    </table>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Fermer</button>
    <button type="button" class="btn btn-primary-stagia" id="addMembersBtn" disabled>
        Ajouter au groupe
    </button>
</div>
</div>
</div>
</div>

<div class="modal fade" id="rotationModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
<form id="rotationForm">
<div class="modal-header">
    <div><h5 class="modal-title">Ajouter une rotation</h5><small class="text-muted" id="rotationGroupName"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="group_id" id="rotationGroupId">

<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">Séquence *</label>
        <input type="number" min="1" name="sequence_no" id="rotationSequence" class="form-control" required>
    </div>
    <div class="col-md-8">
        <label class="form-label">Service / unité *</label>
        <select name="host_unit_id" id="rotationUnit" class="form-select" required></select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Début *</label>
        <input type="date" name="date_debut" class="form-control" required>
    </div>
    <div class="col-md-6">
        <label class="form-label">Fin *</label>
        <input type="date" name="date_fin" class="form-control" required>
    </div>
</div>

<div class="mt-3">
    <label class="form-label">Objectifs</label>
    <textarea name="objectifs" class="form-control" rows="3" placeholder="Ex. Consultation, hospitalisation, urgences..."></textarea>
</div>

<div class="mt-3">
    <label class="form-label">Observation</label>
    <textarea name="observation" class="form-control" rows="2"></textarea>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="saveRotationBtn">Ajouter la rotation</button>
</div>
</form>
</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const groupModal=new bootstrap.Modal($('groupModal'));
const membersModal=new bootstrap.Modal($('membersModal'));
const rotationModal=new bootstrap.Modal($('rotationModal'));

let data={contexts:[],students:[],groups:[],units:[],permissions:{}};
let currentGroupId=0;

function contextPromotions(campaignId){
    const map=new Map();
    data.contexts
        .filter(x=>Number(x.campaign_id)===Number(campaignId))
        .forEach(x=>map.set(String(x.promotion_id),x));
    return [...map.values()];
}

async function load(){
    const campaignId=$('campaign').value;
    const promotionId=$('promotion').value;

    const qs=new URLSearchParams();
    if(campaignId)qs.set('campaign_id',campaignId);
    if(promotionId)qs.set('promotion_id',promotionId);

    try{
        const r=await STAGIA.request(
            BASE_URL+'/actions/espace-hopital/groupes-rotations-list.php'+
            (qs.toString()?'?'+qs.toString():'')
        );
        data=r.data;

        renderContextSelectors();
        render();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

function renderContextSelectors(){
    const selectedCampaign=String(data.selected.campaign_id||'');
    const campaigns=[];
    const seen=new Set();

    data.contexts.forEach(x=>{
        if(!seen.has(String(x.campaign_id))){
            seen.add(String(x.campaign_id));
            campaigns.push(x);
        }
    });

    $('campaign').innerHTML=campaigns.length
        ?campaigns.map(x=>`<option value="${x.campaign_id}">
            ${esc(x.campaign_code)} — ${esc(x.campaign_title)} · ${esc(x.university_name)}
        </option>`).join('')
        :'<option value="">Aucun placement D4 confirmé</option>';

    $('campaign').value=selectedCampaign;

    const promos=contextPromotions(selectedCampaign);
    $('promotion').innerHTML=promos.length
        ?promos.map(x=>`<option value="${x.promotion_id}">
            ${esc(x.promotion_name)}${x.level_code?' · '+esc(x.level_code):''}
        </option>`).join('')
        :'<option value="">Aucune promotion</option>';

    $('promotion').value=String(data.selected.promotion_id||'');
}

function render(){
    const ungrouped=(data.students||[]).filter(x=>!x.group_id);
    const rotationCount=(data.groups||[]).reduce((n,g)=>n+(g.rotations||[]).length,0);

    $('kStudents').textContent=(data.students||[]).length;
    $('kUngrouped').textContent=ungrouped.length;
    $('kGroups').textContent=(data.groups||[]).length;
    $('kRotations').textContent=rotationCount;

    $('newGroupBtn').classList.toggle(
        'd-none',
        !data.permissions.group_manage || !data.selected.campaign_id || !data.selected.promotion_id
    );

    $('groups').innerHTML=(data.groups||[]).length
        ?data.groups.map(g=>`
            <div class="stagia-list-card mb-3">
                <div class="p-3 border-bottom d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <h5 class="mb-1">${esc(g.nom)}</h5>
                        <small class="text-muted">
                            ${esc(g.code)} ·
                            ${g.student_count} stagiaire(s)
                            ${g.capacite?'/ '+g.capacite+' places':''}
                            · ${esc(g.statut)}
                        </small>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        ${data.permissions.group_manage
                            ?`<button class="btn btn-sm btn-outline-primary members" data-id="${g.id}">
                                <i class="bi bi-people me-1"></i>Gérer les membres
                              </button>`
                            :''}

                        ${data.permissions.rotation_manage
                            ?`<button class="btn btn-sm btn-outline-primary add-rotation" data-id="${g.id}">
                                <i class="bi bi-plus-lg me-1"></i>Ajouter rotation
                              </button>`
                            :''}

                        ${data.permissions.rotation_manage && (g.rotations||[]).length && Number(g.student_count)>0
                            ?`<button class="btn btn-sm btn-primary-stagia publish" data-id="${g.id}">
                                <i class="bi bi-send-check me-1"></i>Publier le plan
                              </button>`
                            :''}
                    </div>
                </div>

                <div class="table-responsive">
                <table class="table stagia-modern-table align-middle mb-0">
                <thead><tr>
                    <th>#</th>
                    <th>SERVICE / UNITÉ</th>
                    <th>PÉRIODE</th>
                    <th>OBJECTIFS</th>
                    <th>STATUT</th>
                </tr></thead>
                <tbody>
                    ${(g.rotations||[]).length
                        ?g.rotations.map(r=>`<tr>
                            <td><strong>${r.sequence_no}</strong></td>
                            <td>
                                <strong>${esc(r.unit_name)}</strong>
                                <small class="d-block text-muted">
                                    ${r.parent_name?esc(r.parent_name)+' → ':''}${esc(r.unit_type)}
                                </small>
                            </td>
                            <td>${esc(r.date_debut)} → ${esc(r.date_fin)}</td>
                            <td>${esc(r.objectifs||'—')}</td>
                            <td><span class="badge bg-light text-dark">${esc(r.statut)}</span></td>
                        </tr>`).join('')
                        :'<tr><td colspan="5" class="text-center py-4 text-muted">Aucune rotation planifiée.</td></tr>'}
                </tbody>
                </table>
                </div>
            </div>
        `).join('')
        :'<div class="alert alert-warning">Aucun groupe pour cette campagne et cette promotion.</div>';

    document.querySelectorAll('.members').forEach(b=>b.onclick=()=>openMembers(Number(b.dataset.id)));
    document.querySelectorAll('.add-rotation').forEach(b=>b.onclick=()=>openRotation(Number(b.dataset.id)));
    document.querySelectorAll('.publish').forEach(b=>b.onclick=()=>publishPlan(Number(b.dataset.id)));
}

$('campaign').onchange=()=>{
    const promos=contextPromotions($('campaign').value);
    $('promotion').innerHTML=promos.map(x=>`<option value="${x.promotion_id}">${esc(x.promotion_name)}${x.level_code?' · '+esc(x.level_code):''}</option>`).join('');
    load();
};

$('promotion').onchange=load;

$('newGroupBtn').onclick=()=>{
    const ctx=data.contexts.find(x=>
        Number(x.campaign_id)===Number(data.selected.campaign_id) &&
        Number(x.promotion_id)===Number(data.selected.promotion_id)
    );

    $('groupForm').reset();
    $('groupCampaign').value=data.selected.campaign_id;
    $('groupPromotion').value=data.selected.promotion_id;
    $('groupContext').textContent=ctx
        ?`${ctx.campaign_code} · ${ctx.promotion_name}${ctx.level_code?' · '+ctx.level_code:''}`
        :'';
    groupModal.show();
};

$('groupForm').onsubmit=async e=>{
    e.preventDefault();
    STAGIA.loading($('saveGroupBtn'),true);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/espace-hopital/groupe-store.php',
            new FormData(e.currentTarget)
        );

        STAGIA.toast(r.message);
        groupModal.hide();
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('saveGroupBtn'),false);
    }
};

function groupById(id){
    return (data.groups||[]).find(g=>Number(g.id)===Number(id));
}

function updateMemberSelection(){
    const checked=[...document.querySelectorAll('.member-check:checked')];
    $('memberSelection').textContent=`${checked.length} sélectionné(s)`;
    $('addMembersBtn').disabled=checked.length===0;
}

function openMembers(groupId){
    const g=groupById(groupId);
    if(!g)return;

    currentGroupId=groupId;
    $('membersTitle').textContent=g.nom;
    $('membersMeta').textContent=
        `${g.student_count} membre(s)`+
        (g.capacite?` / capacité ${g.capacite}`:'');

    $('memberRows').innerHTML=(data.students||[]).length
        ?data.students.map(x=>{
            const name=[x.prenom,x.nom,x.postnom].filter(Boolean).join(' ');
            const own=Number(x.group_id)===groupId;
            const available=!x.group_id;

            return `<tr>
                <td>
                    ${available
                        ?`<input class="form-check-input member-check" type="checkbox" value="${x.academic_enrollment_id}">`
                        :own
                            ?'<i class="bi bi-check-circle-fill text-success"></i>'
                            :''}
                </td>
                <td>
                    <strong>${esc(name)}</strong>
                    <small class="d-block text-muted">${esc(x.stagia_code||'')}</small>
                </td>
                <td>${esc(x.assignment_unit_name||'—')}</td>
                <td>
                    ${own
                        ?'<span class="badge bg-success-subtle text-success">Dans ce groupe</span>'
                        :x.group_id
                            ?`<span class="badge bg-secondary-subtle text-secondary">${esc(x.group_name)}</span>`
                            :'<span class="badge bg-warning-subtle text-warning">Disponible</span>'}
                </td>
            </tr>`;
        }).join('')
        :'<tr><td colspan="4" class="text-center py-4 text-muted">Aucun stagiaire admis et affecté.</td></tr>';

    document.querySelectorAll('.member-check').forEach(cb=>cb.onchange=updateMemberSelection);
    updateMemberSelection();
    membersModal.show();
}

$('selectAllUngrouped').onclick=()=>{
    document.querySelectorAll('.member-check').forEach(cb=>cb.checked=true);
    updateMemberSelection();
};

$('addMembersBtn').onclick=async()=>{
    const ids=[...document.querySelectorAll('.member-check:checked')].map(cb=>cb.value);
    if(!ids.length)return;

    const fd=new FormData();
    fd.append('csrf','<?= $_SESSION['csrf'] ?>');
    fd.append('group_id',currentGroupId);
    ids.forEach(id=>fd.append('academic_enrollment_ids[]',id));

    try{
        STAGIA.loading($('addMembersBtn'),true);

        const r=await STAGIA.post(
            BASE_URL+'/actions/espace-hopital/groupe-students-save.php',
            fd
        );

        STAGIA.toast(r.message);
        membersModal.hide();
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('addMembersBtn'),false);
    }
};

function openRotation(groupId){
    const g=groupById(groupId);
    if(!g)return;

    $('rotationForm').reset();
    $('rotationGroupId').value=groupId;
    $('rotationGroupName').textContent=g.nom;

    const maxSeq=Math.max(0,...(g.rotations||[]).map(r=>Number(r.sequence_no)||0));
    $('rotationSequence').value=maxSeq+1;

    $('rotationUnit').innerHTML='<option value="">Sélectionner...</option>'+
        (data.units||[]).map(u=>`<option value="${u.id}">
            ${u.parent_id?'↳ ':''}${esc(u.nom)} · ${esc(u.type)}
        </option>`).join('');

    rotationModal.show();
}

$('rotationForm').onsubmit=async e=>{
    e.preventDefault();
    STAGIA.loading($('saveRotationBtn'),true);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/espace-hopital/groupe-rotation-store.php',
            new FormData(e.currentTarget)
        );

        STAGIA.toast(r.message);
        rotationModal.hide();
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('saveRotationBtn'),false);
    }
};

async function publishPlan(groupId){
    const g=groupById(groupId);
    if(!g)return;

    if(!STAGIA.confirm(
        `Publier le calendrier de « ${g.nom} » pour ${g.student_count} stagiaire(s) ?`
    ))return;

    const fd=new FormData();
    fd.append('csrf','<?= $_SESSION['csrf'] ?>');
    fd.append('group_id',groupId);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/espace-hopital/groupe-rotation-publish.php',
            fd
        );

        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

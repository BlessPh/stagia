<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

$role=$_SESSION['role_code']??'';

if($role==='CHEF_SERVICE')requireRole(['CHEF_SERVICE']);
else requirePermission($pdo,'group.hosting.view');

if(!contextHostEnabled()){
    http_response_code(403);
    exit("Cet établissement n'est pas une structure d'accueil.");
}

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle=$role==='CHEF_SERVICE'?'Rotations du service':'Groupes & rotations';
$activePage='hospital-rotations';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1><?= $role==='CHEF_SERVICE'?'Rotations du service':'Groupes & rotations' ?></h1>
       <p><?= $role==='CHEF_SERVICE'
    ?'Organisez les stagiaires de votre service et choisissez les encadreurs / maîtres de stage.'
    :'Organisez les stagiaires affectés en groupes, définissez leur parcours entre services puis publiez les rotations.'
?></p>
    </div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-lightning-charge text-warning me-1"></i>
    <strong>Mode simple :</strong>
    créez un groupe, ajoutez les stagiaires, définissez les rotations puis publiez.
    STAGIA génère ensuite les rotations individuelles automatiquement.
</div>

<div class="stagia-list-card p-3 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h6 class="mb-1"><i class="bi bi-lightning-charge me-1"></i>Actions rapides</h6>
            <small class="text-muted">Les raccourcis utilisent le même moteur existant, sans modifier les règles métier.</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button id="quickNewGroupBtn" class="btn btn-sm btn-primary-stagia d-none">
                <i class="bi bi-plus-lg me-1"></i>Créer un groupe
            </button>
            <button id="quickPublishReadyBtn" class="btn btn-sm btn-outline-success d-none">
                <i class="bi bi-send-check me-1"></i>Publier les groupes prêts
            </button>
        </div>
    </div>
    <div class="row g-2 mt-2">
        <div class="col-md-3"><div class="small border rounded p-2"><strong>1.</strong> Groupe</div></div>
        <div class="col-md-3"><div class="small border rounded p-2"><strong>2.</strong> Stagiaires</div></div>
        <div class="col-md-3"><div class="small border rounded p-2"><strong>3.</strong> Rotations</div></div>
        <div class="col-md-3"><div class="small border rounded p-2"><strong>4.</strong> Publication</div></div>
    </div>
</div>

<div class="stagia-list-card p-3 mb-4">
<div class="row g-3">
    <div class="col-lg-6">
        <label class="form-label">Campagne *</label>
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
        <label class="form-label">Séquence</label>
        <input type="number" id="rotationSequence" class="form-control" readonly>
        <small class="text-muted">Calculée automatiquement.</small>
    </div>
    <div class="col-md-8">
        <label class="form-label">Service / unité *</label>
        <select name="host_unit_id" id="rotationUnit" class="form-select" required></select>
    </div>
    <div class="col-md-12">
        <label class="form-label">Encadreur principal *</label>
        <select name="principal_supervisor_user_id" id="rotationSupervisor" class="form-select" required></select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Durée de la rotation (jours) *</label>
        <input type="number" min="1" max="366" name="duration_days"
               id="rotationDuration" class="form-control"
               placeholder="Ex. 30" required>
        <div class="d-flex gap-1 mt-2">
            <button type="button" class="btn btn-sm btn-light border duration-shortcut" data-days="7">7 j</button>
            <button type="button" class="btn btn-sm btn-light border duration-shortcut" data-days="14">14 j</button>
            <button type="button" class="btn btn-sm btn-light border duration-shortcut" data-days="30">30 j</button>
        </div>
        <small class="text-muted d-block mt-1">Choisissez un raccourci ou saisissez la durée.</small>
    </div>
    <div class="col-md-4">
        <label class="form-label">Début automatique</label>
        <input type="date" id="rotationStart" class="form-control" readonly>
    </div>
    <div class="col-md-4">
        <label class="form-label">Fin automatique</label>
        <input type="date" id="rotationEnd" class="form-control" readonly>
    </div>
    <div class="col-md-12">
        <div id="rotationPeriodInfo" class="alert alert-light border py-2 mb-0 small"></div>
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

let data={contexts:[],students:[],groups:[],units:[],supervisors:[],permissions:{}};
let currentGroupId=0;
let currentMembersReadonly=false;

const shortcutParams=new URLSearchParams(window.location.search);
const shortcutCampaignCode=(shortcutParams.get('campaign_code')||'').trim();
const shortcutPromotion=(shortcutParams.get('promotion')||'').trim();
let shortcutApplied=false;

function draftGroups(){
    return (data.groups||[]).filter(g=>g.statut==='BROUILLON');
}

function readyGroups(){
    return draftGroups().filter(g=>(g.rotations||[]).length>0 && Number(g.student_count)>0);
}

function ungroupedStudents(){
    return (data.students||[]).filter(x=>!x.group_id);
}

function nextGroupName(){
    const used=new Set((data.groups||[]).map(g=>String(g.nom||'').trim().toUpperCase()));
    const letters='ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    for(const l of letters){
        const name='Groupe '+l;
        if(!used.has(name.toUpperCase()))return name;
    }
    return 'Groupe '+((data.groups||[]).length+1);
}

function openNewGroup(){
    if(!data.permissions.group_manage || !data.selected.campaign_id || !data.selected.promotion_id)return;

    const ctx=data.contexts.find(x=>
        Number(x.campaign_id)===Number(data.selected.campaign_id) &&
        Number(x.promotion_id)===Number(data.selected.promotion_id)
    );

    $('groupForm').reset();
    $('groupCampaign').value=data.selected.campaign_id;
    $('groupPromotion').value=data.selected.promotion_id;
    $('groupForm').elements.nom.value=nextGroupName();

    const available=ungroupedStudents().length;
    if(available>0)$('groupForm').elements.capacite.placeholder=`Ex. ${available}`;

    $('groupContext').textContent=ctx
        ?`${ctx.campaign_code} · ${ctx.promotion_name}${ctx.level_code?' · '+ctx.level_code:''}`
        :'';

    groupModal.show();
}

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

        if(!shortcutApplied && (shortcutCampaignCode||shortcutPromotion)){
            const match=(data.contexts||[]).find(x=>{
                const campaignOk=
                    !shortcutCampaignCode ||
                    String(x.campaign_code||'')===shortcutCampaignCode;

                const promotionOk=
                    !shortcutPromotion ||
                    String(x.promotion_name||'').trim()===shortcutPromotion;

                return campaignOk&&promotionOk;
            });

            if(match){
                data.selected=data.selected||{};
                data.selected.campaign_id=match.campaign_id;
                data.selected.promotion_id=match.promotion_id;
            }

            shortcutApplied=true;
        }

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
        :'<option value="">Aucun placement confirmé</option>';

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

    const canCreate=!!data.permissions.group_manage && !!data.selected.campaign_id && !!data.selected.promotion_id;
    $('newGroupBtn').classList.toggle('d-none',!canCreate);
    $('quickNewGroupBtn').classList.toggle('d-none',!canCreate);
    $('quickPublishReadyBtn').classList.toggle('d-none',!data.permissions.rotation_manage || !readyGroups().length);

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
                            · ${g.statut==='BROUILLON'?'Brouillon':g.statut==='PUBLIE'?'Publié':esc(g.statut)}
                        </small>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        ${g.statut==='BROUILLON'
                            ?`${data.permissions.group_manage
                                ?`<button class="btn btn-sm btn-outline-primary members" data-id="${g.id}">
                                    <i class="bi bi-people me-1"></i>Gérer les étudiants
                                  </button>`
                                :''}

                              ${data.permissions.group_manage && ungroupedStudents().length
                                ?`<button class="btn btn-sm btn-outline-secondary quick-fill" data-id="${g.id}">
                                    <i class="bi bi-person-plus me-1"></i>Remplir
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
                                :''}`
                            :`<button class="btn btn-sm btn-light border members" data-id="${g.id}" data-readonly="1">
                                <i class="bi bi-eye me-1"></i>Voir les étudiants
                              </button>
                              <span class="badge bg-success-subtle text-success align-self-center px-3 py-2">
                                <i class="bi bi-lock-fill me-1"></i>Plan publié
                              </span>`}
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
                            <td>
                                ${esc(r.objectifs||'—')}
                                ${r.supervisor_name?`<small class="d-block text-muted mt-1"><i class="bi bi-person-badge me-1"></i>${esc(r.supervisor_name)}${r.supervisor_function?' · '+esc(r.supervisor_function):''}</small>`:''}
                            </td>
                            <td><span class="badge bg-light text-dark">${r.statut==='PLANIFIEE'?'Planifiée':r.statut==='ACTIVE'?'Active':r.statut==='TERMINEE'?'Terminée':r.statut==='ANNULEE'?'Annulée':esc(r.statut)}</span></td>
                        </tr>`).join('')
                        :'<tr><td colspan="5" class="text-center py-4 text-muted">Aucune rotation planifiée.</td></tr>'}
                </tbody>
                </table>
                </div>
            </div>
        `).join('')
        :'<div class="alert alert-warning">Aucun groupe pour cette campagne et cette promotion.</div>';

    document.querySelectorAll('.members').forEach(b=>b.onclick=()=>openMembers(
        Number(b.dataset.id),
        b.dataset.readonly==='1'
    ));
    document.querySelectorAll('.quick-fill').forEach(b=>b.onclick=()=>quickFillGroup(Number(b.dataset.id)));
    document.querySelectorAll('.add-rotation').forEach(b=>b.onclick=()=>openRotation(Number(b.dataset.id)));
    document.querySelectorAll('.publish').forEach(b=>b.onclick=()=>publishPlan(Number(b.dataset.id)));
}

$('campaign').onchange=()=>{
    const promos=contextPromotions($('campaign').value);
    $('promotion').innerHTML=promos.map(x=>`<option value="${x.promotion_id}">${esc(x.promotion_name)}${x.level_code?' · '+esc(x.level_code):''}</option>`).join('');
    load();
};

$('promotion').onchange=load;

$('newGroupBtn').onclick=openNewGroup;
$('quickNewGroupBtn').onclick=openNewGroup;


async function quickFillGroup(groupId){
    const g=groupById(groupId);
    if(!g || g.statut!=='BROUILLON')return;

    const available=ungroupedStudents();
    if(!available.length){
        STAGIA.toast('Aucun stagiaire disponible.','info');
        return;
    }

    const capacity=Number(g.capacite||0);
    const current=Number(g.student_count||0);
    const slots=capacity>0?Math.max(0,capacity-current):available.length;

    if(slots<=0){
        STAGIA.toast('La capacité de ce groupe est déjà atteinte.','warning');
        return;
    }

    const selected=available.slice(0,slots);

    if(!STAGIA.confirm(
        `Ajouter automatiquement ${selected.length} stagiaire(s) disponible(s) dans « ${g.nom} » ?`
    ))return;

    const fd=new FormData();
    fd.append('csrf','<?= $_SESSION['csrf'] ?>');
    fd.append('group_id',groupId);
    selected.forEach(x=>fd.append('academic_enrollment_ids[]',x.academic_enrollment_id));

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/espace-hopital/groupe-students-save.php',
            fd
        );
        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

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

        const createdId=Number(r.data?.id||0);
        if(createdId && groupById(createdId))openMembers(createdId,false);
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

function openMembers(groupId,readonly=false){
    const g=groupById(groupId);
    if(!g)return;

    currentGroupId=groupId;
    currentMembersReadonly=readonly || g.statut!=='BROUILLON';

    $('membersTitle').textContent=currentMembersReadonly
        ?`Membres — ${g.nom}`
        :g.nom;

    $('membersMeta').textContent=
        `${g.student_count} membre(s)`+
        (g.capacite?` / capacité ${g.capacite}`:'')+
        (currentMembersReadonly?' · plan publié, lecture seule':'');

    $('memberRows').innerHTML=(data.students||[]).length
        ?data.students.map(x=>{
            const name=[x.prenom,x.nom,x.postnom].filter(Boolean).join(' ');
            const own=Number(x.group_id)===groupId;
            const available=!x.group_id;

            return `<tr>
                <td>
                    ${available && !currentMembersReadonly
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

    $('selectAllUngrouped').classList.toggle('d-none',currentMembersReadonly);
    $('addMembersBtn').classList.toggle('d-none',currentMembersReadonly);

    if(currentMembersReadonly){
        $('memberSelection').textContent='Lecture seule';
        $('addMembersBtn').disabled=true;
    }else{
        updateMemberSelection();
    }

    membersModal.show();
}

$('selectAllUngrouped').onclick=()=>{
    if(currentMembersReadonly)return;
    document.querySelectorAll('.member-check').forEach(cb=>cb.checked=true);
    updateMemberSelection();
};

$('addMembersBtn').onclick=async()=>{
    if(currentMembersReadonly){
        STAGIA.toast('Le plan est publié : les membres sont verrouillés.','warning');
        return;
    }

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

function addDaysIso(iso,days){
    if(!iso)return '';
    const d=new Date(iso+'T00:00:00');
    d.setDate(d.getDate()+days);
    return d.toISOString().slice(0,10);
}

function daysInclusive(start,end){
    if(!start||!end)return 0;
    const a=new Date(start+'T00:00:00');
    const b=new Date(end+'T00:00:00');
    return Math.floor((b-a)/86400000)+1;
}

function updateRotationDatesPreview(){
    const g=groupById(currentGroupId);
    if(!g)return;

    const start=g.next_rotation_start||g.planning_start||'';
    const duration=Math.max(0,Number($('rotationDuration').value)||0);

    $('rotationStart').value=start;

    if(!start||!duration){
        $('rotationEnd').value='';
        $('rotationPeriodInfo').innerHTML=
            `Période disponible du <strong>${esc(g.planning_start||'—')}</strong> au `+
            `<strong>${esc(g.planning_end||'—')}</strong>. `+
            `Saisissez seulement la durée : STAGIA calculera les dates.`;
        return;
    }

    const end=addDaysIso(start,duration-1);
    $('rotationEnd').value=end;

    const remaining=daysInclusive(start,g.planning_end);
    const overflow=end>g.planning_end;

    $('rotationPeriodInfo').innerHTML=overflow
        ?`<span class="text-danger"><strong>Durée trop longue.</strong> `+
         `À partir du ${esc(start)}, il reste ${remaining} jour(s) jusqu’au ${esc(g.planning_end)}.</span>`
        :`Début : <strong>${esc(start)}</strong> · `+
         `Fin calculée : <strong>${esc(end)}</strong> · `+
         `Période maximale du groupe : ${esc(g.planning_start)} → ${esc(g.planning_end)}.`;
}

function normType(t){
    return String(t||'').toUpperCase()
        .replaceAll('É','E')
        .replaceAll('È','E')
        .replaceAll('Ê','E')
        .replaceAll('Ë','E');
}

function serviceSelectable(u){
    return ['SERVICE','UNITE'].includes(normType(u.type));
}

function supervisorsForUnit(unitId){
    const id=Number(unitId||0);
    if(!id)return [];

    return (data.supervisors||[]).filter(s=>{
        if(s.scope_all)return true;
        const ids=(s.unit_ids||[]).map(Number);
        return ids.includes(id);
    });
}

function fillSupervisors(unitId){
    const list=supervisorsForUnit(unitId);

    $('rotationSupervisor').innerHTML=list.length
        ?'<option value="">Sélectionner...</option>'+list.map(s=>`<option value="${s.id}">
            ${esc(s.full_name||'Utilisateur')} · ${esc(s.fonction||'Encadreur / Maître de stage')}
        </option>`).join('')
        :'<option value="">Aucun encadreur pour ce service</option>';

    if(list.length===1)$('rotationSupervisor').value=String(list[0].id);
}

function openRotation(groupId){
    const g=groupById(groupId);
    if(!g)return;

    $('rotationForm').reset();
    $('rotationGroupId').value=groupId;
    $('rotationGroupName').textContent=g.nom;

    $('rotationSequence').value=g.next_sequence||1;
    $('rotationDuration').value='';
    $('rotationStart').value=g.next_rotation_start||g.planning_start||'';
    $('rotationEnd').value='';

    const selectableUnits=(data.units||[]).filter(serviceSelectable);

    $('rotationUnit').innerHTML='<option value="">Sélectionner un service...</option>'+
        (data.units||[]).map(u=>{
            const ok=serviceSelectable(u);
            const label=(u.parent_id?'↳ ':'')+esc(u.nom)+' · '+esc(u.type);

            return `<option value="${ok?u.id:''}" ${ok?'':'disabled'}>
                ${label}${ok?'':' — non sélectionnable'}
            </option>`;
        }).join('');

    if(selectableUnits.length===1)$('rotationUnit').value=String(selectableUnits[0].id);

    fillSupervisors($('rotationUnit').value);

    $('rotationUnit').onchange=()=>{
        $('rotationSupervisor').value='';
        fillSupervisors($('rotationUnit').value);
    };

    updateRotationDatesPreview();
    rotationModal.show();
}

$('rotationDuration').oninput=updateRotationDatesPreview;
document.querySelectorAll('.duration-shortcut').forEach(b=>b.onclick=()=>{
    $('rotationDuration').value=b.dataset.days;
    updateRotationDatesPreview();
});

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


$('quickPublishReadyBtn').onclick=async()=>{
    const groups=readyGroups();
    if(!groups.length)return;

    if(!STAGIA.confirm(
        `Publier ${groups.length} groupe(s) prêt(s) ? Les membres et calendriers seront verrouillés.`
    ))return;

    let done=0;
    for(const g of groups){
        const fd=new FormData();
        fd.append('csrf','<?= $_SESSION['csrf'] ?>');
        fd.append('group_id',g.id);

        try{
            await STAGIA.post(
                BASE_URL+'/actions/espace-hopital/groupe-rotation-publish.php',
                fd
            );
            done++;
        }catch(e){
            STAGIA.toast(`« ${g.nom} » : ${e.message}`,'danger');
            break;
        }
    }

    if(done)STAGIA.toast(`${done} groupe(s) publié(s).`);
    await load();
};

load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'placement.university.view');
if(!contextAcademicEnabled()){http_response_code(403);exit("Cet espace n'est pas un établissement de formation.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Affectations / Placements';
$activePage='stages-d4-placements';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Choix étudiants & affectations</h1><p>Voir les choix des étudiants, contrôler les effectifs par structure puis confirmer les affectations.</p></div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-info-circle text-primary me-1"></i>
    <strong>L’étudiant choisit la structure d’accueil.</strong> L’université confirme l’affectation institutionnelle. L’affectation interne reste du ressort de la structure.
</div>

<div class="stagia-list-card p-3 mb-4">
    <label class="form-label">Session</label>
    <select id="campaign" class="form-select"></select>
</div>

<h5 class="mb-3">Répartition par structure d’accueil</h5>
<div id="hospitalSummary" class="row g-3 mb-4"></div>

<div class="stagia-list-card">
<div class="p-3 border-bottom"><h5 class="mb-1">Placements des étudiants</h5><small class="text-muted">Choix en attente, placements confirmés et placements annulés.</small></div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>ÉTUDIANT</th><th>STRUCTURE CHOISIE</th><th>RÉSERVATION</th><th>AFFECTATION</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="rows"><tr><td colspan="5" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="hospitalStudentsModal" tabindex="-1">
<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
<div class="modal-header">
    <div><h5 class="modal-title" id="hospitalModalTitle">Étudiants par structure</h5><small class="text-muted" id="hospitalModalMeta"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<div class="row g-3 align-items-end mb-3">
    <div class="col-lg-6">
        <label class="form-label mb-1">Promotion *</label>
        <select id="promotionFilter" class="form-select"><option value="">Sélectionner une promotion...</option></select>
        <small class="text-muted">Choisissez la promotion à afficher et à affecter.</small>
    </div>
    <div class="col-lg-6"><div class="d-flex flex-wrap justify-content-lg-end gap-2">
        <button type="button" class="btn btn-sm btn-light border" id="selectAllReady"><i class="bi bi-check2-square me-1"></i>Sélectionner tous les prêts</button>
        <button type="button" class="btn btn-sm btn-light border" id="clearSelection">Désélectionner</button>
    </div></div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-2 bg-light rounded border">
    <div id="promotionStats" class="small text-muted">Sélectionnez une promotion.</div>
    <div class="small text-muted" id="selectionInfo">0 étudiant sélectionné</div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th style="width:45px"></th><th>ÉTUDIANT</th><th>RÉSERVATION</th><th>AFFECTATION</th><th>ACTION</th></tr></thead>
<tbody id="hospitalStudentsRows"></tbody>
</table>
</div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Fermer</button>
    <button type="button" class="btn btn-outline-primary" id="assignPromotionBtn" disabled><i class="bi bi-mortarboard me-1"></i>Affecter toute la promotion</button>
    <button type="button" class="btn btn-primary-stagia" id="bulkAssignBtn" disabled><i class="bi bi-people-fill me-1"></i>Affecter la sélection</button>
</div>
</div></div>
</div>

<div class="modal fade" id="cancelPlacementModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<div class="modal-header">
    <h5 class="modal-title">Annuler le placement</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <p id="cancelPlacementContext" class="mb-3"></p>
    <label for="cancelPlacementReason" class="form-label">Motif de l'annulation *</label>
    <textarea id="cancelPlacementReason" class="form-control" rows="4" maxlength="1000" required></textarea>
    <div class="form-text">L'étudiant recevra ce motif dans sa notification.</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Fermer</button>
    <button type="button" class="btn btn-danger" id="cancelPlacementSubmit">Confirmer l'annulation</button>
</div>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('hospitalStudentsModal'));
const cancelModal=new bootstrap.Modal($('cancelPlacementModal'));
let data={campaigns:[],items:[],by_hospital:[],permissions:{}},currentHospitalId=0,currentPromotionId='',placementToCancel=null;

function reservationBadge(s){
    const m={CONFIRMEE:['Place réservée','bg-success-subtle text-success'],EN_ATTENTE_PAIEMENT:['Paiement requis','bg-warning-subtle text-warning'],RESERVEE_TEMPORAIREMENT:['Temporaire','bg-info-subtle text-info']}[s]||[s,'bg-light text-dark'];
    return `<span class="badge ${m[1]}">${esc(m[0])}</span>`;
}
const hospitalGroup=id=>(data.by_hospital||[]).find(h=>Number(h.host_etablissement_id)===Number(id));
const hospitalStudents=id=>(data.items||[]).filter(x=>Number(x.host_etablissement_id)===Number(id));
const ready=s=>s.filter(x=>x.reservation_status==='CONFIRMEE'&&x.placement_status!=='CONFIRME');
const placed=s=>s.filter(x=>x.placement_status==='CONFIRME');
const fullName=x=>[x.prenom,x.nom,x.postnom].filter(Boolean).join(' ');

function groups(students){
    const g={};
    students.forEach(x=>{
        const k=String(x.promotion_id||`${x.promotion_name||'PROMOTION'}-${x.level_code||''}`);
        g[k]??={key:k,id:x.promotion_id||null,code:x.promotion_code||'',name:x.promotion_name||'Promotion non renseignée',level:x.level_code||'',students:[]};
        g[k].students.push(x);
    });
    return Object.values(g).sort((a,b)=>`${a.name} ${a.level}`.localeCompare(`${b.name} ${b.level}`,'fr'));
}

async function load(){
    const q=$('campaign').value?`?campaign_id=${encodeURIComponent($('campaign').value)}`:'';
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/d4-placements-list.php'+q);data=r.data;
        $('campaign').innerHTML=(data.campaigns||[]).length?data.campaigns.map(c=>`<option value="${c.id}">${esc(c.code)} — ${esc(c.titre)} · ${esc(c.statut)}</option>`).join(''):'<option value="">Aucune session avec structure d’accueil</option>';
        $('campaign').value=String(data.selected_campaign_id||'');

        $('hospitalSummary').innerHTML=(data.by_hospital||[]).length?data.by_hospital.map(h=>`<div class="col-md-4"><div class="stagia-list-card p-3 h-100">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div><strong>${esc(h.host_name)}</strong><small class="d-block text-muted">${esc(h.host_code||'')}</small></div>
                ${Number(h.selected_count)>0?`<button class="btn btn-sm btn-outline-primary open-hospital" data-host-id="${h.host_etablissement_id}"><i class="bi bi-eye me-1"></i>Ouvrir</button>`:'<span class="badge bg-success-subtle text-success">Aucun en attente</span>'}
            </div>
            <div class="mt-3 d-flex justify-content-between"><span>Choix en attente</span><strong>${h.selected_count}</strong></div>
            <div class="d-flex justify-content-between"><span>Prêts à affecter</span><strong>${h.ready_count}</strong></div>
            <div class="d-flex justify-content-between"><span>Affectations confirmées</span><strong>${h.placed_count}</strong></div>
            <div class="d-flex justify-content-between"><span>Allocation université</span><strong>${h.allocation}</strong></div>
            <div class="d-flex justify-content-between"><span>Places libres</span><strong>${h.remaining_allocation}</strong></div>
        </div></div>`).join(''):'<div class="col-12"><div class="alert alert-warning mb-0">Aucun étudiant n’a encore choisi une structure pour cette session.</div></div>';

        document.querySelectorAll('.open-hospital').forEach(b=>b.onclick=()=>openHospital(Number(b.dataset.hostId)));

        $('rows').innerHTML=(data.items||[]).length?data.items.map(x=>{
            const name=fullName(x),done=x.placement_status==='CONFIRME',cancelled=x.placement_status==='ANNULE';
            return `<tr>
                <td><strong>${esc(name)}</strong><small class="d-block text-muted">${esc(x.stagia_code||'')}</small><small class="d-block text-muted">${esc(x.promotion_name||'')} ${x.level_code?'· '+esc(x.level_code):''}</small></td>
                <td><strong>${esc(x.host_name)}</strong><small class="d-block text-muted">${esc(x.host_code||'')}</small></td>
                <td>${reservationBadge(x.reservation_status)}</td>
                <td>${done?'<span class="badge bg-success-subtle text-success">CONFIRMÉE</span>':cancelled?'<span class="badge bg-danger-subtle text-danger">ANNULÉE</span>':'<span class="badge bg-warning-subtle text-warning">EN ATTENTE</span>'}</td>
                <td class="text-end">${data.permissions.manage&&!done&&x.reservation_status==='CONFIRMEE'
                    ?`<button class="btn btn-sm btn-primary-stagia confirm-placement" data-id="${x.reservation_id}" data-name="${esc(name)}" data-host="${esc(x.host_name)}"><i class="bi bi-send-check me-1"></i>Affecter</button>`
                    :data.permissions.manage&&done?`<button class="btn btn-sm btn-outline-danger cancel-placement" data-placement-id="${x.placement_id}" data-name="${esc(name)}" data-host="${esc(x.host_name)}"><i class="bi bi-x-circle me-1"></i>Annuler</button>`:done?'<span class="text-success small"><i class="bi bi-check-circle me-1"></i>Transmis</span>':'—'}</td>
            </tr>`;
        }).join(''):'<tr><td colspan="5" class="text-center py-5 text-muted">Aucun placement à afficher pour cette session.</td></tr>';

        document.querySelectorAll('.confirm-placement').forEach(b=>b.onclick=()=>confirmPlacement(b));
        document.querySelectorAll('.cancel-placement').forEach(b=>b.onclick=()=>openCancelPlacement(b));
    }catch(e){STAGIA.toast(e.message,'danger');}
}

function updateSelectionInfo(){
    const n=document.querySelectorAll('.bulk-student:checked').length;
    $('selectionInfo').textContent=`${n} étudiant(s) sélectionné(s)`;$('bulkAssignBtn').disabled=n===0;
}

function fillPromotionSelector(preserve=true){
    const gs=groups(hospitalStudents(currentHospitalId)),old=preserve?String(currentPromotionId||''):'';
    $('promotionFilter').innerHTML='<option value="">Sélectionner une promotion...</option>'+gs.map(g=>`<option value="${esc(g.key)}">${esc(g.name)}${g.level?' · '+esc(g.level):''} — ${g.students.length} étudiant(s), ${ready(g.students).length} prêt(s), ${placed(g.students).length} affecté(s)</option>`).join('');
    currentPromotionId=gs.some(g=>String(g.key)===old)?old:(gs.length===1?String(gs[0].key):'');
    $('promotionFilter').value=currentPromotionId;
}

function currentGroup(){
    return groups(hospitalStudents(currentHospitalId)).find(g=>String(g.key)===String(currentPromotionId))||null;
}

function renderSelectedPromotion(){
    const g=currentGroup();
    if(!g){
        $('promotionStats').textContent='Sélectionnez une promotion pour afficher ses étudiants.';
        $('hospitalStudentsRows').innerHTML='<tr><td colspan="5" class="text-center py-5 text-muted">Choisissez une promotion.</td></tr>';
        ['selectAllReady','clearSelection','bulkAssignBtn','assignPromotionBtn'].forEach(id=>$(id).disabled=true);
        $('selectionInfo').textContent='0 étudiant sélectionné';return;
    }

    const r=ready(g.students),p=placed(g.students);
    $('promotionStats').innerHTML=`<strong>${esc(g.name)}${g.level?' · '+esc(g.level):''}</strong> · ${g.students.length} étudiant(s) · ${r.length} prêt(s) · ${p.length} affecté(s)`;
    $('hospitalStudentsRows').innerHTML=g.students.map(x=>{
        const name=fullName(x),done=x.placement_status==='CONFIRME',cancelled=x.placement_status==='ANNULE',ok=x.reservation_status==='CONFIRMEE'&&!done;
        return `<tr>
            <td>${ok?`<input class="form-check-input bulk-student" type="checkbox" value="${x.reservation_id}">`:''}</td>
            <td><strong>${esc(name)}</strong><small class="d-block text-muted">${esc(x.stagia_code||'')}</small></td>
            <td>${reservationBadge(x.reservation_status)}</td>
            <td>${done?'<span class="badge bg-success-subtle text-success">CONFIRMÉE</span>':cancelled?'<span class="badge bg-danger-subtle text-danger">ANNULÉE</span>':'<span class="badge bg-warning-subtle text-warning">EN ATTENTE</span>'}</td>
            <td>${data.permissions.manage&&ok?`<button class="btn btn-sm btn-outline-primary confirm-placement" data-id="${x.reservation_id}" data-name="${esc(name)}" data-host="${esc(x.host_name)}">Affecter</button>`:data.permissions.manage&&done?`<button class="btn btn-sm btn-outline-danger cancel-placement" data-placement-id="${x.placement_id}" data-name="${esc(name)}" data-host="${esc(x.host_name)}">Annuler</button>`:done?'<span class="text-success small"><i class="bi bi-check-circle me-1"></i>Transmis</span>':'—'}</td>
        </tr>`;
    }).join('');

    document.querySelectorAll('.bulk-student').forEach(cb=>cb.onchange=updateSelectionInfo);
    document.querySelectorAll('#hospitalStudentsRows .confirm-placement').forEach(b=>b.onclick=async()=>{await confirmPlacement(b);await load();hospitalStudents(currentHospitalId).length?renderHospitalModal(true):modal.hide();});
    document.querySelectorAll('#hospitalStudentsRows .cancel-placement').forEach(b=>b.onclick=()=>openCancelPlacement(b));
    $('selectAllReady').disabled=r.length===0;$('clearSelection').disabled=r.length===0;$('assignPromotionBtn').disabled=r.length===0;updateSelectionInfo();
}

function renderHospitalModal(preserve=true){
    const h=hospitalGroup(currentHospitalId);if(!h)return;
    const gs=groups(hospitalStudents(currentHospitalId));
    $('hospitalModalTitle').textContent=h.host_name;
    $('hospitalModalMeta').textContent=`${h.selected_count} choix · ${h.ready_count} prêts · ${h.placed_count} confirmés · allocation ${h.allocation} · ${gs.length} promotion(s)`;
    fillPromotionSelector(preserve);renderSelectedPromotion();
}

function openHospital(id){currentHospitalId=id;currentPromotionId='';renderHospitalModal(false);modal.show();}

async function bulkAssignReservationIds(ids){
    const h=hospitalGroup(currentHospitalId);if(!ids.length||!h)return;
    if(!STAGIA.confirm(`Affecter ${ids.length} étudiant(s) vers « ${h.host_name} » ?`))return;

    const fd=new FormData();
    fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('campaign_id',$('campaign').value);fd.append('host_etablissement_id',currentHospitalId);
    ids.forEach(id=>fd.append('reservation_ids[]',id));

    try{
        STAGIA.loading($('bulkAssignBtn'),true);
        const r=await STAGIA.post(BASE_URL+'/actions/stages/d4-placement-bulk-confirm.php',fd);
        STAGIA.toast(r.message);await load();
        hospitalStudents(currentHospitalId).length?renderHospitalModal(true):modal.hide();
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('bulkAssignBtn'),false);}
}

async function confirmPlacement(btn){
    if(!STAGIA.confirm(`Confirmer l’affectation de ${btn.dataset.name} vers « ${btn.dataset.host} » ?`))return;
    const fd=new FormData();fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('reservation_id',btn.dataset.id);
    try{STAGIA.loading(btn,true);const r=await STAGIA.post(BASE_URL+'/actions/stages/d4-placement-confirm.php',fd);STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading(btn,false);}
}

function openCancelPlacement(btn){
    placementToCancel={id:btn.dataset.placementId,name:btn.dataset.name,host:btn.dataset.host};
    $('cancelPlacementContext').textContent=`Annuler le placement de ${placementToCancel.name} à « ${placementToCancel.host} » ?`;
    $('cancelPlacementReason').value='';
    cancelModal.show();
}

$('cancelPlacementSubmit').onclick=async()=>{
    if(!placementToCancel)return;
    const reason=$('cancelPlacementReason').value.trim();
    if(reason.length<5)return STAGIA.toast("Précisez un motif d'au moins 5 caractères.",'warning');
    const btn=$('cancelPlacementSubmit'),fd=new FormData();
    fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('placement_id',placementToCancel.id);fd.append('reason',reason);
    try{
        STAGIA.loading(btn,true);
        const r=await STAGIA.post(BASE_URL+'/actions/stages/d4-placement-cancel.php',fd);
        cancelModal.hide();placementToCancel=null;STAGIA.toast(r.message);await load();
        if(currentHospitalId&&hospitalStudents(currentHospitalId).length)renderHospitalModal(true);
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading(btn,false);}
};

$('promotionFilter').onchange=()=>{currentPromotionId=$('promotionFilter').value;renderSelectedPromotion();};
$('selectAllReady').onclick=()=>{document.querySelectorAll('#hospitalStudentsRows .bulk-student').forEach(cb=>cb.checked=true);updateSelectionInfo();};
$('clearSelection').onclick=()=>{document.querySelectorAll('.bulk-student').forEach(cb=>cb.checked=false);updateSelectionInfo();};
$('assignPromotionBtn').onclick=async()=>{const g=currentGroup();if(!g)return STAGIA.toast('Sélectionnez d’abord une promotion.','warning');const ids=ready(g.students).map(x=>x.reservation_id);ids.length?await bulkAssignReservationIds(ids):STAGIA.toast('Aucun étudiant prêt à affecter.','warning');};
$('bulkAssignBtn').onclick=async()=>{const ids=[...document.querySelectorAll('#hospitalStudentsRows .bulk-student:checked')].map(cb=>cb.value);await bulkAssignReservationIds(ids);};
$('campaign').onchange=load;load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

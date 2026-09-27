<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'admission.hosting.view');
if(!contextHostEnabled()){http_response_code(403);exit("Cet établissement n'est pas une structure d'accueil.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Stagiaires attendus';
$activePage='hospital-stagiaires';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Stagiaires attendus</h1><p>Admettez les stagiaires puis envoyez-les vers leurs coordinations/départements.</p></div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-lightning-charge text-warning me-1"></i>
    <strong>Flux :</strong> administration → admission → coordination/département → paiement validé → service.
</div>

<div class="row g-3 mb-4">
    <div class="col-md"><div class="stagia-list-card p-3"><small class="text-muted">TOTAL</small><h3 id="kTotal">0</h3></div></div>
    <div class="col-md"><div class="stagia-list-card p-3"><small class="text-muted">ATTENDUS</small><h3 id="kExpected">0</h3></div></div>
    <div class="col-md"><div class="stagia-list-card p-3"><small class="text-muted">ADMIS</small><h3 id="kAdmitted">0</h3></div></div>
    <div class="col-md"><div class="stagia-list-card p-3"><small class="text-muted">EN COORDINATION</small><h3 id="kCoord">0</h3></div></div>
    <div class="col-md"><div class="stagia-list-card p-3"><small class="text-muted">AFFECTÉS</small><h3 id="kAssigned">0</h3></div></div>
</div>

<div class="stagia-list-card p-3 mb-3">
<div class="row g-2 align-items-end">
    <div class="col-lg-3"><label class="form-label">Session</label><select id="campaignFilter" class="form-select"><option value="">Toutes</option></select></div>
    <div class="col-lg-2"><label class="form-label">Université</label><select id="universityFilter" class="form-select"><option value="">Toutes</option></select></div>
    <div class="col-lg-2"><label class="form-label">Promotion</label><select id="promotionFilter" class="form-select"><option value="">Toutes</option></select></div>
    <div class="col-lg-2">
        <label class="form-label">Statut</label>
        <select id="statusFilter" class="form-select">
            <option value="ALL">Tous</option>
            <option value="ATTENDU">Attendus</option>
            <option value="ADMIS">Admis / à envoyer</option>
        </select>
    </div>
    <div class="col-lg-3"><label class="form-label">Recherche</label><input id="searchFilter" class="form-control" placeholder="Nom, code STAGIA, session..."></div>
</div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap gap-2">
        <button id="selectPageBtn" class="btn btn-sm btn-light border"><i class="bi bi-check2-square me-1"></i>Sélectionner la page</button>
        <button id="clearSelectionBtn" class="btn btn-sm btn-light border d-none">Effacer la sélection</button>

        <button id="bulkAdmitBtn" class="btn btn-sm btn-outline-success" disabled>
            <i class="bi bi-person-check me-1"></i>Admettre
            <span class="badge bg-light text-dark ms-1" id="admitSelectedCount">0</span>
        </button>

        <button id="bulkCoordBtn" class="btn btn-sm btn-primary-stagia" disabled>
            <i class="bi bi-diagram-3 me-1"></i>Envoyer à une coordination
            <span class="badge bg-light text-dark ms-1" id="coordSelectedCount">0</span>
        </button>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <button id="admitAllFilteredBtn" class="btn btn-sm btn-outline-success d-none"><i class="bi bi-lightning-charge me-1"></i>Admettre tous les résultats filtrés</button>
        <span class="small text-muted" id="resultInfo"></span>
    </div>
</div>

<div id="coordWarning" class="alert alert-warning d-none">
    Aucune coordination/département actif n’est configuré. Créez d’abord les coordinations dans les unités d’accueil.
</div>

<div class="stagia-list-card">
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr>
    <th style="width:42px"><input type="checkbox" class="form-check-input" id="selectAllCheck"></th>
    <th>STAGIAIRE</th>
    <th>UNIVERSITÉ / SESSION</th>
    <th>PÉRIODE</th>
    <th>ADMISSION</th>
    <th>COORDINATION / SERVICE</th>
    <th class="text-end">ACTION</th>
</tr></thead>
<tbody id="rows"><tr><td colspan="7" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
<div class="d-flex justify-content-between align-items-center p-3 border-top">
    <small class="text-muted" id="pageInfo"></small>
    <div class="btn-group">
        <button id="prevBtn" class="btn btn-sm btn-light border">Précédent</button>
        <button id="nextBtn" class="btn btn-sm btn-light border">Suivant</button>
    </div>
</div>
</div>
</main>

<div class="modal fade" id="admitModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="admitForm">
<div class="modal-header">
    <div><h5 class="modal-title">Enregistrer l’arrivée</h5><small class="text-muted" id="admitName"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
    <input type="hidden" name="admission_id" id="admitAdmission">
    <label class="form-label">Observation d’admission</label>
    <textarea name="observation" class="form-control" rows="3" placeholder="Ex. Arrivé avec les pièces requises."></textarea>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="admitBtn">Confirmer l’admission</button>
</div>
</form>
</div></div>
</div>

<div class="modal fade" id="coordModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="coordForm">
<div class="modal-header">
    <div><h5 class="modal-title">Envoyer à une coordination</h5><small class="text-muted" id="coordName"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
    <input type="hidden" name="admission_id" id="coordAdmission">
    <label class="form-label">Coordination / département *</label>
    <select name="coordination_unit_id" id="coordSelect" class="form-select" required></select>
    <small class="text-muted">La coordination affectera ensuite le stagiaire dans ses services après contrôle des frais.</small>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="coordBtn">Envoyer</button>
</div>
</form>
</div></div>
</div>

<div class="modal fade" id="bulkCoordModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="bulkCoordForm">
<div class="modal-header">
    <div><h5 class="modal-title">Envoi groupé vers une coordination</h5><small class="text-muted" id="bulkCoordInfo"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
    <label class="form-label">Coordination / département *</label>
    <select name="coordination_unit_id" id="bulkCoordSelect" class="form-select" required></select>
    <small class="text-muted">Tous les stagiaires sélectionnés seront envoyés vers cette même coordination.</small>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="bulkCoordSaveBtn">Envoyer la sélection</button>
</div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const admitModal=new bootstrap.Modal($('admitModal')),coordModal=new bootstrap.Modal($('coordModal')),bulkCoordModal=new bootstrap.Modal($('bulkCoordModal'));
let data={items:[],coordinations:[],filters:{campaigns:[],universities:[],promotions:[]},permissions:{},kpi:{},pagination:{page:1,pages:1,total:0,per_page:50}};
let page=1,selected=new Set(),timer=null;

function params(){
    const p=new URLSearchParams();p.set('page',page);p.set('per_page',50);
    const v={campaign_id:$('campaignFilter').value,university_id:$('universityFilter').value,promotion_id:$('promotionFilter').value,status:$('statusFilter').value,q:$('searchFilter').value.trim()};
    Object.entries(v).forEach(([k,x])=>{
        if(!x)return;
        if(k==='status'&&x==='ALL')return;
        p.set(k,x);
    });
    return p;
}

async function load(reset=false){
    if(reset)selected.clear();
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/espace-hopital/expected-interns-list.php?'+params().toString());
        data=r.data||data;
        $('kTotal').textContent=data.kpi?.total||0;$('kExpected').textContent=data.kpi?.attendus||0;
        $('kAdmitted').textContent=data.kpi?.admis||0;$('kCoord').textContent=data.kpi?.en_coordination||0;
        $('kAssigned').textContent=data.kpi?.affectes||0;
        $('coordWarning').classList.toggle('d-none',(data.coordinations||[]).length>0);
        renderFilters();renderRows();renderPagination();renderSelection();
    }catch(e){
        STAGIA.toast(e.message,'danger');
        $('rows').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;
    }
}

function fillSelect(id,items,valueKey,labelKey,emptyLabel){
    const el=$(id),cur=el.value;
    el.innerHTML=`<option value="">${emptyLabel}</option>`+items.map(x=>`<option value="${x[valueKey]}">${esc(x[labelKey])}</option>`).join('');
    if([...el.options].some(o=>o.value===cur))el.value=cur;
}
function renderFilters(){
    fillSelect('campaignFilter',data.filters?.campaigns||[],'id','label','Toutes');
    fillSelect('universityFilter',data.filters?.universities||[],'id','label','Toutes');
    fillSelect('promotionFilter',data.filters?.promotions||[],'id','label','Toutes');
}
function nameOf(x){return [x.prenom,x.nom,x.postnom].filter(Boolean).join(' ')||`Étudiant #${x.student_id||''}`;}
function itemByAdmission(id){return (data.items||[]).find(v=>Number(v.admission_id)===Number(id));}
function canSendCoord(x){return data.permissions?.send_coordination&&['ADMIS','EN_COURS'].includes(x.admission_status)&&!Number(x.coordination_unit_id)&&!Number(x.assignment_id);}
function selectedItems(){return [...selected].map(id=>itemByAdmission(id)).filter(Boolean);}
function selectedAdmitIds(){return selectedItems().filter(x=>x.admission_status==='ATTENDU').map(x=>Number(x.admission_id));}
function selectedCoordIds(){return selectedItems().filter(canSendCoord).map(x=>Number(x.admission_id));}

function renderRows(){
    const items=data.items||[];
    if(!items.length){$('rows').innerHTML='<tr><td colspan="7" class="text-center py-5 text-muted">Aucun stagiaire trouvé.</td></tr>';return;}

    $('rows').innerHTML=items.map(x=>{
        const name=nameOf(x),hasCoord=Number(x.coordination_unit_id)>0,hasAssign=Number(x.assignment_id)>0;
        const canAdmit=x.admission_status==='ATTENDU',canSend=canSendCoord(x),canCheck=canAdmit||canSend;

        return `<tr>
            <td>${canCheck?`<input type="checkbox" class="form-check-input row-check" value="${x.admission_id}" ${selected.has(Number(x.admission_id))?'checked':''}>`:''}</td>
            <td><strong>${esc(name)}</strong><small class="d-block text-muted">${esc(x.stagia_code||'')}</small><small class="d-block text-muted">${esc(x.promotion_name||'')}</small></td>
            <td><strong>${esc(x.university_name||'—')}</strong><small class="d-block text-muted">${esc(x.campaign_code||'')} — ${esc(x.campaign_title||'')}</small></td>
            <td>${esc(x.date_debut||'—')} → ${esc(x.date_fin||'—')}</td>
            <td>${admissionBadge(x.admission_status)}</td>
            <td>
                ${hasCoord?`<strong>${esc(x.coordination_name||'Coordination')}</strong><small class="d-block text-muted">Envoyé à la coordination</small>`:'—'}
                ${hasAssign?`<small class="d-block text-success">Service : ${esc(x.host_unit_name||'—')}</small>`:''}
            </td>
            <td class="text-end">
                ${x.application_id?`<a class="btn btn-sm btn-outline-secondary" target="_blank" href="${BASE_URL}/views/documents/print-stage-document.php?type=lettre_stage&application_id=${encodeURIComponent(x.application_id)}"><i class="bi bi-file-earmark-text me-1"></i>Lettre</a>`:''}
                ${data.permissions?.admit&&canAdmit?`<button class="btn btn-sm btn-outline-success admit" data-id="${x.admission_id}"><i class="bi bi-person-check me-1"></i>Admettre</button>`:''}
                ${canSend?`<button class="btn btn-sm btn-outline-primary send-coord" data-id="${x.admission_id}"><i class="bi bi-diagram-3 me-1"></i>Envoyer</button>`:''}
                ${hasCoord&&!hasAssign?'<span class="text-success small"><i class="bi bi-check-circle me-1"></i>Envoyé</span>':''}
                ${hasAssign?'<span class="text-success small"><i class="bi bi-check2-all me-1"></i>Affecté</span>':''}
            </td>
        </tr>`;
    }).join('');

    document.querySelectorAll('.row-check').forEach(cb=>cb.onchange=()=>{const id=Number(cb.value);cb.checked?selected.add(id):selected.delete(id);renderSelection();});
    document.querySelectorAll('.admit').forEach(b=>b.onclick=()=>openAdmit(Number(b.dataset.id)));
    document.querySelectorAll('.send-coord').forEach(b=>b.onclick=()=>openCoord(Number(b.dataset.id)));
}

function admissionBadge(s){
    const m={ATTENDU:['Attendu','bg-warning-subtle text-warning'],ADMIS:['Admis','bg-info-subtle text-info'],EN_COURS:['En cours','bg-success-subtle text-success'],TERMINE:['Terminé','bg-secondary-subtle text-secondary'],ANNULE:['Annulé','bg-danger-subtle text-danger']}[s]||[s||'—','bg-light text-dark'];
    return `<span class="badge ${m[1]}">${esc(m[0])}</span>`;
}

function renderPagination(){
    const p=data.pagination||{};page=Number(p.page||1);
    $('pageInfo').textContent=`Page ${page}/${p.pages||1} · ${p.total||0} résultat(s)`;
    $('resultInfo').textContent=`${p.total||0} résultat(s) filtré(s)`;
    $('prevBtn').disabled=page<=1;$('nextBtn').disabled=page>=Number(p.pages||1);
    $('admitAllFilteredBtn').classList.toggle('d-none',!data.permissions?.admit||Number(data.filtered_expected||0)<=0);
    if(Number(data.filtered_expected||0)>0)$('admitAllFilteredBtn').innerHTML=`<i class="bi bi-lightning-charge me-1"></i>Admettre tous les attendus filtrés (${Number(data.filtered_expected)})`;
}

function renderSelection(){
    const admitIds=selectedAdmitIds(),coordIds=selectedCoordIds();
    $('admitSelectedCount').textContent=admitIds.length;
    $('coordSelectedCount').textContent=coordIds.length;
    $('bulkAdmitBtn').disabled=admitIds.length===0;
    $('bulkCoordBtn').disabled=coordIds.length===0;
    $('clearSelectionBtn').classList.toggle('d-none',selected.size===0);

    const selectable=(data.items||[]).filter(x=>x.admission_status==='ATTENDU'||canSendCoord(x));
    $('selectAllCheck').checked=selectable.length>0&&selectable.every(x=>selected.has(Number(x.admission_id)));
}

$('selectPageBtn').onclick=()=>{
    (data.items||[]).filter(x=>x.admission_status==='ATTENDU'||canSendCoord(x)).forEach(x=>selected.add(Number(x.admission_id)));
    renderRows();renderSelection();
};
$('selectAllCheck').onchange=e=>{
    const checked=e.currentTarget.checked;
    (data.items||[]).filter(x=>x.admission_status==='ATTENDU'||canSendCoord(x)).forEach(x=>{
        const id=Number(x.admission_id);checked?selected.add(id):selected.delete(id);
    });
    renderRows();renderSelection();
};
$('clearSelectionBtn').onclick=()=>{selected.clear();renderRows();renderSelection();};

$('bulkAdmitBtn').onclick=async()=>{
    const ids=selectedAdmitIds();
    if(!ids.length)return;
    if(!STAGIA.confirm(`Confirmer l’arrivée de ${ids.length} stagiaire(s) sélectionné(s) ?`))return;

    const fd=new FormData();fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'])?>');fd.append('mode','selected');
    ids.forEach(id=>fd.append('admission_ids[]',id));

    try{
        const r=await STAGIA.post(BASE_URL+'/actions/espace-hopital/admission-bulk-admit.php',fd);
        STAGIA.toast(r.message);
        // On garde la sélection : les mêmes lignes passent de ATTENDU à ADMIS,
        // donc le bouton "Envoyer à une coordination" devient actif directement.
        await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
};

$('bulkCoordBtn').onclick=()=>{
    const ids=selectedCoordIds();
    if(!ids.length)return STAGIA.toast('Sélectionnez au moins un stagiaire admis non envoyé.','warning');
    if(!(data.coordinations||[]).length)return STAGIA.toast('Créez d’abord une coordination ou un département actif.','warning');

    $('bulkCoordForm').reset();
    $('bulkCoordInfo').textContent=`${ids.length} stagiaire(s) sélectionné(s)`;
    $('bulkCoordSelect').innerHTML='<option value="">Sélectionner...</option>'+data.coordinations.map(c=>`<option value="${c.id}">${esc(c.nom)} · ${esc(c.type||'Coordination')}</option>`).join('');
    if(data.coordinations.length===1)$('bulkCoordSelect').value=String(data.coordinations[0].id);
    bulkCoordModal.show();
};

$('bulkCoordForm').onsubmit=async e=>{
    e.preventDefault();
    const ids=selectedCoordIds();
    if(!ids.length)return;

    const fd=new FormData(e.currentTarget);
    ids.forEach(id=>fd.append('admission_ids[]',id));

    STAGIA.loading($('bulkCoordSaveBtn'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/espace-hopital/admission-send-coordination-bulk.php',fd);
        STAGIA.toast(r.message);
        bulkCoordModal.hide();
        ids.forEach(id=>selected.delete(id));
        await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('bulkCoordSaveBtn'),false);}
};

$('admitAllFilteredBtn').onclick=async()=>{
    const total=Number(data.filtered_expected||0);if(!total)return;
    if(!STAGIA.confirm(`Vous allez admettre ${total} stagiaire(s) ATTENDU correspondant aux filtres actuels. Continuer ?`))return;

    const fd=new FormData();fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'])?>');fd.append('mode','filtered');
    const p=params();['campaign_id','university_id','promotion_id','q'].forEach(k=>{if(p.get(k))fd.append(k,p.get(k));});

    try{
        const r=await STAGIA.post(BASE_URL+'/actions/espace-hopital/admission-bulk-admit.php',fd);
        STAGIA.toast(r.message);selected.clear();await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
};

function openAdmit(id){
    const x=itemByAdmission(id);if(!x)return;
    $('admitForm').reset();$('admitAdmission').value=x.admission_id;$('admitName').textContent=nameOf(x);admitModal.show();
}

$('admitForm').onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('admitBtn'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/espace-hopital/admission-admit.php',new FormData(e.currentTarget));
        STAGIA.toast(r.message);
        admitModal.hide();
        selected.add(Number($('admitAdmission').value));
        await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('admitBtn'),false);}
};

function openCoord(id){
    const x=itemByAdmission(id);if(!x)return;
    if(!(data.coordinations||[]).length)return STAGIA.toast('Créez d’abord une coordination ou un département actif.','warning');

    $('coordForm').reset();$('coordAdmission').value=x.admission_id;$('coordName').textContent=nameOf(x);
    $('coordSelect').innerHTML='<option value="">Sélectionner...</option>'+data.coordinations.map(c=>`<option value="${c.id}">${esc(c.nom)} · ${esc(c.type||'Coordination')}</option>`).join('');
    if(data.coordinations.length===1)$('coordSelect').value=String(data.coordinations[0].id);
    coordModal.show();
}

$('coordForm').onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('coordBtn'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/espace-hopital/admission-send-coordination.php',new FormData(e.currentTarget));
        STAGIA.toast(r.message);
        coordModal.hide();
        const sentId=Number($('coordAdmission').value);
        selected.delete(sentId);
        await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('coordBtn'),false);}
};

function filterChanged(){page=1;selected.clear();load();}
['campaignFilter','universityFilter','promotionFilter','statusFilter'].forEach(id=>$(id).onchange=filterChanged);
$('searchFilter').oninput=()=>{clearTimeout(timer);timer=setTimeout(filterChanged,350);};
$('prevBtn').onclick=()=>{if(page>1){page--;selected.clear();load();}};
$('nextBtn').onclick=()=>{if(page<Number(data.pagination?.pages||1)){page++;selected.clear();load();}};
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
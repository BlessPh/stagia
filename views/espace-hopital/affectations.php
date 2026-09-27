<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE']);

$hostId=currentEtablissementId($pdo);
if(!$hostId)exit('Aucun établissement associé.');
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Affectations';
$activePage='hospital-affectations';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
    .stagia-notif-pulse{animation:stagiaNotifPulse 1.2s ease-in-out 2}
@keyframes stagiaNotifPulse{
    0%{box-shadow:inset 4px 0 0 #f97316;background:rgba(249,115,22,.12)}
    50%{box-shadow:inset 7px 0 0 #f97316;background:rgba(249,115,22,.22)}
    100%{box-shadow:inset 4px 0 0 transparent;background:transparent}
}
</style>
<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Affectations</h1>
        <p>Affectez les stagiaires de votre coordination/département vers leurs services, après contrôle du paiement.</p>
    </div>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>ADMIS</span><strong id="statTotal">0</strong><small>Stagiaires disponibles</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-people"></i></div></div>
    <div class="stagia-kpi-card"><div><span>AFFECTÉS</span><strong id="statAssigned">0</strong><small>Avec service</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-geo-alt"></i></div></div>
    <div class="stagia-kpi-card"><div><span>NON AFFECTÉS</span><strong id="statPending">0</strong><small>À organiser</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-hourglass"></i></div></div>
    <div class="stagia-kpi-card"><div><span>EN COURS</span><strong id="statActive">0</strong><small>Affectations actives</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-play-circle"></i></div></div>
</div>

<div class="stagia-list-card p-3 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <h6 class="mb-1"><i class="bi bi-lightning-charge text-warning me-1"></i>Actions rapides</h6>
            <small class="text-muted">Filtrez par coordination, sélectionnez les stagiaires, puis affectez-les dans un service de cette coordination.</small>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <button type="button" id="selectFilteredBtn" class="btn btn-sm btn-light border">
                <i class="bi bi-check2-square me-1"></i>Sélectionner les filtrés
            </button>

            <button type="button" id="autoDistributeBtn" class="btn btn-sm btn-outline-success" disabled>
                <i class="bi bi-diagram-3-fill me-1"></i>Répartir automatiquement
            </button>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-lg-2">
            <label class="form-label">Session</label>
            <select id="campaignFilter" class="form-select"><option value="">Toutes</option></select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Université</label>
            <select id="universityFilter" class="form-select"><option value="">Toutes</option></select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Promotion</label>
            <select id="promotionFilter" class="form-select"><option value="">Toutes</option></select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Coordination</label>
            <select id="coordinationFilter" class="form-select"><option value="">Toutes</option></select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Affectation</label>
            <select id="assignmentFilter" class="form-select">
                <option value="PENDING">Non affectés</option>
                <option value="ASSIGNED">Affectés</option>
                <option value="ALL">Tous</option>
            </select>
        </div>

        <div class="col-lg-2">
            <label class="form-label">Recherche</label>
            <input type="search" id="search" class="form-control" placeholder="Nom, code...">
        </div>
    </div>
</div>

<div id="bulkProgressCard" class="stagia-list-card p-3 mb-4 d-none">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
        <div><h6 class="mb-1"><i class="bi bi-people-fill me-1"></i>Affectation groupée</h6><small id="bulkProgressMessage" class="text-muted">Préparation...</small></div>
        <span id="bulkStatusBadge" class="badge bg-secondary">EN ATTENTE</span>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><small class="text-muted d-block">TOTAL</small><strong id="bulkTotal" class="fs-5">0</strong></div></div>
        <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><small class="text-muted d-block">TRAITÉS</small><strong id="bulkProcessed" class="fs-5">0</strong></div></div>
        <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><small class="text-muted d-block">AFFECTÉS</small><strong id="bulkAssigned" class="fs-5 text-success">0</strong></div></div>
        <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><small class="text-muted d-block">ÉCHECS</small><strong id="bulkFailed" class="fs-5 text-danger">0</strong></div></div>
        <div class="col-6 col-md"><div class="border rounded-3 p-2 h-100"><small class="text-muted d-block">RESTANTS</small><strong id="bulkRemaining" class="fs-5">0</strong></div></div>
    </div>

    <div class="progress" style="height:12px"><div id="bulkProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%"></div></div>
    <div class="d-flex justify-content-between gap-3 flex-wrap mt-2">
        <small id="bulkCurrentStudent" class="text-muted"></small>
        <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Le traitement continue sur le serveur même si vous quittez cette page.</small>
    </div>
    <div id="bulkErrors" class="mt-3 d-none"></div>
</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div>
            <h5 class="mb-1">Stagiaires en coordination</h5>
            <small class="text-muted">Les cases apparaissent seulement pour les stagiaires affectables : non affectés, paiement OK, coordination définie.</small>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <span id="selectionCount" class="badge bg-light text-dark border">0 sélectionné</span>

            <button type="button" id="bulkAssignBtn" class="btn btn-primary-stagia" disabled>
                <i class="bi bi-people-fill me-1"></i>Affecter au service
            </button>

            <button type="button" id="organizeGroupsBtn" class="btn btn-outline-primary">
                <i class="bi bi-arrow-repeat me-1"></i>Groupes & rotations
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
            <tr>
                <th style="width:42px"><input type="checkbox" class="form-check-input" id="selectAll" title="Tout sélectionner"></th>
                <th>STAGIAIRE</th>
                <th>UNIVERSITÉ</th>
                <th>SESSION</th>
                <th>COORDINATION</th>
                <th>SERVICE</th>
                <th>PAIEMENT</th>
                <th class="text-center">ACTION</th>
            </tr>
            </thead>
            <tbody id="body">
            <tr><td colspan="8" class="text-center py-5">Chargement...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap p-3 border-top">
        <small id="pageInfo" class="text-muted"></small>

        <div class="btn-group">
            <button type="button" id="prevPageBtn" class="btn btn-sm btn-light border">Précédent</button>
            <button type="button" id="nextPageBtn" class="btn btn-sm btn-light border">Suivant</button>
        </div>
    </div>
</div>
</main>

<div class="modal fade" id="assignmentModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="form">
<div class="modal-header">
    <div><h5 class="modal-title">Affecter au service</h5><small class="text-muted" id="studentLabel"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
<input type="hidden" name="id" id="assignmentId">
<input type="hidden" name="admission_id" id="admissionId">

<div class="row g-3">
    <div class="col-12">
        <label class="form-label">Service *</label>
        <select name="host_unit_id" id="unitId" class="form-select" required></select>
        <small class="text-muted">Seuls les services de la coordination du stagiaire sont proposés.</small>
    </div>

    <div class="col-md-6">
        <label class="form-label">Date de début *</label>
        <input type="date" name="date_debut" id="dateStart" class="form-control" required>
    </div>

    <div class="col-md-6">
        <label class="form-label">Date de fin *</label>
        <input type="date" name="date_fin" id="dateEnd" class="form-control" required>
    </div>

    <div class="col-12">
        <label class="form-label">Observation</label>
        <textarea name="observation" id="observation" class="form-control" rows="3"></textarea>
    </div>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
</div>
</form>
</div></div>
</div>

<div class="modal fade" id="bulkAssignmentModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="bulkForm">
<div class="modal-header">
    <div><h5 class="modal-title">Affectation groupée</h5><small class="text-muted" id="bulkStudentLabel">0 stagiaire</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<div class="alert alert-light border py-2">
    <i class="bi bi-info-circle me-1"></i>
    L’affectation groupée est limitée à une seule coordination/département.
</div>

<div class="row g-3">
    <div class="col-12">
        <label class="form-label">Service *</label>
        <select id="bulkUnitId" class="form-select" required></select>
    </div>

    <div class="col-md-6">
        <label class="form-label">Date de début *</label>
        <input type="date" id="bulkDateStart" class="form-control" required>
    </div>

    <div class="col-md-6">
        <label class="form-label">Date de fin *</label>
        <input type="date" id="bulkDateEnd" class="form-control" required>
    </div>

    <div class="col-12">
        <label class="form-label">Observation commune</label>
        <textarea id="bulkObservation" class="form-control" rows="3"></textarea>
    </div>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="bulkSaveBtn"><i class="bi bi-people-fill me-1"></i>Affecter</button>
</div>
</form>
</div></div>
</div>

<div class="modal fade" id="autoDistributionModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<form id="autoDistributionForm">
<div class="modal-header">
    <div><h5 class="modal-title">Répartition automatique</h5><small class="text-muted" id="autoDistributionLabel">0 stagiaire</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <div class="alert alert-light border">
        <i class="bi bi-magic me-1"></i>
        STAGIA répartira les stagiaires dans les services de la même coordination, selon les capacités disponibles.
    </div>

    <div class="mb-3">
        <label class="form-label">Capacités disponibles</label>
        <div id="autoCapacitySummary" class="border rounded-3 p-2 small"></div>
    </div>

    <div>
        <label class="form-label">Observation commune</label>
        <textarea id="autoObservation" class="form-control" rows="3" placeholder="Ex. Répartition automatique de la cohorte."></textarea>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-success" id="autoDistributeSaveBtn">
        <i class="bi bi-diagram-3-fill me-1"></i>Répartir maintenant
    </button>
</div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    const BASE=window.STAGIA_BASE_URL||'',
          esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));

    const countEl=document.getElementById('stageNotificationCount'),
          icon=document.getElementById('stageNotificationIcon'),
          head=document.getElementById('stageNotificationHead'),
          box=document.getElementById('stageNotificationItems'),
          footer=document.getElementById('stageNotificationFooter');

    if(!countEl||!box)return;

    const pages={
        'hospital-solicitations':'/views/espace-hopital/sollicitations-d4.php',
        'hospital-stagiaires':'/views/espace-hopital/stagiaires-attendus.php',
        'hospital-affectations':'/views/espace-hopital/affectations.php',
        'stages-d4-partenaires':'/views/stages/d4-partenaires.php',
        'stages-candidatures':'/views/stages/candidatures.php',
        'student-stages':'/views/espace-etudiant/stages.php'
    };

    const seen=new Set([...document.querySelectorAll('[data-notification-key]')].map(x=>String(x.dataset.notificationKey||'')));
    let first=true,busy=false,lastCount=Number(countEl.textContent||0)||0;

    function link(page){
        const part=pages[page]||'';
        return [...document.querySelectorAll('.sidebar-menu a')]
            .find(a=>String(a.getAttribute('href')||'').includes(part))||null;
    }

    function badge(page,count){
        const a=link(page);
        if(!a)return;

        let b=a.querySelector('[data-stage-badge="'+page+'"]');
        if(!b){
            b=document.createElement('span');
            b.className='sidebar-notification-badge d-none';
            b.dataset.stageBadge=page;
            a.appendChild(b);
        }

        b.textContent=count>99?'99+':count;
        b.classList.toggle('d-none',count<=0);
    }

    function pulse(page){
        const a=link(page);
        if(!a)return;
        a.classList.add('stagia-notif-pulse');
        setTimeout(()=>a.classList.remove('stagia-notif-pulse'),2000);
    }

    function pageFromKey(key){
        key=String(key||'');
        if(key.startsWith('AS:'))return 'hospital-affectations';
        if(key.startsWith('E:'))return 'hospital-stagiaires';
        if(key.startsWith('H:'))return 'hospital-solicitations';
        if(key.startsWith('A:'))return 'stages-d4-partenaires';
        if(key.startsWith('C:'))return 'stages-candidatures';
        if(key.startsWith('S:'))return 'student-stages';
        return '';
    }

    function sync(d){
        const count=Number(d.count||0),list=d.items||[];

        countEl.textContent=count>99?'99+':count;
        countEl.classList.toggle('d-none',count<=0);
        icon.className='bi '+(count>0?'bi-bell-fill':'bi-bell');
        head.innerHTML='<i class="bi bi-bell me-1"></i> Notifications'+(count>0?' ('+count+')':'');
        if(footer)footer.href=d.primary_href||'#';

        badge('hospital-solicitations',Number(d.host_count||0));
        badge('hospital-stagiaires',Number(d.expected_count||0));
        badge('hospital-affectations',Number(d.ready_assignment_count||0));
        badge('stages-d4-partenaires',Number(d.academic_count||0));
        badge('stages-candidatures',Number(d.application_count||0));
        badge('student-stages',Number(d.student_offer_count||0));

        box.innerHTML=list.length?list.map(n=>`
            <a class="notification-item" data-notification-key="${esc(n.key)}" href="${esc(n.href)}">
                <strong>${esc(n.title)}</strong>
                <small>${esc(n.subtitle)}</small>
                <small>${esc(n.meta)}</small>
            </a>
        `).join(''):'<div class="p-4 text-center text-muted small"><i class="bi bi-check2-circle d-block fs-4 mb-1"></i>Aucune nouvelle notification.</div>';

        if(!first){
            for(const n of list){
                const key=String(n.key||'');
                if(!key||seen.has(key))continue;

                seen.add(key);
                const p=pageFromKey(key);
                if(p)pulse(p);
                if(window.STAGIA?.toast)STAGIA.toast(n.title||'Nouvel élément disponible.','info');
            }

            if(count>lastCount&&window.STAGIA?.toast)
                STAGIA.toast('Nouvel élément disponible dans la sidebar.','info');
        }else{
            list.forEach(n=>seen.add(String(n.key||'')));
        }

        first=false;
        lastCount=count;
    }

    async function refresh(){
        if(busy||document.hidden)return;
        busy=true;

        try{
            const r=await fetch(BASE+'/actions/stages/stage-notification-feed.php',{
                credentials:'same-origin',
                cache:'no-store',
                headers:{'X-Requested-With':'XMLHttpRequest'}
            });

            const j=await r.json();
            if(j.success)sync(j.data||{});
            else console.warn(j.message||'Erreur notifications');
        }catch(e){
            console.error('Notifications STAGIA:',e);
        }finally{
            busy=false;
        }
    }

    refresh();
    setInterval(refresh,2000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
});
</script>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?=BASE_URL?>',
      TODAY='<?=date('Y-m-d')?>',
      CSRF='<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>',
      JOB_KEY='stagia_host_bulk_assignment_job',
      $=id=>document.getElementById(id),
      esc=STAGIA.escape;

const modal=new bootstrap.Modal($('assignmentModal'));
const bulkModal=new bootstrap.Modal($('bulkAssignmentModal'));
const autoModal=new bootstrap.Modal($('autoDistributionModal'));

let items=[],units=[],filteredItems=[],visibleItems=[],selected=new Set();
let page=1;const PER_PAGE=50;
let currentJob=localStorage.getItem(JOB_KEY)||null,pollTimer=null,hideTimer=null,finishedReloaded=false;

const upper=v=>String(v??'').toUpperCase();
const num=v=>Number(v||0);

function coordIdOf(x){return num(x.coordination_unit_id||x.coord_unit_id||x.parent_unit_id||x.parent_id);}
function coordNameOf(x){return x.coordination_name||x.coordination_unit_name||x.parent_name||'—';}
function paymentStatus(x){return upper(x.payment_status||x.invoice_status||x.statut_paiement||x.invoice_statut||x.facture_statut||x.statut_facture||'');}
function paymentAmount(x){return num(x.invoice_amount||x.montant_facture||x.amount_due||x.montant_frais);}
function paymentRef(x){return x.invoice_reference||x.reference||x.facture_reference||'';}
function paymentRequired(x){
    return num(x.payment_required||x.paiement_requis||x.frais_requis||x.frais_stage_requis)===1
        || paymentAmount(x)>0
        || paymentStatus(x)!=='';
}
function paymentOk(x){
    if(!paymentRequired(x))return true;
    return ['PAYEE','PAYÉE','PAID','VALIDE','VALIDEE','VALIDÉE'].includes(paymentStatus(x))||num(x.payment_validated)===1;
}
function paymentBadge(x){
    if(!paymentRequired(x))return '<span class="badge bg-light text-dark border">Non requis</span>';
    if(paymentOk(x))return '<span class="badge bg-success-subtle text-success">Payé</span>';

    const s=paymentStatus(x)||'NON PAYÉ',ref=paymentRef(x);
    const cls=s==='PARTIELLEMENT_PAYEE'?'bg-warning-subtle text-warning':'bg-danger-subtle text-danger';

    return `<span class="badge ${cls}">${esc(s)}</span>${ref?`<small class="d-block text-muted">${esc(ref)}</small>`:''}`;
}
function isSelectable(x){
    return num(x.admission_id)>0&&!num(x.assignment_id)&&coordIdOf(x)>0&&paymentOk(x);
}
function selectedItems(){return [...selected].map(id=>items.find(x=>num(x.admission_id)===num(id))).filter(Boolean);}
function servicesForCoord(coordId){
    return units.filter(u=>{
        const t=upper(u.type);
        return num(u.parent_id)===num(coordId)&&['SERVICE','UNITE','UNITÉ'].includes(t);
    });
}

function fillUnitSelect(id,coordId){
    const list=servicesForCoord(coordId);
    $(id).innerHTML=list.length
        ?'<option value="">Sélectionner...</option>'+list.map(u=>
            `<option value="${num(u.id)}">${esc(u.nom||'Service')}</option>`
        ).join('')
        :'<option value="">Aucun service actif configuré</option>';
}

async function charger(){
    try{
        const [a,u]=await Promise.all([
            STAGIA.request(BASE_URL+'/actions/stages/host-assignment-list.php'),
            STAGIA.request(BASE_URL+'/actions/stages/host-unit-list.php')
        ]);

        items=a.data.items||[];
        units=(u.data.items||[]).filter(x=>num(x.actif)===1);

        const available=new Set(items.filter(isSelectable).map(x=>num(x.admission_id)));
        selected=new Set([...selected].filter(id=>available.has(id)));

        const s=a.data.stats||{};
        $('statTotal').textContent=s.total||0;
        $('statAssigned').textContent=s.affectes||0;
        $('statPending').textContent=s.non_affectes||0;
        $('statActive').textContent=s.actifs||0;

        chargerServices();
        chargerFiltres();
        afficher();
        updateSelection();

    }catch(e){
        $('body').innerHTML=`<tr><td colspan="8" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;
    }
}

function chargerServices(){
    $('unitId').innerHTML='<option value="">Sélectionner...</option>';
    $('bulkUnitId').innerHTML='<option value="">Sélectionner...</option>';
}

function uniqueOptions(list,valueKey,labelFn){
    const map=new Map();
    list.forEach(x=>{
        const value=x[valueKey];
        if(value===null||value===undefined||value==='')return;
        const key=String(value);
        if(!map.has(key))map.set(key,{value:key,label:labelFn(x)});
    });
    return [...map.values()].sort((a,b)=>a.label.localeCompare(b.label,'fr'));
}

function coordinationOptions(){
    const map=new Map();
    items.forEach(x=>{
        const id=coordIdOf(x);
        if(!id)return;
        if(!map.has(String(id)))map.set(String(id),{value:String(id),label:coordNameOf(x)});
    });
    return [...map.values()].sort((a,b)=>a.label.localeCompare(b.label,'fr'));
}

function fillFilter(id,options,emptyLabel){
    const el=$(id),current=el.value;
    el.innerHTML=`<option value="">${emptyLabel}</option>`+
        options.map(x=>`<option value="${esc(x.value)}">${esc(x.label)}</option>`).join('');
    if([...el.options].some(o=>o.value===current))el.value=current;
}

function chargerFiltres(){
    fillFilter('campaignFilter',uniqueOptions(items,'campaign_code',x=>`${x.campaign_code||''} — ${x.campaign_title||''}`),'Toutes');
    fillFilter('universityFilter',uniqueOptions(items,'university_name',x=>x.university_name||'Université'),'Toutes');
    fillFilter('promotionFilter',uniqueOptions(items,'promotion',x=>x.promotion||'Promotion'),'Toutes');
    fillFilter('coordinationFilter',coordinationOptions(),'Toutes');
}

function afficher(){
    const q=$('search').value.trim().toLowerCase();
    const campaign=$('campaignFilter').value;
    const university=$('universityFilter').value;
    const promotion=$('promotionFilter').value;
    const coordination=$('coordinationFilter').value;
    const assignment=$('assignmentFilter').value;

    filteredItems=items.filter(x=>{
        if(campaign&&String(x.campaign_code||'')!==campaign)return false;
        if(university&&String(x.university_name||'')!==university)return false;
        if(promotion&&String(x.promotion||'')!==promotion)return false;
        if(coordination&&String(coordIdOf(x))!==coordination)return false;
        if(assignment==='PENDING'&&x.assignment_id)return false;
        if(assignment==='ASSIGNED'&&!x.assignment_id)return false;

        if(q){
            const text=[
                x.nom,x.postnom,x.prenom,x.stagia_code,x.university_name,
                x.campaign_title,x.campaign_code,x.promotion,x.filiere,
                x.unit_name,x.host_unit_name,x.parent_name,x.coordination_name
            ].filter(Boolean).join(' ').toLowerCase();

            if(!text.includes(q))return false;
        }

        return true;
    });

    const pages=Math.max(1,Math.ceil(filteredItems.length/PER_PAGE));
    if(page>pages)page=pages;

    const start=(page-1)*PER_PAGE;
    visibleItems=filteredItems.slice(start,start+PER_PAGE);

    render(visibleItems);
    renderPagination();
    updateSelection();
}

function renderPagination(){
    const pages=Math.max(1,Math.ceil(filteredItems.length/PER_PAGE));
    $('pageInfo').textContent=`Page ${page}/${pages} · ${filteredItems.length} résultat(s) · ${PER_PAGE} par page`;
    $('prevPageBtn').disabled=page<=1;
    $('nextPageBtn').disabled=page>=pages;
}

function render(list){
    if(!list.length){
        $('body').innerHTML='<tr><td colspan="8" class="text-center py-5 text-muted">Aucun stagiaire admis.</td></tr>';
        return;
    }

    $('body').innerHTML=list.map(x=>{
        const id=num(x.admission_id),selectable=isSelectable(x);
        const name=[x.nom,x.postnom,x.prenom].filter(Boolean).join(' ');
        const assigned=num(x.assignment_id)>0;
        const coordId=coordIdOf(x);

        return `<tr>
            <td>${selectable
                ?`<input type="checkbox" class="form-check-input row-select" data-id="${id}" ${selected.has(id)?'checked':''}>`
                :'<i class="bi bi-lock text-muted" title="Non affectable"></i>'}</td>

            <td>
                <strong>${esc(name||'-')}</strong>
                <div class="small text-muted">${esc(x.stagia_code||'-')}</div>
            </td>

            <td>
                <strong>${esc(x.university_name||'-')}</strong>
                <div class="small text-muted">${esc([x.promotion,x.filiere].filter(Boolean).join(' • '))}</div>
            </td>

            <td>
                <strong>${esc(x.campaign_title||'-')}</strong>
                <div class="small text-muted">${esc(x.campaign_code||'-')}</div>
            </td>

            <td>
                ${coordId?`<strong>${esc(coordNameOf(x))}</strong><div class="small text-muted">Coordination / département</div>`:'<span class="text-danger small">Non envoyé</span>'}
            </td>

            <td>${assigned
                ?`<strong>${esc(x.unit_name||x.host_unit_name||'-')}</strong><div class="small text-muted">${esc(x.unit_code||'')}</div>`
                :'<span class="text-muted">Non affecté</span>'}</td>

            <td>${paymentBadge(x)}</td>

            <td class="text-center">${!assigned&&paymentOk(x)&&coordId
                ?`<button type="button" class="btn btn-sm btn-primary-stagia btn-assign" data-id="${id}">
                    <i class="bi bi-geo-alt me-1"></i>Affecter
                  </button>`
                :assigned
                    ?'<span class="small text-success"><i class="bi bi-check2-all me-1"></i>Affecté</span>'
                    :!coordId
                        ?'<span class="small text-warning">À envoyer</span>'
                        :'<span class="small text-danger">Paiement requis</span>'}</td>
        </tr>`;
    }).join('');
}

function selectableVisibleIds(){
    return visibleItems.filter(isSelectable).map(x=>num(x.admission_id));
}

function updateSelection(){
    const count=selected.size;
    $('selectionCount').textContent=count+(count>1?' sélectionnés':' sélectionné');
    $('bulkAssignBtn').disabled=count===0||!!currentJob;
    $('autoDistributeBtn').disabled=count===0||!!currentJob;

    const ids=selectableVisibleIds();
    $('selectAll').checked=ids.length>0&&ids.every(id=>selected.has(id));
    $('selectAll').indeterminate=ids.some(id=>selected.has(id))&&!$('selectAll').checked;
}

$('body').addEventListener('change',e=>{
    const c=e.target.closest('.row-select');
    if(!c)return;
    const id=num(c.dataset.id);
    c.checked?selected.add(id):selected.delete(id);
    updateSelection();
});

$('selectAll').addEventListener('change',()=>{
    const ids=selectableVisibleIds();
    ids.forEach(id=>$('selectAll').checked?selected.add(id):selected.delete(id));
    render(visibleItems);
    updateSelection();
});

$('selectFilteredBtn').addEventListener('click',()=>{
    const ids=filteredItems.filter(isSelectable).map(x=>num(x.admission_id));
    if(!ids.length){STAGIA.toast('Aucun stagiaire affectable dans les résultats filtrés.','info');return;}

    ids.forEach(id=>selected.add(id));
    render(visibleItems);
    updateSelection();
    STAGIA.toast(`${ids.length} stagiaire(s) sélectionné(s).`);
});

function badge(s){
    return {
        PLANIFIEE:'<span class="badge bg-warning text-dark">PLANIFIÉE</span>',
        ACTIVE:'<span class="badge bg-success">EN COURS</span>',
        TERMINEE:'<span class="badge bg-secondary">TERMINÉE</span>',
        ANNULEE:'<span class="badge bg-danger">ANNULÉE</span>'
    }[s]||'<span class="badge bg-light text-dark">NON AFFECTÉ</span>';
}

function ouvrir(x,edit=false){
    if(!paymentOk(x)){STAGIA.toast('Paiement non validé : affectation au service bloquée.','danger');return;}

    const coordId=coordIdOf(x);
    if(!coordId){STAGIA.toast('Envoyez d’abord ce stagiaire vers une coordination.','warning');return;}

    fillUnitSelect('unitId',coordId);

    if(!servicesForCoord(coordId).length){
        STAGIA.toast('Aucun service actif trouvé pour cette coordination.','danger');
        return;
    }

    $('form').reset();
    $('admissionId').value=x.admission_id;
    $('assignmentId').value=edit&&x.assignment_id?x.assignment_id:'';
    $('studentLabel').textContent=[x.nom,x.postnom,x.prenom].filter(Boolean).join(' ');

    if(edit){
        $('unitId').value=x.host_unit_id||'';
        $('dateStart').value=x.date_debut||'';
        $('dateEnd').value=x.date_fin||'';
        $('observation').value=x.observation||'';
    }else{
        $('unitId').value='';
        $('dateStart').value=maxDate(TODAY,x.campaign_start);
        $('dateEnd').value=x.campaign_end||'';
    }

    $('dateStart').min=x.campaign_start||'';
    $('dateStart').max=x.campaign_end||'';
    $('dateEnd').min=x.campaign_start||'';
    $('dateEnd').max=x.campaign_end||'';

    modal.show();
}

$('body').addEventListener('click',e=>{
    const organize=e.target.closest('.btn-organize');

    if(organize){
        const campaign=organize.dataset.campaign||'',promotion=organize.dataset.promotion||'',qs=new URLSearchParams();
        if(campaign)qs.set('campaign_code',campaign);
        if(promotion)qs.set('promotion',promotion);
        window.location.href=BASE_URL+'/views/espace-hopital/rotations.php'+(qs.toString()?'?'+qs.toString():'');
        return;
    }

    const btn=e.target.closest('.btn-assign,.btn-edit');
    if(!btn)return;

    const x=items.find(v=>num(v.admission_id)===num(btn.dataset.id));
    if(x)ouvrir(x,btn.classList.contains('btn-edit'));
});

$('form').addEventListener('submit',async e=>{
    e.preventDefault();
    const btn=$('saveBtn');
    STAGIA.loading(btn,true);

    try{
       const admissionId=num($('admissionId').value);
        const r=await STAGIA.post(BASE_URL+'/actions/stages/host-assignment-save.php',new FormData(e.target));

        modal.hide();
        items=items.filter(x=>num(x.admission_id)!==admissionId);
        selected.delete(admissionId);
        afficher();
        updateSelection();

        STAGIA.toast(r.message||'Stagiaire affecté au service.');
        setTimeout(charger,700);
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading(btn,false);}
});

$('bulkAssignBtn').addEventListener('click',()=>{
    if(!selected.size)return;

    const chosen=selectedItems().filter(isSelectable);
    if(!chosen.length)return STAGIA.toast('Aucun stagiaire affectable dans la sélection.','warning');

    const coordIds=[...new Set(chosen.map(coordIdOf).filter(Boolean))];
    if(coordIds.length!==1){
        STAGIA.toast('Sélectionnez uniquement les stagiaires d’une seule coordination/département.','warning');
        return;
    }

    fillUnitSelect('bulkUnitId',coordIds[0]);
    if(!servicesForCoord(coordIds[0]).length)return STAGIA.toast('Aucun service actif pour cette coordination.','danger');

    const starts=chosen.map(x=>x.campaign_start).filter(Boolean);
    const ends=chosen.map(x=>x.campaign_end).filter(Boolean);

    let start=starts.length?starts.reduce((a,b)=>a>b?a:b):TODAY;
    start=maxDate(TODAY,start);

    const end=ends.length?ends.reduce((a,b)=>a<b?a:b):'';

    if(end&&start>end){
        STAGIA.toast('Les stagiaires sélectionnés n’ont pas de période commune.','warning');
        return;
    }

    $('bulkForm').reset();
    fillUnitSelect('bulkUnitId',coordIds[0]);
    $('bulkStudentLabel').textContent=chosen.length+(chosen.length>1?' stagiaires sélectionnés':' stagiaire sélectionné');
    $('bulkDateStart').value=start||'';
    $('bulkDateEnd').value=end||'';
    $('bulkDateStart').min=starts.length?starts.reduce((a,b)=>a>b?a:b):'';
    $('bulkDateEnd').max=end||'';

    bulkModal.show();
});

$('bulkForm').addEventListener('submit',async e=>{
    e.preventDefault();

    const chosen=selectedItems().filter(isSelectable);
    if(!chosen.length)return;

    const btn=$('bulkSaveBtn');
    STAGIA.loading(btn,true);

    try{
        const f=new FormData();
        f.append('csrf',CSRF);
        f.append('admission_ids',JSON.stringify(chosen.map(x=>num(x.admission_id))));
        f.append('host_unit_id',$('bulkUnitId').value);
        f.append('date_debut',$('bulkDateStart').value);
        f.append('date_fin',$('bulkDateEnd').value);
        f.append('observation',$('bulkObservation').value);

        const r=await STAGIA.post(BASE_URL+'/actions/stages/host-assignment-bulk-start.php',f);

        bulkModal.hide();

        const d=r.data||{};
        currentJob=d.job_uuid;
        localStorage.setItem(JOB_KEY,currentJob);
        finishedReloaded=false;

        renderJob({
            uuid:currentJob,
            statut:d.statut||'PENDING',
            total:d.total||chosen.length,
            processed:d.processed||0,
            assigned:d.assigned||0,
            failed:d.failed||0,
            remaining:d.remaining??d.total??chosen.length,
            progress:d.progress||0,
            current_student:null,
            errors:[]
        });

        STAGIA.toast(r.message);
        await loadBulkStatus();

    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading(btn,false);}
});

$('autoDistributeBtn').addEventListener('click',()=>{
    const chosen=selectedItems().filter(isSelectable);
    if(!chosen.length)return STAGIA.toast('Aucun stagiaire affectable dans la sélection.','warning');

    const coordIds=[...new Set(chosen.map(coordIdOf).filter(Boolean))];
    if(coordIds.length!==1)return STAGIA.toast('Sélectionnez une seule coordination/département.','warning');

    const capacityUnits=servicesForCoord(coordIds[0]).filter(x=>num(x.capacite)>0);
    if(!capacityUnits.length)return STAGIA.toast('Aucun service de cette coordination ne possède une capacité configurée.','warning');

    $('autoDistributionLabel').textContent=`${chosen.length} stagiaire(s) sélectionné(s)`;
    $('autoObservation').value='';

    $('autoCapacitySummary').innerHTML=capacityUnits.map(u=>`
        <div class="d-flex justify-content-between gap-3 py-1 border-bottom">
            <span>${esc(u.nom||'Service')}</span>
            <strong>${num(u.capacite)} place(s)</strong>
        </div>
    `).join('');

    autoModal.show();
});

$('autoDistributionForm').addEventListener('submit',async e=>{
    e.preventDefault();

    const chosen=selectedItems().filter(isSelectable);
    if(!chosen.length)return;

    if(!STAGIA.confirm(`Répartir automatiquement ${chosen.length} stagiaire(s) selon les capacités disponibles ?`))return;

    const btn=$('autoDistributeSaveBtn');
    STAGIA.loading(btn,true);

    try{
        const fd=new FormData();
        fd.append('csrf',CSRF);
        fd.append('admission_ids',JSON.stringify(chosen.map(x=>num(x.admission_id))));
        fd.append('observation',$('autoObservation').value);

        const r=await STAGIA.post(BASE_URL+'/actions/espace-hopital/assignment-auto-distribute.php',fd);

        autoModal.hide();
        selected.clear();

        const d=r.data||{};
        STAGIA.toast(`${r.message}${num(d.failed)>0?' · '+d.failed+' non affecté(s)':''}`,num(d.failed)>0?'warning':'success');

        await charger();

    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading(btn,false);}
});

$('organizeGroupsBtn').addEventListener('click',()=>{
    const source=filteredItems.filter(x=>x.assignment_id);

    if(!source.length){
        STAGIA.toast('Aucun stagiaire affecté dans les résultats actuels.','warning');
        return;
    }

    const contexts=new Map();

    source.forEach(x=>{
        const campaign=String(x.campaign_code||'').trim();
        const promotion=String(x.promotion||'').trim();
        const key=campaign+'|'+promotion;
        if(campaign)contexts.set(key,{campaign,promotion});
    });

    if(contexts.size!==1){
        STAGIA.toast('Filtrez d’abord une seule session et une seule promotion pour ouvrir directement les groupes.','warning');
        return;
    }

    const ctx=[...contexts.values()][0],qs=new URLSearchParams();
    qs.set('campaign_code',ctx.campaign);
    if(ctx.promotion)qs.set('promotion',ctx.promotion);

    window.location.href=BASE_URL+'/views/espace-hopital/rotations.php?'+qs.toString();
});

function jobRunning(s){return ['PENDING','RUNNING'].includes(s);}
function jobLabel(s){
    return {PENDING:'EN ATTENTE',RUNNING:'EN COURS',COMPLETED:'TERMINÉ',COMPLETED_WITH_ERRORS:'TERMINÉ AVEC ERREURS',FAILED:'ÉCHEC'}[s]||s||'-';
}
function jobBadge(s){
    return s==='COMPLETED'?'bg-success'
        :s==='COMPLETED_WITH_ERRORS'?'bg-warning text-dark'
        :s==='FAILED'?'bg-danger'
        :s==='RUNNING'?'bg-primary'
        :'bg-secondary';
}

function renderJob(job){
    if(!job)return;

    $('bulkProgressCard').classList.remove('d-none');
    currentJob=job.uuid;
    localStorage.setItem(JOB_KEY,currentJob);

    $('bulkTotal').textContent=num(job.total);
    $('bulkProcessed').textContent=num(job.processed);
    $('bulkAssigned').textContent=num(job.assigned);
    $('bulkFailed').textContent=num(job.failed);
    $('bulkRemaining').textContent=num(job.remaining);

    const progress=Math.max(0,Math.min(100,num(job.progress)));
    $('bulkProgressBar').style.width=progress+'%';
    $('bulkProgressBar').textContent=progress>=12?progress+'%':'';
    $('bulkProgressBar').classList.toggle('progress-bar-animated',jobRunning(job.statut));

    $('bulkStatusBadge').className='badge '+jobBadge(job.statut);
    $('bulkStatusBadge').textContent=jobLabel(job.statut);

    $('bulkProgressMessage').textContent=jobRunning(job.statut)
        ?`${job.processed||0} stagiaire(s) traité(s) sur ${job.total||0}.`
        :(job.message||(job.statut==='FAILED'?'Traitement interrompu.':'Traitement terminé.'));

    $('bulkCurrentStudent').textContent=job.current_student?'Traitement : '+job.current_student:'';

    const errors=Array.isArray(job.errors)?job.errors:[];
    if(errors.length){
        $('bulkErrors').classList.remove('d-none');
        $('bulkErrors').innerHTML=`<div class="alert alert-warning mb-0">
            <strong>Derniers échecs</strong>
            <ul class="mb-0 mt-2">${errors.map(x=>`<li>${esc(x.student_label||'Stagiaire')} — ${esc(x.error_message||'Erreur')}</li>`).join('')}</ul>
        </div>`;
    }else{
        $('bulkErrors').classList.add('d-none');
        $('bulkErrors').innerHTML='';
    }

    const active=jobRunning(job.statut);
    $('bulkAssignBtn').disabled=active||selected.size===0;
    $('autoDistributeBtn').disabled=active||selected.size===0;

    if(active){
        if(hideTimer){clearTimeout(hideTimer);hideTimer=null;}
        return;
    }

    localStorage.removeItem(JOB_KEY);
    currentJob=null;

    if(job.statut==='FAILED'){
        STAGIA.toast(job.error_message||'L’affectation groupée a échoué.','danger');
        return;
    }

    if(!finishedReloaded){
        finishedReloaded=true;
        selected.clear();
        charger();
    }

    if(!hideTimer){
        hideTimer=setTimeout(()=>{
            $('bulkProgressCard').classList.add('d-none');
            hideTimer=null;
        },5000);
    }
}

async function loadBulkStatus(){
    try{
        const q=currentJob?'?job_uuid='+encodeURIComponent(currentJob):'';
        const r=await STAGIA.request(BASE_URL+'/actions/stages/host-assignment-bulk-status.php'+q);
        const job=r.data.job||null;

        if(!job){
            if(pollTimer){clearInterval(pollTimer);pollTimer=null;}
            return;
        }

        renderJob(job);

        if(jobRunning(job.statut)){
            if(!pollTimer)pollTimer=setInterval(loadBulkStatus,1200);
        }else if(pollTimer){
            clearInterval(pollTimer);
            pollTimer=null;
        }

    }catch(e){console.error('Progression affectation groupée :',e);}
}

function maxDate(a,b){
    if(!a)return b||'';
    if(!b)return a;
    return a>b?a:b;
}

function resetAndFilter(){
    page=1;
    afficher();
}

['campaignFilter','universityFilter','promotionFilter','coordinationFilter','assignmentFilter']
    .forEach(id=>$(id).addEventListener('change',resetAndFilter));

let searchTimer=null;
$('search').addEventListener('input',()=>{
    clearTimeout(searchTimer);
    searchTimer=setTimeout(resetAndFilter,250);
});

$('prevPageBtn').addEventListener('click',()=>{
    if(page>1){page--;afficher();}
});

$('nextPageBtn').addEventListener('click',()=>{
    const pages=Math.max(1,Math.ceil(filteredItems.length/PER_PAGE));
    if(page<pages){page++;afficher();}
});

charger();
loadBulkStatus();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
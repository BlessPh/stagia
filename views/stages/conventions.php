<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/stage-convention.php';

requireConventionView($pdo);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Conventions de stage';
$activePage='stage-conventions';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.conv-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px}
.conv-kpi,.conv-card{background:#fff;border:1px solid #e5eaf0;border-radius:14px}
.conv-kpi{padding:16px}.conv-kpi small{display:block;color:#64748b;font-size:10px;text-transform:uppercase}.conv-kpi strong{font-size:26px}
.sign-line{display:flex;gap:6px;flex-wrap:wrap}.sign-ok{background:#eaf7ef;color:#198754}.sign-wait{background:#f1f5f9;color:#64748b}
@media(max-width:1000px){.conv-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.conv-kpis{grid-template-columns:1fr}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Conventions de stage</h1>
        <p>Préparez, suivez les signatures et archivez les conventions liées aux placements.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/views/documents/modeles-stage.php" class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-text me-1"></i> Modèles documents
        </a>
        <button id="addBtn" class="btn btn-primary-stagia d-none"><i class="bi bi-plus-lg me-1"></i> Nouvelle convention</button>
    </div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-shield-check text-primary me-1"></i>
    Une convention est créée par l'établissement de formation à partir d'un placement confirmé.
    Une convention signée reste immuable ; une correction administrative nécessite une nouvelle version.
</div>

<div class="conv-kpis mb-4">
    <div class="conv-kpi"><small>Total</small><strong id="kTotal">0</strong></div>
    <div class="conv-kpi"><small>Brouillons</small><strong id="kDraft">0</strong></div>
    <div class="conv-kpi"><small>À signer</small><strong id="kSign">0</strong></div>
    <div class="conv-kpi"><small>Signées</small><strong id="kSigned">0</strong></div>
    <div class="conv-kpi"><small>Archivées</small><strong id="kArchived">0</strong></div>
</div>

<div class="conv-card overflow-hidden">
<div class="p-3 border-bottom">
<div class="row g-2">
    <div class="col-lg-8"><input id="search" class="form-control" placeholder="Rechercher étudiant, référence, campagne, hôpital..."></div>
    <div class="col-lg-4"><select id="statusFilter" class="form-select"><option value="">Tous les statuts</option><option>BROUILLON</option><option>A_SIGNER</option><option>SIGNEE</option><option>ARCHIVEE</option><option>ANNULEE</option></select></div>
</div>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>CONVENTION</th><th>STAGIAIRE</th><th>STAGE</th><th>SIGNATURES</th><th>STATUT</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="rows"><tr><td colspan="6" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="convModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="convForm">
<div class="modal-header"><div><h5 class="modal-title" id="convTitle">Nouvelle convention</h5><small class="text-muted">La référence est générée automatiquement.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><input type="hidden" name="id" id="convId">
<div class="mb-3" id="placementWrap"><label class="form-label">Placement confirmé *</label><select name="placement_id" id="placementId" class="form-select"></select></div>
<div class="mb-3"><label class="form-label">Titre *</label><input name="titre" id="titre" class="form-control" maxlength="200" required></div>
<div><label class="form-label">Date d'émission</label><input type="date" name="date_emission" id="dateEmission" class="form-control"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="saveBtn">Enregistrer</button></div>
</form></div></div>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="uploadForm" enctype="multipart/form-data">
<div class="modal-header"><div><h5 class="modal-title">Déposer le PDF signé</h5><small class="text-muted">Les trois signatures doivent être enregistrées.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><input type="hidden" name="id" id="uploadId">
<label class="form-label">Convention signée (PDF, max. 8 Mo) *</label>
<input type="file" name="document" class="form-control" accept=".pdf,application/pdf" required>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="uploadBtn"><i class="bi bi-cloud-arrow-up me-1"></i> Enregistrer le PDF</button></div>
</form></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const modal=new bootstrap.Modal($('convModal')),uploadModal=new bootstrap.Modal($('uploadModal'));
let items=[],placements=[],permissions={};

function badge(s){const m={BROUILLON:['Brouillon','bg-secondary-subtle text-secondary'],A_SIGNER:['À signer','bg-warning-subtle text-warning'],SIGNEE:['Signée','bg-success-subtle text-success'],ARCHIVEE:['Archivée','bg-info-subtle text-info'],ANNULEE:['Annulée','bg-danger-subtle text-danger']}[s]||[s,'bg-light text-dark'];return `<span class="badge ${m[1]}">${esc(m[0])}</span>`;}
function date(v){if(!v)return '—';const p=String(v).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;}
function signatures(x){return `<div class="sign-line"><span class="badge ${x.date_signature_etudiant?'sign-ok':'sign-wait'}">Étudiant ${x.date_signature_etudiant?'✓':'…'}</span><span class="badge ${x.date_signature_universite?'sign-ok':'sign-wait'}">Formation ${x.date_signature_universite?'✓':'…'}</span><span class="badge ${x.date_signature_accueil?'sign-ok':'sign-wait'}">Accueil ${x.date_signature_accueil?'✓':'…'}</span></div>`;}
function actions(x){
 let a=[];
 if(x.can_edit)a.push(`<button class="btn btn-sm btn-outline-primary edit" data-id="${x.id}" title="Modifier"><i class="bi bi-pencil"></i></button>`);
 if(x.statut==='BROUILLON'&&permissions.academic_manage)a.push(`<button class="btn btn-sm btn-outline-success act" data-id="${x.id}" data-action="SUBMIT" title="Transmettre à signer"><i class="bi bi-send-check"></i></button>`);
 if(x.can_record_student)a.push(`<button class="btn btn-sm btn-outline-secondary act" data-id="${x.id}" data-action="SIGN_STUDENT" title="Enregistrer signature étudiant"><i class="bi bi-person-check"></i></button>`);
 if(x.can_university_sign)a.push(`<button class="btn btn-sm btn-outline-primary act" data-id="${x.id}" data-action="SIGN_UNIVERSITY" title="Signature établissement de formation"><i class="bi bi-building-check"></i></button>`);
 if(x.can_host_sign)a.push(`<button class="btn btn-sm btn-outline-info act" data-id="${x.id}" data-action="SIGN_HOST" title="Signature établissement d’accueil"><i class="bi bi-hospital"></i></button>`);
 if(x.ready_upload)a.push(`<button class="btn btn-sm btn-outline-success upload" data-id="${x.id}" title="Déposer PDF signé"><i class="bi bi-file-earmark-arrow-up"></i></button>`);
 if(x.document_id)a.push(`<a class="btn btn-sm btn-outline-dark" href="${BASE}/actions/stages/convention-file.php?token=${encodeURIComponent(x.uuid)}" target="_blank" title="Voir PDF"><i class="bi bi-eye"></i></a>`);
 if(['BROUILLON','A_SIGNER'].includes(x.statut)&&permissions.academic_manage)a.push(`<button class="btn btn-sm btn-outline-danger act" data-id="${x.id}" data-action="CANCEL" title="Annuler"><i class="bi bi-x-circle"></i></button>`);
 if(x.statut==='SIGNEE'&&permissions.academic_manage)a.push(`<button class="btn btn-sm btn-outline-secondary act" data-id="${x.id}" data-action="ARCHIVE" title="Archiver"><i class="bi bi-archive"></i></button>`);
 return `<div class="btn-group btn-group-sm">${a.join('')}</div>`;
}
function render(){
 const q=$('search').value.trim().toLowerCase(),st=$('statusFilter').value;
 const list=items.filter(x=>(!st||x.statut===st)&&(!q||[x.reference,x.titre,x.student_name,x.stagia_code,x.campaign_title,x.host_name,x.university_name].filter(Boolean).join(' ').toLowerCase().includes(q)));
 $('rows').innerHTML=list.length?list.map(x=>`<tr>
 <td><strong>${esc(x.titre)}</strong><small class="d-block text-muted">${esc(x.reference)} · v${x.version}</small><small class="d-block text-muted">Émise : ${date(x.date_emission)}</small></td>
 <td><strong>${esc(x.student_name)}</strong><small class="d-block text-muted">${esc(x.stagia_code||'—')}</small></td>
 <td><strong>${esc(x.campaign_title||'—')}</strong><small class="d-block text-muted">${esc(x.stage_type_label||'')}</small><small class="d-block text-muted">${esc(x.university_name)} → ${esc(x.host_name)}</small><small class="d-block text-muted">${date(x.date_debut)} → ${date(x.date_fin)}</small></td>
 <td>${signatures(x)}</td><td>${badge(x.statut)}</td><td class="text-end">${actions(x)}</td></tr>`).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucune convention.</td></tr>';
 document.querySelectorAll('.edit').forEach(b=>b.onclick=()=>edit(Number(b.dataset.id)));
 document.querySelectorAll('.act').forEach(b=>b.onclick=()=>status(Number(b.dataset.id),b.dataset.action));
 document.querySelectorAll('.upload').forEach(b=>b.onclick=()=>openUpload(Number(b.dataset.id)));
}
async function load(){try{const r=await STAGIA.request(BASE+'/actions/stages/convention-list.php');items=r.data.items||[];placements=r.data.placements||[];permissions=r.data.permissions||{};const k=r.data.kpi||{};$('kTotal').textContent=k.total||0;$('kDraft').textContent=k.brouillons||0;$('kSign').textContent=k.a_signer||0;$('kSigned').textContent=k.signees||0;$('kArchived').textContent=k.archivees||0;$('addBtn').classList.toggle('d-none',!permissions.create);render();}catch(e){STAGIA.toast(e.message,'danger');}}
function fillPlacements(){ $('placementId').innerHTML='<option value="">Sélectionner...</option>'+placements.map(p=>`<option value="${p.id}">${esc(p.student_name)} — ${esc(p.campaign_title)} — ${esc(p.host_name)}</option>`).join('');}
$('addBtn').onclick=()=>{if(!placements.length){STAGIA.toast('Aucun placement confirmé disponible pour une nouvelle convention.','danger');return;}$('convForm').reset();$('convId').value='';$('convTitle').textContent='Nouvelle convention';$('placementWrap').classList.remove('d-none');fillPlacements();$('dateEmission').value=new Date().toISOString().slice(0,10);modal.show();};
function edit(id){const x=items.find(v=>v.id===id);if(!x)return;$('convForm').reset();$('convId').value=x.id;$('titre').value=x.titre||'';$('dateEmission').value=(x.date_emission||'').slice(0,10);$('convTitle').textContent='Modifier la convention';$('placementWrap').classList.add('d-none');modal.show();}
$('convForm').onsubmit=async e=>{e.preventDefault();STAGIA.loading($('saveBtn'),true);try{const fd=new FormData(e.currentTarget),id=$('convId').value;if(id&&!fd.get('placement_id'))fd.delete('placement_id');const r=await STAGIA.post(BASE+(id?'/actions/stages/convention-update.php':'/actions/stages/convention-store.php'),fd);STAGIA.toast(r.message);modal.hide();await load();}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}};
async function status(id,action){let reason='';const labels={SUBMIT:'Transmettre cette convention pour signature ?',SIGN_STUDENT:"Enregistrer la signature de l'étudiant ?",SIGN_UNIVERSITY:"Enregistrer la signature de l'établissement de formation ?",SIGN_HOST:"Enregistrer la signature de l'établissement d'accueil ?",ARCHIVE:'Archiver cette convention signée ?'};if(action==='CANCEL'){reason=prompt("Motif d'annulation :")||'';if(!reason.trim())return;}else if(!STAGIA.confirm(labels[action]||'Confirmer cette action ?'))return;const fd=new FormData();fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'])?>');fd.append('id',id);fd.append('action',action);fd.append('reason',reason);try{const r=await STAGIA.post(BASE+'/actions/stages/convention-status.php',fd);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}}
function openUpload(id){$('uploadForm').reset();$('uploadId').value=id;uploadModal.show();}
$('uploadForm').onsubmit=async e=>{e.preventDefault();STAGIA.loading($('uploadBtn'),true);try{const fd=new FormData(e.currentTarget);const r=await fetch(BASE+'/actions/stages/convention-upload.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const raw=(await r.text()).replace(/^\uFEFF/,'');const j=JSON.parse(raw);if(!r.ok||j.success!==true)throw new Error(j.message||'Erreur upload');STAGIA.toast(j.message);uploadModal.hide();await load();}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('uploadBtn'),false);}};
$('search').oninput=render;$('statusFilter').onchange=render;load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

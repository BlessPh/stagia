<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/academic-structure.php';
require_once __DIR__.'/../../includes/academic-labels.php';

requirePermission($pdo,'academic.view');

if(!contextAcademicEnabled()){
    http_response_code(403);
    exit('La structure académique n’est pas activée pour cet établissement.');
}

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$activePage='niveaux-academiques';
$canManage=hasPermission($pdo,'academic.manage');
$etablissementId=currentEtablissementId($pdo);
$labels=academicLabels($pdo,$etablissementId);
$programSingular=academicLabelFromMap($labels,'PROGRAM',false,'Filière / Programme');
$programPlural=academicLabelFromMap($labels,'PROGRAM',true,'Filières / Programmes');
$pageTitle='Niveaux académiques';

require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.level-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.level-kpi,.level-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px}
.level-kpi{padding:16px}.level-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.level-kpi strong{display:block;font-size:27px;margin-top:4px}
.level-card{overflow:hidden}.origin-dot{width:9px;height:9px;border-radius:99px;display:inline-block;margin-right:6px;background:#64748b}.origin-dot.local{background:#0d6efd}.level-actions{white-space:nowrap}
@media(max-width:900px){.level-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.level-kpis{grid-template-columns:1fr}.stagia-page-head{gap:12px}.stagia-page-head .btn{width:100%}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Niveaux académiques</h1>
        <p>Ajoutez ou modifiez les niveaux utilisés dans vos <?= htmlspecialchars(mb_strtolower($programPlural)) ?> et vos promotions.</p>
    </div>
    <?php if($canManage): ?>
    <button class="btn btn-primary-stagia" id="addBtn"><i class="bi bi-plus-lg me-1"></i> Ajouter un niveau</button>
    <?php endif; ?>
</div>

<div class="alert alert-info border-0 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Les niveaux <strong>nationaux</strong> restent visibles en lecture seule. Votre établissement peut ajouter, modifier, activer ou désactiver uniquement ses niveaux locaux.
</div>

<div class="level-kpis mb-4">
    <div class="level-kpi"><small>Total affiché</small><strong id="kTotal">0</strong></div>
    <div class="level-kpi"><small>Niveaux locaux</small><strong id="kLocal">0</strong></div>
    <div class="level-kpi"><small>Niveaux nationaux</small><strong id="kNational">0</strong></div>
    <div class="level-kpi"><small>Actifs</small><strong id="kActive">0</strong></div>
</div>

<div class="level-card">
<div class="p-3 border-bottom">
<div class="row g-2">
    <div class="col-lg-4">
        <select id="programFilter" class="form-select"><option value="">Chargement...</option></select>
    </div>
    <div class="col-lg-3">
        <select id="cycleFilter" class="form-select"><option value="">Tous les cycles</option></select>
    </div>
    <div class="col-lg-2">
        <select id="originFilter" class="form-select">
            <option value="">Toutes origines</option>
            <option value="national">National</option>
            <option value="local">Établissement</option>
        </select>
    </div>
    <div class="col-lg-2">
        <select id="statusFilter" class="form-select">
            <option value="">Tous statuts</option>
            <option value="1">Actifs</option>
            <option value="0">Inactifs</option>
        </select>
    </div>
    <div class="col-lg-1">
        <input id="search" class="form-control" placeholder="Recherche">
    </div>
</div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>NIVEAU</th>
    <th>CYCLE</th>
    <th>ORDRE</th>
    <th>TYPE</th>
    <th>UTILISATION</th>
    <th>STATUT</th>
    <th class="text-end">ACTION</th>
</tr>
</thead>
<tbody id="rows"><tr><td colspan="7" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<?php if($canManage): ?>
<div class="modal fade" id="levelModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
<form id="levelForm">
<div class="modal-header">
    <div>
        <h5 class="modal-title" id="modalTitle">Ajouter un niveau</h5>
        <small class="text-muted">Niveau local de votre établissement</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="id" id="levelId">

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label"><?= htmlspecialchars($programSingular) ?> *</label>
        <select name="filiere_id" id="program" class="form-select" required></select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Cycle *</label>
        <select name="academic_cycle_id" id="cycle" class="form-select" required></select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Code *</label>
        <input name="code" id="code" class="form-control" maxlength="30" placeholder="Ex. B1, L1, D4" required>
    </div>
    <div class="col-md-5">
        <label class="form-label">Libellé *</label>
        <input name="libelle" id="libelle" class="form-control" maxlength="150" placeholder="Ex. Bachelier 1" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Ordre</label>
        <input name="ordre" id="ordre" type="number" min="0" class="form-control" placeholder="Auto">
    </div>
    <div class="col-md-12">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="preparatoire" name="preparatoire" value="1">
            <label class="form-check-label" for="preparatoire">Niveau préparatoire</label>
        </div>
        <small class="text-muted">À cocher seulement si ce niveau correspond à une année préparatoire autorisée pour le programme.</small>
    </div>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div>
</div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id),
      esc=STAGIA.escape,
      canManage=<?= $canManage?'true':'false' ?>,
      qs=new URLSearchParams(location.search);

let items=[],filieres=[],cycles=[],timer,loadedProgram=Number(qs.get('filiere_id')||0);
const modal=canManage?new bootstrap.Modal($('levelModal')):null;

function fillFilters(){
    const curProgram=String($('programFilter').value||loadedProgram||''),curCycle=$('cycleFilter').value;
    $('programFilter').innerHTML=filieres.length
        ?filieres.map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('')
        :'<option value="">Aucune filière / programme</option>';
    if(curProgram)$('programFilter').value=curProgram;

    $('cycleFilter').innerHTML='<option value="">Tous les cycles</option>'+cycles.map(x=>`<option value="${x.id}">${esc(x.code)} — ${esc(x.libelle)}</option>`).join('');
    $('cycleFilter').value=curCycle;

    if(canManage){
        $('program').innerHTML=filieres.map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');
        $('cycle').innerHTML=cycles.map(x=>`<option value="${x.id}">${esc(x.code)} — ${esc(x.libelle)}</option>`).join('');
    }
}

function badge(x){
    if(Number(x.actif)===1)return '<span class="badge bg-success-subtle text-success">Actif</span>';
    return '<span class="badge bg-secondary-subtle text-secondary">Inactif</span>';
}

function origin(x){
    return Number(x.local)===1
        ?'<span class="badge bg-primary-subtle text-primary"><span class="origin-dot local"></span>Établissement</span>'
        :'<span class="badge bg-light text-dark border"><span class="origin-dot"></span>National</span>';
}

function renderRows(){
    $('rows').innerHTML=items.length?items.map(x=>{
        const local=Number(x.local)===1,canEdit=canManage&&local;
        return `<tr>
            <td><strong>${esc(x.code)}</strong><small class="d-block text-muted">${esc(x.libelle)}</small></td>
            <td><strong>${esc(x.cycle_code||'—')}</strong><small class="d-block text-muted">${esc(x.cycle_libelle||'')}</small></td>
            <td>${Number(x.ordre)||0}</td>
            <td>${origin(x)} ${Number(x.preparatoire)===1?'<span class="badge bg-warning-subtle text-warning ms-1">Préparatoire</span>':''}</td>
            <td><span class="badge bg-light text-dark border">${Number(x.usage_count)||0} promotion(s)</span></td>
            <td>${badge(x)}</td>
            <td class="text-end level-actions">
                ${canEdit?`
                <button class="btn btn-sm btn-outline-primary edit-btn" data-id="${x.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-secondary status-btn" data-id="${x.id}" data-next="${Number(x.actif)===1?0:1}" title="${Number(x.actif)===1?'Désactiver':'Activer'}"><i class="bi bi-${Number(x.actif)===1?'pause-circle':'play-circle'}"></i></button>
                `:'<span class="text-muted small">Lecture seule</span>'}
            </td>
        </tr>`;
    }).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucun niveau trouvé.</td></tr>';

    document.querySelectorAll('.edit-btn').forEach(b=>b.onclick=()=>edit(Number(b.dataset.id)));
    document.querySelectorAll('.status-btn').forEach(b=>b.onclick=()=>changeStatus(Number(b.dataset.id),Number(b.dataset.next)));
}

async function load(){
    const q=new URLSearchParams();
    if(loadedProgram&&!$('programFilter').value)q.set('filiere_id',loadedProgram);
    if($('programFilter').value)q.set('filiere_id',$('programFilter').value);
    if($('cycleFilter').value)q.set('cycle_id',$('cycleFilter').value);
    if($('originFilter').value)q.set('origin',$('originFilter').value);
    if($('statusFilter').value!=='')q.set('actif',$('statusFilter').value);
    if($('search').value.trim())q.set('search',$('search').value.trim());

    try{
        const r=await STAGIA.request(BASE_URL+'/actions/academique/niveau-list.php?'+q);
        const d=r.data||{};
        items=d.items||[];filieres=d.filieres||[];cycles=d.cycles||[];
        loadedProgram=0;
        $('kTotal').textContent=d.kpi?.total||0;
        $('kLocal').textContent=d.kpi?.local||0;
        $('kNational').textContent=d.kpi?.national||0;
        $('kActive').textContent=d.kpi?.actifs||0;
        fillFilters();renderRows();
    }catch(e){
        $('rows').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

if(canManage){
    $('addBtn').onclick=()=>{
        $('levelForm').reset();$('levelId').value='';
        $('modalTitle').textContent='Ajouter un niveau';
        fillFilters();$('program').value=$('programFilter').value||'';
        modal.show();
    };

    $('program').onchange=async()=>{
        $('programFilter').value=$('program').value;
        await load();
        $('program').value=$('programFilter').value||'';
    };

    function edit(id){
        const x=items.find(v=>Number(v.id)===id);
        if(!x)return;
        $('levelForm').reset();$('levelId').value=x.id;
        $('modalTitle').textContent='Modifier le niveau '+x.code;
        $('program').value=$('programFilter').value||'';
        $('cycle').value=x.academic_cycle_id;
        $('code').value=x.code||'';
        $('libelle').value=x.libelle||'';
        $('ordre').value=x.ordre||'';
        $('preparatoire').checked=Number(x.preparatoire)===1;
        modal.show();
    }

    $('levelForm').onsubmit=async e=>{
        e.preventDefault();STAGIA.loading($('saveBtn'),true);
        try{
            const fd=new FormData(e.currentTarget);
            const id=$('levelId').value;
            const url=BASE_URL+'/actions/academique/'+(id?'niveau-update.php':'niveau-store.php');
            const r=await STAGIA.post(url,fd);
            STAGIA.toast(r.message);modal.hide();await load();
        }catch(e){STAGIA.toast(e.message,'danger');}
        finally{STAGIA.loading($('saveBtn'),false);}
    };

    async function changeStatus(id,next){
        if(!STAGIA.confirm(next?'Activer ce niveau ?':'Désactiver ce niveau ?'))return;
        const fd=new FormData();fd.append('csrf','<?= $_SESSION['csrf'] ?>');fd.append('id',id);fd.append('actif',String(next));
        try{const r=await STAGIA.post(BASE_URL+'/actions/academique/niveau-status.php',fd);STAGIA.toast(r.message);await load();}
        catch(e){STAGIA.toast(e.message,'danger');}
    }
}

$('programFilter').onchange=()=>{$('cycleFilter').value='';load();};
$('cycleFilter').onchange=load;
$('originFilter').onchange=load;
$('statusFilter').onchange=load;
$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

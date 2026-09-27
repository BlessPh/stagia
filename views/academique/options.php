<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'academic.view');
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$settings=$_SESSION['academic_settings']??[];
if(!contextAcademicEnabled() || empty($settings['option_specialite_active'])){
    http_response_code(403);exit('Les options / spécialités ne sont pas activées pour cet établissement.');
}

$pageTitle='Options / Spécialités';
$activePage='options';
$canManage=hasPermission($pdo,'academic.manage');
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Options / Spécialités</h1>
        <p>Référentiel appliqué à votre établissement. Ajoutez localement uniquement un élément réellement manquant.</p>
    </div>
    <?php if($canManage): ?>
    <button class="btn btn-primary-stagia" id="addLocalBtn"><i class="bi bi-plus-lg me-1"></i> Ajouter un élément manquant</button>
    <?php endif; ?>
</div>

<div class="alert alert-light border">
    <i class="bi bi-shield-check me-1 text-primary"></i>
    Les éléments <strong>Nationaux</strong> sont configurés par STAGIA. Un ajout local est utilisable dans votre établissement mais reste identifié et transmis au Super Admin.
</div>

<div class="stagia-list-card">
<div class="p-3 border-bottom">
<div class="row g-2">
    <div class="col-md-7"><input id="search" class="form-control" placeholder="Rechercher une option, spécialité ou filière..."></div>
    <div class="col-md-5"><select id="filiereFilter" class="form-select"><option value="">Toutes les filières</option></select></div>
</div>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>OPTION / SPÉCIALITÉ</th><th>FILIÈRE</th><th>ORIGINE</th><th>VALIDATION</th><th class="text-center">PROMOTIONS</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="rows"><tr><td colspan="6" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<?php if($canManage): ?>
<div class="modal fade" id="localOptionModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<form id="localOptionForm">
<div class="modal-header">
<div><h5 class="modal-title" id="localModalTitle">Ajouter une option / spécialité manquante</h5><small class="text-muted">Cet ajout sera limité à votre établissement jusqu'à décision du Super Admin.</small></div>
<button class="btn-close" type="button" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="id" id="localId">
<div class="row g-3">
<div class="col-md-6"><label class="form-label">Filière *</label><select name="filiere_id" id="localFiliere" class="form-select" required></select></div>
<div class="col-md-6"><label class="form-label">Nom *</label><input name="nom" id="localNom" class="form-control" maxlength="150" required></div>
<div class="col-12"><label class="form-label">Pourquoi cet élément manque-t-il ? *</label><textarea name="motif_ajout" id="localMotif" class="form-control" rows="2" maxlength="500" required></textarea></div>
<div class="col-12"><label class="form-label">Description</label><textarea name="description" id="localDescription" class="form-control" rows="2"></textarea></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-light border" type="button" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="localSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div></div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape,canManage=<?= $canManage?'true':'false' ?>;
let items=[],filieres=[],timer;
const modal=canManage?new bootstrap.Modal($('localOptionModal')):null;

function statusBadge(x){
    const s=x.validation_statut||'NATIONAL';
    const map={
        NATIONAL:['National','bg-primary-subtle text-primary'],
        EN_ATTENTE:['En attente','bg-warning-subtle text-warning'],
        VALIDE_LOCAL:['Validé local','bg-success-subtle text-success'],
        INTEGRE_REFERENTIEL:['Intégré national','bg-info-subtle text-info'],
        REFUSE:['Refusé','bg-danger-subtle text-danger']
    };
    const m=map[s]||[s,'bg-light text-dark'];
    return `<span class="badge ${m[1]}">${m[0]}</span>`;
}
function originBadge(x){
    return Number(x.ajoute_localement)===1
        ?'<span class="badge bg-light text-dark border">Ajout local</span>'
        :'<span class="badge bg-primary">STAGIA national</span>';
}
function fillFilieres(){
    const opts='<option value="">Sélectionner...</option>'+filieres.map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');
    if(canManage)$('localFiliere').innerHTML=opts;
    $('filiereFilter').innerHTML='<option value="">Toutes les filières</option>'+filieres.map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');
}
async function load(){
    const q=new URLSearchParams();
    const search=$('search').value.trim(),f=$('filiereFilter').value;
    if(search)q.set('search',search);if(f)q.set('filiere_id',f);
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/academique/option-list.php?'+q);
        items=r.data.items||[];filieres=r.data.filieres||[];fillFilieres();
        $('rows').innerHTML=items.length?items.map(x=>{
            const editable=canManage&&Number(x.ajoute_localement)===1&&['EN_ATTENTE','VALIDE_LOCAL'].includes(x.validation_statut);
            return `<tr>
                <td><strong>${esc(x.nom)}</strong><small class="d-block text-muted">${esc(x.code||'')}</small></td>
                <td>${esc(x.filiere_nom)}</td>
                <td>${originBadge(x)}</td>
                <td>${statusBadge(x)}${x.review_comment?`<small class="d-block text-muted mt-1">${esc(x.review_comment)}</small>`:''}</td>
                <td class="text-center">${Number(x.promotions_count)||0}</td>
                <td class="text-end">${editable?`<button class="btn btn-sm btn-outline-primary edit-local" data-id="${x.id}"><i class="bi bi-pencil"></i></button>`:'<span class="text-muted">—</span>'}</td>
            </tr>`;
        }).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucune option / spécialité.</td></tr>';
        document.querySelectorAll('.edit-local').forEach(b=>b.onclick=()=>edit(Number(b.dataset.id)));
    }catch(e){STAGIA.toast(e.message,'danger');}
}
if(canManage){
    $('addLocalBtn').onclick=()=>{ $('localOptionForm').reset();$('localId').value='';fillFilieres();$('localFiliere').disabled=false;$('localModalTitle').textContent='Ajouter une option / spécialité manquante';modal.show(); };
    function edit(id){
        const x=items.find(v=>Number(v.id)===id);if(!x)return;
        $('localOptionForm').reset();$('localId').value=x.id;$('localFiliere').value=x.filiere_id;$('localFiliere').disabled=true;
        $('localNom').value=x.nom||'';$('localMotif').value=x.motif_ajout||'';$('localDescription').value=x.description||'';
        $('localModalTitle').textContent='Modifier l’ajout local';modal.show();
    }
    $('localOptionForm').onsubmit=async e=>{
        e.preventDefault();STAGIA.loading($('localSaveBtn'),true);
        try{
            const id=$('localId').value;
            const fd=new FormData(e.currentTarget);
            if(id){
                fd.delete('filiere_id');fd.append('id',id);
                const r=await STAGIA.post(BASE_URL+'/actions/academique/option-update.php',fd);STAGIA.toast(r.message);
            }else{
                const r=await STAGIA.post(BASE_URL+'/actions/academique/option-store.php',fd);STAGIA.toast(r.message);
            }
            modal.hide();await load();
        }catch(e){STAGIA.toast(e.message,'danger');}
        finally{STAGIA.loading($('localSaveBtn'),false);}
    };
}
$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};
$('filiereFilter').onchange=load;
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle="Types d'établissements"; $activePage='config-types-etablissements';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Types d'établissements</h1><p>Catalogue national des établissements de formation et structures d'accueil.</p></div>
    <button class="btn btn-primary-stagia px-4" onclick="nouveauType()"><i class="bi bi-plus-lg me-1"></i> Nouveau type</button>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>TOTAL</span><strong id="statTotal">0</strong><small>Types enregistrés</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-buildings"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIFS</span><strong id="statActifs">0</strong><small>Types disponibles</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACADÉMIQUES</span><strong id="statAcademiques">0</strong><small>Établissements de formation</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-mortarboard"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACCUEIL</span><strong id="statAccueil">0</strong><small>Structures d'accueil</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-briefcase"></i></div></div>
</div>

<div class="stagia-list-card"><div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>CODE</th><th>TYPE</th><th>CATÉGORIE</th><th>USAGES</th><th>MODÈLES</th><th>ÉTABL.</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
<tbody id="typesBody"><tr><td colspan="8" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
</table></div></div>
</main>

<div class="modal fade" id="typeModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="typeForm">
<div class="modal-header"><div><h5 id="typeTitle" class="modal-title">Nouveau type</h5><small class="text-muted">Le code devient l'identifiant technique du type.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>"><input type="hidden" name="id" id="typeId">
<div class="row g-3">
    <div class="col-md-6"><label class="form-label">Libellé *</label><input class="form-control" name="libelle" id="typeLibelle" placeholder="Ex. Académie supérieure" required></div>
    <div class="col-md-6"><label class="form-label">Code</label><input class="form-control" name="code" id="typeCode" placeholder="Ex. ACADEMIE_SUPERIEURE"><small class="text-muted">Généré automatiquement si vide. Non modifiable ensuite.</small></div>
    <div class="col-md-6"><label class="form-label">Catégorie *</label><select class="form-select" name="categorie" id="typeCategorie">
        <option value="FORMATION">Formation</option><option value="ACCUEIL">Accueil</option><option value="MIXTE">Mixte</option><option value="AUTRE">Autre</option>
    </select></div>
    <div class="col-md-6"><label class="form-label">Ordre d'affichage</label><input type="number" class="form-control" name="ordre" id="typeOrdre" value="0" min="0"></div>
    <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" id="typeDescription"></div>
</div>

<div class="border rounded-3 p-3 mt-4"><div class="row g-3">
    <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" data-name="academic_enabled" id="academicEnabled"><label class="form-check-label" for="academicEnabled">Académique</label></div></div>
    <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" data-name="host_enabled" id="hostEnabled"><label class="form-check-label" for="hostEnabled">Structure d'accueil</label></div></div>
    <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" data-name="adhesion_enabled" id="adhesionEnabled"><label class="form-check-label" for="adhesionEnabled">Adhésion autorisée</label></div></div>
    <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" data-name="allow_parent" id="allowParent"><label class="form-check-label" for="allowParent">Hiérarchie parent/enfant</label></div></div>
</div></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),form=$('typeForm'),modal=new bootstrap.Modal($('typeModal'));
let items=[];
const cat={FORMATION:'Formation',ACCUEIL:'Accueil',MIXTE:'Mixte',AUTRE:'Autre'};

async function charger(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/configuration/type-etablissement-list.php'),s=r.data.stats||{};items=r.data.items||[];
        $('statTotal').textContent=s.total||0;$('statActifs').textContent=s.actifs||0;$('statAcademiques').textContent=s.academiques||0;$('statAccueil').textContent=s.accueil||0;
        $('typesBody').innerHTML=items.length?items.map(t=>{
            const usages=[Number(t.academic_enabled)?'Académique':'',Number(t.host_enabled)?"Accueil":'',Number(t.adhesion_enabled)?'Adhésion':''].filter(Boolean).join(' · ')||'—';
            return `<tr><td><strong>${STAGIA.escape(t.code)}</strong></td><td><strong class="table-main-text">${STAGIA.escape(t.libelle)}</strong><small class="d-block text-muted">${STAGIA.escape(t.description||'')}</small></td>
            <td>${STAGIA.escape(cat[t.categorie]||t.categorie)}</td><td><small>${STAGIA.escape(usages)}</small></td><td>${Number(t.nb_modeles||0)}</td><td>${Number(t.nb_etablissements||0)}</td>
            <td><span class="badge bg-${Number(t.actif)===1?'success':'secondary'}">${Number(t.actif)===1?'Actif':'Inactif'}</span></td>
            <td class="text-center"><button class="btn btn-sm btn-outline-primary btn-edit" data-id="${t.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-primary btn-status" data-id="${t.id}" data-actif="${t.actif}" title="${Number(t.actif)===1?'Désactiver':'Activer'}"><i class="bi bi-${Number(t.actif)===1?'pause-circle':'play-circle'}"></i></button></td></tr>`;
        }).join(''):'<tr><td colspan="8" class="text-center py-5 text-muted">Aucun type.</td></tr>';
        document.querySelectorAll('.btn-edit').forEach(b=>b.onclick=()=>editer(items.find(x=>Number(x.id)===Number(b.dataset.id))));
        document.querySelectorAll('.btn-status').forEach(b=>b.onclick=()=>statut(Number(b.dataset.id),Number(b.dataset.actif)));
    }catch(e){$('typesBody').innerHTML=`<tr><td colspan="8" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;STAGIA.toast(e.message,'danger');}
}
window.nouveauType=()=>{form.reset();$('typeId').value='';$('typeCode').disabled=false;$('typeTitle').textContent='Nouveau type d’établissement';$('adhesionEnabled').checked=true;modal.show();};
function editer(t){if(!t)return;form.reset();$('typeId').value=t.id;$('typeLibelle').value=t.libelle||'';$('typeCode').value=t.code||'';$('typeCode').disabled=true;
    $('typeCategorie').value=t.categorie||'AUTRE';$('typeOrdre').value=t.ordre||0;$('typeDescription').value=t.description||'';$('typeTitle').textContent=t.libelle;
    document.querySelectorAll('.bool').forEach(x=>x.checked=Number(t[x.dataset.name]||0)===1);modal.show();}
form.onsubmit=async e=>{e.preventDefault();STAGIA.loading($('saveBtn'),true);const data=new FormData(form);document.querySelectorAll('.bool').forEach(x=>data.set(x.dataset.name,x.checked?'1':'0'));
    const edit=!!$('typeId').value,url=BASE_URL+'/actions/configuration/'+(edit?'type-etablissement-update.php':'type-etablissement-store.php');
    try{const r=await STAGIA.post(url,data);modal.hide();STAGIA.toast(r.message);await charger();}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}};
async function statut(id,actif){if(!STAGIA.confirm(actif===1?'Désactiver ce type ?':'Activer ce type ?'))return;const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/configuration/type-etablissement-status.php',d);STAGIA.toast(r.message);await charger();}catch(e){STAGIA.toast(e.message,'danger');}}
charger();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

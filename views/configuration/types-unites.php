<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle="Types d'unités académiques"; $activePage='config-types-unites';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Types d'unités académiques</h1><p>Définissez les formes d’unités que les modèles académiques peuvent autoriser.</p></div>
    <button class="btn btn-primary-stagia px-4" onclick="nouveauType()"><i class="bi bi-plus-lg me-1"></i> Nouveau type</button>
</div>

<div class="alert alert-light border d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle text-primary mt-1"></i>
    <div>Désactiver un type empêche son utilisation dans les nouvelles configurations, mais ne supprime jamais les unités déjà créées.</div>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>TOTAL</span><strong id="statTotal">0</strong><small>Types enregistrés</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-diagram-2"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIFS</span><strong id="statActifs">0</strong><small>Types disponibles</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span>UTILISÉS</span><strong id="statUtilises">0</strong><small>Types déjà utilisés</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-buildings"></i></div></div>
    <div class="stagia-kpi-card"><div><span>AJOUTÉS</span><strong id="statPerso">0</strong><small>Types personnalisés</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-plus-square"></i></div></div>
</div>

<div class="stagia-list-card"><div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>CODE</th><th>TYPE D'UNITÉ</th><th>PRÉFIXE</th><th>MODÈLES</th><th>ÉTABL.</th><th>UNITÉS RÉELLES</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
<tbody id="typesBody"><tr><td colspan="8" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
</table></div></div>
</main>

<div class="modal fade" id="typeModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="typeForm">
<div class="modal-header"><div><h5 class="modal-title" id="typeTitle">Nouveau type d'unité</h5><small class="text-muted">Le code technique ne pourra plus être modifié.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>"><input type="hidden" name="id" id="typeId">
<div class="row g-3">
    <div class="col-12"><label class="form-label">Libellé *</label><input class="form-control" name="libelle" id="typeLibelle" placeholder="Ex. Académie" required></div>
    <div class="col-md-7"><label class="form-label">Code</label><input class="form-control" name="code" id="typeCode" placeholder="Ex. ACADEMIE"><small class="text-muted">Généré si vide.</small></div>
    <div class="col-md-5"><label class="form-label">Préfixe *</label><input class="form-control text-uppercase" name="prefixe_code" id="typePrefixe" maxlength="8" placeholder="ACA" required></div>
    <div class="col-12"><label class="form-label">Description</label><input class="form-control" name="description" id="typeDescription"></div>
    <div class="col-12"><label class="form-label">Ordre d'affichage</label><input type="number" class="form-control" name="ordre" id="typeOrdre" min="0" value="0"></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),form=$('typeForm'),modal=new bootstrap.Modal($('typeModal'));
let items=[];

async function charger(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/configuration/type-unite-list.php'),s=r.data.stats||{};items=r.data.items||[];
        $('statTotal').textContent=s.total||0;$('statActifs').textContent=s.actifs||0;$('statUtilises').textContent=s.utilises||0;$('statPerso').textContent=s.personnalises||0;
        $('typesBody').innerHTML=items.length?items.map(t=>`<tr>
            <td><strong>${STAGIA.escape(t.code)}</strong></td>
            <td><strong class="table-main-text">${STAGIA.escape(t.libelle)}</strong><small class="d-block text-muted">${STAGIA.escape(t.description||'')}</small></td>
            <td><span class="badge bg-light text-dark border">${STAGIA.escape(t.prefixe_code)}</span></td>
            <td>${Number(t.nb_modeles||0)}</td><td>${Number(t.nb_etablissements||0)}</td><td>${Number(t.nb_unites||0)}</td>
            <td><span class="badge bg-${Number(t.actif)===1?'success':'secondary'}">${Number(t.actif)===1?'Actif':'Inactif'}</span></td>
            <td class="text-center"><button class="btn btn-sm btn-outline-primary btn-edit" data-id="${t.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-primary btn-status" data-id="${t.id}" data-actif="${t.actif}" title="${Number(t.actif)===1?'Désactiver':'Activer'}"><i class="bi bi-${Number(t.actif)===1?'pause-circle':'play-circle'}"></i></button></td>
        </tr>`).join(''):'<tr><td colspan="8" class="text-center py-5 text-muted">Aucun type d’unité académique.</td></tr>';
        document.querySelectorAll('.btn-edit').forEach(b=>b.onclick=()=>editer(items.find(x=>Number(x.id)===Number(b.dataset.id))));
        document.querySelectorAll('.btn-status').forEach(b=>b.onclick=()=>statut(Number(b.dataset.id),Number(b.dataset.actif)));
    }catch(e){$('typesBody').innerHTML=`<tr><td colspan="8" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;STAGIA.toast(e.message,'danger');}
}
window.nouveauType=()=>{form.reset();$('typeId').value='';$('typeCode').disabled=false;$('typeTitle').textContent="Nouveau type d'unité académique";modal.show();};
function editer(t){if(!t)return;form.reset();$('typeId').value=t.id;$('typeLibelle').value=t.libelle||'';$('typeCode').value=t.code||'';$('typeCode').disabled=true;
    $('typePrefixe').value=t.prefixe_code||'';$('typeDescription').value=t.description||'';$('typeOrdre').value=t.ordre||0;$('typeTitle').textContent=t.libelle;modal.show();}
form.onsubmit=async e=>{e.preventDefault();STAGIA.loading($('saveBtn'),true);const data=new FormData(form);
    const edit=!!$('typeId').value,url=BASE_URL+'/actions/configuration/'+(edit?'type-unite-update.php':'type-unite-store.php');
    try{const r=await STAGIA.post(url,data);modal.hide();STAGIA.toast(r.message);await charger();}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}};
async function statut(id,actif){if(!STAGIA.confirm(actif===1?"Désactiver ce type d'unité ?":"Activer ce type d'unité ?"))return;const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/configuration/type-unite-status.php',d);STAGIA.toast(r.message);await charger();}catch(e){STAGIA.toast(e.message,'danger');}}
charger();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

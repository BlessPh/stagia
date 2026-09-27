<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle='Modèles académiques'; $activePage='config-modeles-academiques';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Modèles académiques</h1><p>Configurez une seule fois la structure académique standard de chaque type d’établissement.</p></div>
    <button class="btn btn-primary-stagia px-4" onclick="nouveauModele()"><i class="bi bi-plus-lg me-1"></i> Nouveau modèle</button>
</div>

<div class="alert alert-light border d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle text-primary mt-1"></i>
    <div><strong>Principe :</strong> le modèle par défaut porte la structure académique à cloner automatiquement dans chaque nouvel établissement de ce type. Le Super Admin ne reconfigure donc pas chaque université individuellement.</div>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>MODÈLES</span><strong id="statTotal">0</strong><small>Modèles configurés</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-diagram-3"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIFS</span><strong id="statActifs">0</strong><small>Modèles disponibles</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ÉTABLISSEMENTS</span><strong id="statEtablissements">0</strong><small>Configurations appliquées</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-buildings"></i></div></div>
    <div class="stagia-kpi-card"><div><span>PERSONNALISÉS</span><strong id="statPersonnalises">0</strong><small>Configurations locales</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-sliders"></i></div></div>
</div>

<div class="stagia-list-card">
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>TYPE D'ÉTABLISSEMENT</th><th>MODÈLE</th><th>UNITÉS AUTORISÉES</th><th>RÈGLES</th><th>VERSION</th><th>ÉTABL.</th><th>PAR DÉFAUT</th><th>STATUT</th><th class="text-center">ACTION</th></tr></thead>
<tbody id="modelesBody"><tr><td colspan="9" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="modeleModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="modeleForm">
<div class="modal-header"><div><h5 class="modal-title" id="modeleTitle">Nouveau modèle</h5><small class="text-muted" id="modeleTypeInfo">Configuration académique.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>"><input type="hidden" name="id" id="modeleId">

<div class="row g-3 mb-4">
    <div class="col-md-4"><label class="form-label">Type d’établissement *</label><select class="form-select" name="type_etablissement" id="modeleType" required></select></div>
    <div class="col-md-4"><label class="form-label">Nom du modèle *</label><input class="form-control" name="nom" id="modeleNom" required></div>
    <div class="col-md-4"><label class="form-label">Description</label><input class="form-control" name="description" id="modeleDescription"></div>
</div>

<h6 class="mb-3">Unités académiques</h6>
<div class="border rounded-3 p-3 mb-4">
    <div class="row g-2 mb-3">
        <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" id="uaActive" data-name="unite_academique_active"><label class="form-check-label" for="uaActive">Module activé</label></div></div>
        <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" id="uaRequired" data-name="unite_academique_obligatoire"><label class="form-check-label" for="uaRequired">Obligatoire</label></div></div>
        <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" id="uaParent" data-name="unite_parentale_autorisee"><label class="form-check-label" for="uaParent">Sous-unités autorisées</label></div></div>
    </div>
    <label class="form-label">Types autorisés</label><div id="unitTypes" class="d-flex flex-wrap gap-3"></div>
</div>

<h6 class="mb-3">Organisation du parcours</h6>
<div class="table-responsive border rounded-3">
<table class="table align-middle mb-0">
<thead><tr><th>Élément</th><th class="text-center">Activé</th><th class="text-center">Obligatoire</th><th>Règles complémentaires</th></tr></thead>
<tbody>
<tr><td><strong>Département</strong></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="departement_active"></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="departement_obligatoire"></td><td class="text-muted">Peut rester facultatif.</td></tr>
<tr><td><strong>Filière</strong></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="filiere_active"></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="filiere_obligatoire"></td><td>
    <div class="form-check"><input class="form-check-input bool" type="checkbox" data-name="filiere_directe_etablissement_autorisee" id="filDirectEtab"><label for="filDirectEtab" class="form-check-label">Directement sous établissement</label></div>
    <div class="form-check"><input class="form-check-input bool" type="checkbox" data-name="filiere_directe_unite_autorisee" id="filDirectUnite"><label for="filDirectUnite" class="form-check-label">Directement sous unité</label></div>
</td></tr>
<tr><td><strong>Option / Spécialité</strong></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="option_specialite_active"></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="option_specialite_obligatoire"></td><td class="text-muted">Selon la filière.</td></tr>
<tr><td><strong>Promotion</strong></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="promotion_active"></td><td class="text-center"><input class="form-check-input bool" type="checkbox" data-name="promotion_obligatoire"></td><td class="text-muted">Unité de gestion du stage.</td></tr>
</tbody>
</table>
</div>

<div class="d-flex flex-wrap gap-4 mt-4">
    <div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" id="modeleDefault" data-name="is_default"><label class="form-check-label fw-semibold" for="modeleDefault">Modèle par défaut</label></div>
    <div class="form-check form-switch"><input class="form-check-input bool" type="checkbox" id="modeleActif" data-name="actif"><label class="form-check-label fw-semibold" for="modeleActif">Modèle actif</label></div>
</div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),modal=new bootstrap.Modal($('modeleModal')),form=$('modeleForm');
let items=[],types={},unitCatalog=[],unitLabels={};

function typeOptions(selected=''){return '<option value="">Sélectionner...</option>'+Object.entries(types).map(([k,v])=>`<option value="${k}" ${k===selected?'selected':''}>${STAGIA.escape(v)}</option>`).join('');}
function unitChecks(active=[],isEdit=false){
    const selected=new Set(active);
    $('unitTypes').innerHTML=unitCatalog.map(u=>{
        const inactive=Number(u.actif)!==1,checked=selected.has(u.code),disabled=inactive&&!checked;
        return `<label class="form-check ${inactive?'text-muted':''}"><input class="form-check-input unit-type" type="checkbox" name="unit_types[]" value="${u.code}" ${checked?'checked':''} ${disabled?'disabled':''}> <span class="form-check-label">${STAGIA.escape(u.libelle)}${inactive?' (inactif)':''}</span></label>`;
    }).join('');
}

async function charger(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/configuration/modele-academique-list.php');items=r.data.items||[];types=r.data.types||{};unitCatalog=r.data.unit_catalog||[];unitLabels=Object.fromEntries(unitCatalog.map(u=>[u.code,u.libelle]));const s=r.data.stats||{};
        $('statTotal').textContent=s.total||0;$('statActifs').textContent=s.actifs||0;$('statEtablissements').textContent=s.etablissements||0;$('statPersonnalises').textContent=s.personnalises||0;
        $('modelesBody').innerHTML=items.length?items.map(m=>{
            const units=(m.unit_types||[]).filter(u=>Number(u.actif)===1).map(u=>unitLabels[u.type_unite]||u.libelle).join(', ')||'—';
            const rules=[Number(m.departement_active)?'Département':'',Number(m.filiere_active)?'Filière':'',Number(m.option_specialite_active)?'Option':'',Number(m.promotion_active)?'Promotion':''].filter(Boolean).join(' · ');
            return `<tr><td><strong>${STAGIA.escape(types[m.type_etablissement]||m.type_etablissement)}</strong></td>
            <td><strong class="table-main-text">${STAGIA.escape(m.nom)}</strong><small class="d-block text-muted">${STAGIA.escape(m.code)}</small></td>
            <td><small>${STAGIA.escape(units)}</small></td><td><small>${STAGIA.escape(rules)}</small></td><td>v${Number(m.version_no||1)}</td>
            <td><strong>${Number(m.nb_etablissements||0)}</strong>${Number(m.nb_personnalises||0)?` <small class="text-muted">(${m.nb_personnalises} perso.)</small>`:''}</td>
            <td><span class="badge bg-${Number(m.is_default)===1?'primary':'light text-dark border'}">${Number(m.is_default)===1?'Oui':'Non'}</span></td>
            <td><span class="badge bg-${Number(m.actif)===1?'success':'secondary'}">${Number(m.actif)===1?'Actif':'Inactif'}</span></td>
            <td class="text-center"><a class="btn btn-sm btn-outline-primary me-1" href="${BASE_URL}/views/configuration/structure-modele.php?id=${m.id}" title="Configurer la structure du type"><i class="bi bi-diagram-3"></i></a><button class="btn btn-sm btn-outline-primary btn-edit" data-id="${m.id}" title="Modifier les règles"><i class="bi bi-pencil"></i></button></td></tr>`;
        }).join(''):'<tr><td colspan="9" class="text-center py-5 text-muted">Aucun modèle académique.</td></tr>';
        document.querySelectorAll('.btn-edit').forEach(b=>b.onclick=()=>editer(items.find(x=>Number(x.id)===Number(b.dataset.id))));
    }catch(e){$('modelesBody').innerHTML=`<tr><td colspan="9" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;STAGIA.toast(e.message,'danger');}
}

window.nouveauModele=()=>{
    form.reset();$('modeleId').value='';$('modeleTitle').textContent='Nouveau modèle académique';$('modeleTypeInfo').textContent='Créer une configuration alternative ou par défaut.';
    $('modeleType').innerHTML=typeOptions();$('modeleType').disabled=false;document.querySelectorAll('.bool').forEach(x=>x.checked=false);
    ['unite_academique_active','unite_parentale_autorisee','departement_active','filiere_active','filiere_obligatoire','filiere_directe_etablissement_autorisee','filiere_directe_unite_autorisee','option_specialite_active','promotion_active','promotion_obligatoire','actif']
        .forEach(n=>{const x=document.querySelector(`.bool[data-name="${n}"]`);if(x)x.checked=true;});
    unitChecks(unitCatalog.filter(u=>Number(u.actif)===1).map(u=>u.code));modal.show();
};

function editer(m){
    if(!m)return;form.reset();$('modeleId').value=m.id;$('modeleNom').value=m.nom||'';$('modeleDescription').value=m.description||'';
    $('modeleTitle').textContent=m.nom;$('modeleTypeInfo').textContent=types[m.type_etablissement]||m.type_etablissement;
    $('modeleType').innerHTML=typeOptions(m.type_etablissement);$('modeleType').disabled=true;
    document.querySelectorAll('.bool').forEach(x=>x.checked=Number(m[x.dataset.name]||0)===1);
    unitChecks((m.unit_types||[]).filter(u=>Number(u.actif)===1).map(u=>u.type_unite),true);modal.show();
}

$('modeleType').onchange=e=>{if(!$('modeleId').value)unitChecks(unitCatalog.filter(u=>Number(u.actif)===1).map(u=>u.code));};

form.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('saveBtn'),true);const data=new FormData(form);
    document.querySelectorAll('.bool').forEach(x=>data.set(x.dataset.name,x.checked?'1':'0'));
    const edit=!!$('modeleId').value,url=BASE_URL+'/actions/configuration/'+(edit?'modele-academique-update.php':'modele-academique-store.php');
    try{const r=await STAGIA.post(url,data);modal.hide();STAGIA.toast(r.message);await charger();}
    catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}
};
charger();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$id=(int)($_GET['id']??0);
if(!$id){http_response_code(422);exit('Modèle invalide.');}

$s=$pdo->prepare("SELECT t.id,t.code,t.nom,t.type_etablissement,e.libelle type_libelle
    FROM academic_structure_templates t LEFT JOIN establishment_types e ON e.code=t.type_etablissement
    WHERE t.id=? LIMIT 1");
$s->execute([$id]);$tpl=$s->fetch(PDO::FETCH_ASSOC);
if(!$tpl){http_response_code(404);exit('Modèle introuvable.');}

$pageTitle='Structure du modèle';$activePage='config-modele-structure';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <a href="<?= BASE_URL ?>/views/configuration/modeles-academiques.php" class="detail-back"><i class="bi bi-arrow-left"></i> Modèles académiques</a>
        <h1>Structure académique par type</h1>
        <p><strong><?= htmlspecialchars($tpl['type_libelle']?:$tpl['type_etablissement']) ?></strong> · <?= htmlspecialchars($tpl['nom']) ?> · <?= htmlspecialchars($tpl['code']) ?></p>
    </div>
</div>

<div class="alert alert-info border-0">
    <i class="bi bi-copy me-2"></i><strong>Configuration unique :</strong>
    tout nouvel établissement utilisant ce modèle recevra automatiquement une copie de cette structure.
</div>

<div class="row g-3 mb-4">
    <div class="col"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">UNITÉS</small><strong class="fs-3 d-block" id="countUnits">0</strong></div></div></div>
    <div class="col"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">DÉPARTEMENTS</small><strong class="fs-3 d-block" id="countDeps">0</strong></div></div></div>
    <div class="col"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">PROGRAMMES</small><strong class="fs-3 d-block" id="countPrograms">0</strong></div></div></div>
    <div class="col"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">OPTIONS</small><strong class="fs-3 d-block" id="countOptions">0</strong></div></div></div>
    <div class="col"><div class="card border-0 shadow-sm h-100"><div class="card-body"><small class="text-muted">MODÈLES PROMOTIONS</small><strong class="fs-3 d-block" id="countPromotionModels">0</strong></div></div></div>
</div>

<div class="stagia-list-card mb-4">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center">
    <div><h5 class="mb-1">1. Unités académiques du type</h5><p class="text-muted mb-0">Ex. Faculté de Médecine, Institut / ISTM, École...</p></div>
    <button class="btn btn-primary-stagia btn-sm" onclick="openUnit()"><i class="bi bi-plus-lg me-1"></i> Ajouter</button>
</div>
<div class="table-responsive"><table class="table stagia-modern-table mb-0"><thead><tr><th>CODE</th><th>TYPE</th><th>UNITÉ</th><th>PARENTE</th><th class="text-center">ACTION</th></tr></thead><tbody id="unitsBody"></tbody></table></div>
</div>

<div class="stagia-list-card mb-4">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center">
    <div><h5 class="mb-1">2. Départements du type</h5><p class="text-muted mb-0">Étape optionnelle lorsque le modèle n’utilise pas de département.</p></div>
    <button class="btn btn-primary-stagia btn-sm" onclick="openDepartment()"><i class="bi bi-plus-lg me-1"></i> Ajouter</button>
</div>
<div class="table-responsive"><table class="table stagia-modern-table mb-0"><thead><tr><th>CODE</th><th>DÉPARTEMENT</th><th>UNITÉ</th><th class="text-center">ACTION</th></tr></thead><tbody id="depsBody"></tbody></table></div>
</div>

<div class="stagia-list-card mb-4">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center">
    <div><h5 class="mb-1">3. Filières / Programmes du type</h5><p class="text-muted mb-0">Chaque programme utilise un référentiel de cursus STAGIA contrôlé.</p></div>
    <button class="btn btn-primary-stagia btn-sm" onclick="openProgram()"><i class="bi bi-plus-lg me-1"></i> Ajouter</button>
</div>
<div class="table-responsive"><table class="table stagia-modern-table mb-0"><thead><tr><th>CODE</th><th>PROGRAMME</th><th>RATTACHEMENT</th><th>CURSUS</th><th>NIVEAUX AUTOMATIQUES</th><th class="text-center">ACTION</th></tr></thead><tbody id="programsBody"></tbody></table></div>
</div>

<div class="stagia-list-card">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center">
    <div><h5 class="mb-1">4. Options / Spécialités du type</h5><p class="text-muted mb-0">Seulement lorsque le parcours en utilise réellement.</p></div>
    <button class="btn btn-primary-stagia btn-sm" onclick="openOption()"><i class="bi bi-plus-lg me-1"></i> Ajouter</button>
</div>
<div class="table-responsive"><table class="table stagia-modern-table mb-0"><thead><tr><th>CODE</th><th>OPTION / SPÉCIALITÉ</th><th>PROGRAMME</th><th class="text-center">ACTION</th></tr></thead><tbody id="optionsBody"></tbody></table></div>
</div>

<div class="stagia-list-card mt-4">
<div class="p-4 border-bottom">
    <div class="d-flex justify-content-between align-items-start gap-3">
        <div>
            <h5 class="mb-1">5. Promotions — Niveaux du modèle</h5>
            <p class="text-muted mb-0">Les niveaux ne sont pas saisis manuellement. Ils proviennent automatiquement du référentiel de cursus choisi pour chaque programme.</p>
        </div>
        <span class="badge bg-light text-dark border">Référentiel STAGIA</span>
    </div>
</div>

<div class="alert alert-light border-0 border-bottom rounded-0 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Le <strong>Super Admin</strong> définit ici les niveaux de promotion applicables à ce type d’établissement. Lorsqu’un établissement utilisant ce modèle est créé, STAGIA lui applique automatiquement cette configuration. Les promotions réelles seront ensuite matérialisées sous la forme
    <strong>Programme + Année académique + Niveau</strong> (ex. Médecine · 2026-2027 · L1).
</div>

<div class="table-responsive">
<table class="table stagia-modern-table mb-0">
<thead>
<tr>
    <th>PROGRAMME</th>
    <th>RÉFÉRENTIEL</th>
    <th>NIVEAUX DE PROMOTION</th>
    <th>PRÉPARATOIRE</th>
    <th class="text-center">ACTION</th>
</tr>
</thead>
<tbody id="promotionModelsBody"></tbody>
</table>
</div>
</div>

</main>

<div class="modal fade" id="structureModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="structureForm">
<div class="modal-header"><div><h5 id="modalTitle" class="modal-title"></h5><small class="text-muted">Le code technique est généré automatiquement par STAGIA. Cette donnée sera clonée dans chaque nouvel établissement du type.</small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="template_id" value="<?= $id ?>">
<input type="hidden" name="entity" id="entity">
<input type="hidden" name="id" id="rowId">
<div id="dynamicFields"></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button id="saveBtn" class="btn btn-primary-stagia"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',TID=<?= $id ?>,$=id=>document.getElementById(id),modal=new bootstrap.Modal($('structureModal')),form=$('structureForm');
let data={units:[],departments:[],programs:[],options:[],promotion_models:[],unit_types:[],curricula:[],template:{}};
const esc=STAGIA.escape;

async function load(){
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/configuration/modele-structure-list.php?template_id=${TID}`);data=r.data;
        $('countUnits').textContent=data.units.length;$('countDeps').textContent=data.departments.length;$('countPrograms').textContent=data.programs.length;$('countOptions').textContent=data.options.length;$('countPromotionModels').textContent=(data.promotion_models||[]).length;
        $('unitsBody').innerHTML=data.units.length?data.units.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.type_unite)}</td><td>${esc(x.nom)}</td><td>${esc(x.parent_nom||'—')}</td><td class="text-center">${actions('UNIT',x.id)}</td></tr>`).join(''):empty(5,'Aucune unité configurée.');
        $('depsBody').innerHTML=data.departments.length?data.departments.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.nom)}</td><td>${esc(x.unit_nom||'Directement sous le type')}</td><td class="text-center">${actions('DEPARTMENT',x.id)}</td></tr>`).join(''):empty(4,'Aucun département configuré.');
        $('programsBody').innerHTML=data.programs.length?data.programs.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.nom)}</td><td>${esc(x.department_nom?'Département · '+x.department_nom:(x.unit_nom?'Unité · '+x.unit_nom:'Établissement'))}</td><td><strong>${esc(x.curriculum_code)}</strong><small class="d-block text-muted">${esc(x.curriculum_nom)}</small></td><td><small>${esc(x.niveaux||'—')}</small></td><td class="text-center">${actions('PROGRAM',x.id)}</td></tr>`).join(''):empty(6,'Aucun programme configuré.');
        $('optionsBody').innerHTML=data.options.length?data.options.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.nom)}</td><td>${esc(x.program_nom)}</td><td class="text-center">${actions('OPTION',x.id)}</td></tr>`).join(''):empty(4,'Aucune option / spécialité configurée.');

        $('promotionModelsBody').innerHTML=(data.promotion_models||[]).length?data.promotion_models.map(x=>{
            const levels=(x.levels||[]).map(l=>`<span class="badge bg-light text-dark border me-1 mb-1">${esc(l.code)}</span>`).join('');
            const prep=Number(x.preparatory_level_enabled)===1
                ?'<span class="badge bg-success">L0 activé si prévu</span>'
                :'<span class="badge bg-secondary">Non activé</span>';
            return `<tr>
                <td><strong>${esc(x.program_nom)}</strong></td>
                <td><strong>${esc(x.curriculum_code)}</strong><small class="d-block text-muted">${esc(x.curriculum_nom)}</small></td>
                <td>${levels||'<span class="text-muted">Aucun niveau</span>'}</td>
                <td>${prep}</td>
                <td class="text-center"><button class="btn btn-sm btn-outline-primary edit-promotion-model" data-id="${x.program_id}" title="Modifier le programme et son référentiel"><i class="bi bi-pencil"></i></button></td>
            </tr>`;
        }).join(''):empty(5,'Aucun modèle de promotion : créez d’abord une filière / programme et associez-lui un référentiel de cursus.');
        document.querySelectorAll('.edit-structure').forEach(b=>b.onclick=()=>edit(b.dataset.entity,Number(b.dataset.id)));
        document.querySelectorAll('.delete-structure').forEach(b=>b.onclick=()=>remove(b.dataset.entity,Number(b.dataset.id)));
        document.querySelectorAll('.edit-promotion-model').forEach(b=>b.onclick=()=>edit('PROGRAM',Number(b.dataset.id)));
    }catch(e){STAGIA.toast(e.message,'danger');}
}
function empty(n,msg){return `<tr><td colspan="${n}" class="text-center py-4 text-muted">${msg}</td></tr>`;}
function actions(entity,id){return `<button class="btn btn-sm btn-outline-primary edit-structure" data-entity="${entity}" data-id="${id}"><i class="bi bi-pencil"></i></button> <button class="btn btn-sm btn-outline-danger delete-structure" data-entity="${entity}" data-id="${id}"><i class="bi bi-trash"></i></button>`;}
function optionList(rows,value=''){return rows.map(x=>`<option value="${x.id}" ${String(x.id)===String(value)?'selected':''}>${esc(x.nom)}</option>`).join('');}
function unitTypeOptions(value=''){return data.unit_types.map(x=>`<option value="${x.type_unite}" ${x.type_unite===value?'selected':''}>${esc(x.libelle)}</option>`).join('');}
function curriculumOptions(value=''){return data.curricula.map(x=>`<option value="${x.id}" ${String(x.id)===String(value)?'selected':''}>${esc(x.nom)} (${esc(x.code)})</option>`).join('');}

window.openUnit=(x=null)=>open('UNIT',x);
window.openDepartment=(x=null)=>open('DEPARTMENT',x);
window.openProgram=(x=null)=>open('PROGRAM',x);
window.openOption=(x=null)=>open('OPTION',x);

function open(entity,x=null){
    form.reset();$('entity').value=entity;$('rowId').value=x?.id||'';
    const title={UNIT:'Unité académique',DEPARTMENT:'Département',PROGRAM:'Filière / Programme',OPTION:'Option / Spécialité'}[entity];
    $('modalTitle').textContent=(x?'Modifier ':'Ajouter ')+title;

    if(entity==='UNIT')$('dynamicFields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-5"><label class="form-label">Type *</label><select name="type_unite" class="form-select" required>${unitTypeOptions(x?.type_unite||'')}</select></div>
        <div class="col-md-7"><label class="form-label">Nom *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        
        <div class="col-md-6"><label class="form-label">Parente</label><select name="parent_id" class="form-select"><option value="">Aucune</option>${optionList(data.units.filter(u=>u.id!==x?.id),x?.parent_id||'')}</select></div>
        <div class="col-md-2"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div></div>`;

    if(entity==='DEPARTMENT')$('dynamicFields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-7"><label class="form-label">Nom *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        <div class="col-md-5"><label class="form-label">Unité académique</label><select name="unit_id" class="form-select"><option value="">Aucune / direct</option>${optionList(data.units,x?.unit_id||'')}</select></div>
        
        <div class="col-md-4"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div></div>`;

    if(entity==='PROGRAM'){
        const mode=x?.department_id?'DEPARTMENT':(x?.unit_id?'UNIT':'ESTABLISHMENT');
        const parent=x?.department_id||x?.unit_id||'';
        $('dynamicFields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-8"><label class="form-label">Nom du programme *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        
        <div class="col-md-5"><label class="form-label">Rattachement *</label><select name="rattachement_type" id="programMode" class="form-select">
            ${data.template.departement_active==1?'<option value="DEPARTMENT">Département</option>':''}
            ${data.template.filiere_directe_unite_autorisee==1?'<option value="UNIT">Unité académique</option>':''}
            ${data.template.filiere_directe_etablissement_autorisee==1?'<option value="ESTABLISHMENT">Directement sous établissement</option>':''}
        </select></div>
        <div class="col-md-7"><label class="form-label">Parent</label><select name="parent_id" id="programParent" class="form-select"></select></div>
        <div class="col-md-6"><label class="form-label">Référentiel de cursus *</label><select name="curriculum_reference_id" class="form-select" required>${curriculumOptions(x?.curriculum_reference_id||'')}</select></div>
        <div class="col-md-3"><label class="form-label">Durée (ans)</label><input type="number" min="1" max="20" name="duree_annees" class="form-control" value="${x?.duree_annees||''}"></div>
        <div class="col-md-3"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div>
        <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="preparatory_level_enabled" value="0"><input class="form-check-input" type="checkbox" name="preparatory_level_enabled" value="1" id="prep" ${Number(x?.preparatory_level_enabled||0)===1?'checked':''}><label class="form-check-label" for="prep">Activer le niveau préparatoire L0 si disponible</label></div></div>
        </div>`;
        $('programMode').value=mode;fillProgramParent(mode,parent);$('programMode').onchange=e=>fillProgramParent(e.target.value,'');
    }

    if(entity==='OPTION')$('dynamicFields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Programme *</label><select name="program_id" class="form-select" required><option value="">Sélectionner...</option>${optionList(data.programs,x?.program_id||'')}</select></div>
        <div class="col-md-6"><label class="form-label">Nom *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        
        <div class="col-md-4"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div></div>`;

    modal.show();
}
function fillProgramParent(mode,value=''){
    const el=$('programParent');
    if(mode==='DEPARTMENT')el.innerHTML='<option value="">Sélectionner...</option>'+optionList(data.departments,value);
    else if(mode==='UNIT')el.innerHTML='<option value="">Sélectionner...</option>'+optionList(data.units,value);
    else{el.innerHTML='<option value="">Établissement</option>';el.disabled=true;return;}
    el.disabled=false;
}
function edit(entity,id){
    const map={UNIT:'units',DEPARTMENT:'departments',PROGRAM:'programs',OPTION:'options'};
    open(entity,data[map[entity]].find(x=>Number(x.id)===id));
}
async function remove(entity,id){
    if(!STAGIA.confirm('Supprimer cet élément du modèle ?'))return;
    const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('template_id',TID);d.append('entity',entity);d.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/configuration/modele-structure-delete.php',d);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}
}
form.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('saveBtn'),true);
    try{const r=await STAGIA.post(BASE_URL+'/actions/configuration/modele-structure-save.php',form);modal.hide();STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}
};
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

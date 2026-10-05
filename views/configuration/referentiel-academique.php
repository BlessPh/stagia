<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

$entity=strtoupper(trim($_GET['entity']??'UNIT'));
$meta=[
    'UNIT'=>['Unités / Facultés','config-quick-units','bi-buildings'],
    'DEPARTMENT'=>['Départements','config-quick-departments','bi-diagram-2'],
    'PROGRAM'=>['Filières / Programmes','config-quick-programs','bi-mortarboard'],
    'OPTION'=>['Options / Spécialités','config-quick-options','bi-signpost-split'],
    'PROMOTION'=>['Promotions / Niveaux','config-quick-promotions','bi-layers']
];
if(!isset($meta[$entity])){http_response_code(404);exit('Référentiel invalide.');}

$stmt=$pdo->query("SELECT e.code,e.libelle,t.id template_id,t.nom template_nom,t.code template_code
    FROM establishment_types e
    LEFT JOIN academic_structure_templates t
      ON t.type_etablissement=e.code AND t.is_default=1 AND t.actif=1
    WHERE e.academic_enabled=1 AND e.actif=1
    ORDER BY e.ordre,e.libelle");
$types=$stmt->fetchAll(PDO::FETCH_ASSOC);

[$pageTitle,$activePage,$pageIcon]=$meta[$entity];
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
        <p>Accès rapide : choisissez le type d’établissement, puis gérez directement son modèle académique par défaut.</p>
    </div>
    <a href="<?= BASE_URL ?>/views/configuration/modeles-academiques.php" class="btn btn-light border">
        <i class="bi bi-diagram-3 me-1"></i> Vue complète
    </a>
</div>

<div class="alert alert-light border d-flex gap-2 align-items-start">
    <i class="bi bi-info-circle text-primary mt-1"></i>
    <div><strong>Référentiel piloté par le Super Admin.</strong> Les données ajoutées ici constituent le modèle national du type sélectionné. Pour les options / spécialités, les modifications sont aussi propagées aux établissements déjà rattachés au modèle.</div>
</div>

<div class="stagia-list-card mb-4">
<div class="p-4">
<div class="row g-3 align-items-end">
    <div class="col-lg-7">
        <label class="form-label">Type d’établissement *</label>
        <select id="typeSelect" class="form-select">
            <option value="">Sélectionner...</option>
            <?php foreach($types as $t): ?>
            <option value="<?= (int)($t['template_id']??0) ?>" <?= empty($t['template_id'])?'disabled':'' ?>>
                <?= htmlspecialchars($t['libelle']) ?>
                <?= empty($t['template_id'])?' — aucun modèle par défaut':' — '.htmlspecialchars($t['template_nom']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-lg-5">
        <div id="modelInfo" class="small text-muted">Sélectionnez un type pour charger sa configuration.</div>
    </div>
</div>
</div>
</div>

<div class="stagia-list-card">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center gap-3">
    <div>
        <h5 class="mb-1"><i class="bi <?= $pageIcon ?> me-2"></i><?= htmlspecialchars($pageTitle) ?></h5>
        <p id="sectionHelp" class="text-muted mb-0"></p>
    </div>
    <?php if($entity!=='PROMOTION'): ?>
    <button id="addBtn" class="btn btn-primary-stagia" disabled><i class="bi bi-plus-lg me-1"></i> Ajouter</button>
    <?php endif; ?>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead id="tableHead"></thead>
<tbody id="tableBody"><tr><td class="text-center py-5 text-muted">Sélectionnez d’abord un type d’établissement.</td></tr></tbody>
</table>
</div>
</div>



<?php if($entity==='PROGRAM'): ?>
<div class="stagia-list-card mt-4" id="localProgramRequestsCard">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
        <h5 class="mb-1"><i class="bi bi-mortarboard me-2"></i>Filières / programmes ajoutés localement</h5>
        <p class="text-muted mb-0">Propositions des établissements lorsqu’un programme manque au référentiel STAGIA.</p>
    </div>
    <select id="localProgramStatus" class="form-select" style="max-width:220px">
        <option value="">Tous les statuts</option>
        <option value="EN_ATTENTE">En attente</option>
        <option value="VALIDE_LOCAL">Validés localement</option>
        <option value="INTEGRE_REFERENTIEL">Intégrés au national</option>
        <option value="REFUSE">Refusés</option>
    </select>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>PROGRAMME</th>
    <th>ÉTABLISSEMENT</th>
    <th>CURSUS</th>
    <th>RATTACHEMENT</th>
    <th>STATUT</th>
    <th>DEMANDE</th>
    <th class="text-center">DÉCISION</th>
</tr>
</thead>
<tbody id="localProgramRequestsBody">
<tr><td colspan="7" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<?php if($entity==='DEPARTMENT'): ?>
<div class="stagia-list-card mt-4" id="localDepartmentRequestsCard">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
        <h5 class="mb-1"><i class="bi bi-diagram-2 me-2"></i>Départements ajoutés localement</h5>
        <p class="text-muted mb-0">Demandes des établissements lorsqu’un département manque au référentiel STAGIA.</p>
    </div>
    <select id="localDepartmentStatus" class="form-select" style="max-width:220px">
        <option value="">Tous les statuts</option>
        <option value="EN_ATTENTE">En attente</option>
        <option value="VALIDE_LOCAL">Validés localement</option>
        <option value="INTEGRE_REFERENTIEL">Intégrés au national</option>
        <option value="REFUSE">Refusés</option>
    </select>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>DÉPARTEMENT</th>
    <th>ÉTABLISSEMENT</th>
    <th>UNITÉ</th>
    <th>STATUT</th>
    <th>DEMANDE</th>
    <th class="text-center">DÉCISION</th>
</tr>
</thead>
<tbody id="localDepartmentRequestsBody">
<tr><td colspan="6" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<?php if($entity==='UNIT'): ?>
<div class="stagia-list-card mt-4"><div class="p-4 border-bottom d-flex justify-content-between align-items-center gap-3 flex-wrap"><div><h5 class="mb-1"><i class="bi bi-building-add me-2"></i>Unités ajoutées localement</h5><p class="text-muted mb-0">Demandes des établissements pour une unité manquante.</p></div><select id="localUnitStatus" class="form-select" style="max-width:220px"><option value="">Tous les statuts</option><option value="EN_ATTENTE">En attente</option><option value="VALIDE_LOCAL">Validés localement</option><option value="INTEGRE_REFERENTIEL">Intégrés au national</option><option value="REFUSE">Refusés</option></select></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>UNITÉ</th><th>ÉTABLISSEMENT</th><th>TYPE</th><th>PARENTE</th><th>STATUT</th><th>DEMANDE</th><th class="text-center">DÉCISION</th></tr></thead><tbody id="localUnitRequestsBody"><tr><td colspan="7" class="text-center py-5 text-muted">Sélectionnez un type.</td></tr></tbody></table></div></div>
<?php endif; ?>

<?php if($entity==='OPTION'): ?>
<div class="stagia-list-card mt-4" id="localRequestsCard">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
        <h5 class="mb-1"><i class="bi bi-building-add me-2"></i>Ajouts locaux des établissements</h5>
        <p class="text-muted mb-0">Éléments ajoutés par une université parce qu'ils manquaient au référentiel appliqué.</p>
    </div>
    <select id="localStatus" class="form-select" style="max-width:220px">
        <option value="">Tous les statuts</option>
        <option value="EN_ATTENTE">En attente</option>
        <option value="VALIDE_LOCAL">Validés localement</option>
        <option value="INTEGRE_REFERENTIEL">Intégrés au national</option>
        <option value="REFUSE">Refusés</option>
    </select>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>OPTION / SPÉCIALITÉ</th><th>ÉTABLISSEMENT</th><th>FILIÈRE</th><th>STATUT</th><th>DEMANDE</th><th class="text-center">DÉCISION</th></tr></thead>
<tbody id="localRequestsBody"><tr><td colspan="6" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr></tbody>
</table>
</div>
</div>
<?php endif; ?>

</main>

<div class="modal fade" id="quickModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="quickForm">
<div class="modal-header">
    <div><h5 id="modalTitle" class="modal-title"></h5><small class="text-muted">Le code technique est généré automatiquement par STAGIA.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="template_id" id="templateId">
<input type="hidden" name="entity" id="formEntity">
<input type="hidden" name="id" id="rowId">
<div id="fields"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button id="saveBtn" class="btn btn-primary-stagia"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',ENTITY='<?= $entity ?>',$=id=>document.getElementById(id);
const modal=new bootstrap.Modal($('quickModal')),form=$('quickForm'),esc=STAGIA.escape;
let TID=0,data={units:[],departments:[],programs:[],options:[],promotion_models:[],unit_types:[],curricula:[],template:{}};

const help={
    UNIT:"Ajoutez les unités académiques prévues pour ce type : faculté, institut, école, section, centre…",
    DEPARTMENT:"Ajoutez les départements et rattachez-les à une unité lorsque le modèle le prévoit.",
    PROGRAM:"Ajoutez les filières / programmes et associez leur référentiel de cursus.",
    OPTION:"Ajoutez uniquement les options / spécialités réellement prévues par les programmes.",
    PROMOTION:"Le Super Admin définit ici les niveaux de promotion applicables au type sélectionné. Les promotions réelles seront ensuite matérialisées par Programme + Année académique + Niveau."
};
$('sectionHelp').textContent=help[ENTITY];

const heads={
    UNIT:'<tr><th>CODE</th><th>TYPE</th><th>UNITÉ</th><th>PARENTE</th><th class="text-center">ACTION</th></tr>',
    DEPARTMENT:'<tr><th>CODE</th><th>DÉPARTEMENT</th><th>UNITÉ</th><th class="text-center">ACTION</th></tr>',
    PROGRAM:'<tr><th>CODE</th><th>PROGRAMME</th><th>RATTACHEMENT</th><th>CURSUS</th><th>NIVEAUX</th><th class="text-center">ACTION</th></tr>',
    OPTION:'<tr><th>CODE</th><th>OPTION / SPÉCIALITÉ</th><th>PROGRAMME</th><th class="text-center">ACTION</th></tr>',
    PROMOTION:'<tr><th>PROGRAMME</th><th>RÉFÉRENTIEL</th><th>NIVEAUX DE PROMOTION</th><th>PRÉPARATOIRE</th><th class="text-center">ACTION</th></tr>'
};
$('tableHead').innerHTML=heads[ENTITY];

$('typeSelect').onchange=async e=>{
    TID=Number(e.target.value||0);
    <?php if($entity!=='PROMOTION'): ?>$('addBtn').disabled=!TID;<?php endif; ?>
    if(!TID){$('tableBody').innerHTML='<tr><td class="text-center py-5 text-muted">Sélectionnez d’abord un type d’établissement.</td></tr>';return;}
    await load();
};

async function load(){
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/configuration/modele-structure-list.php?template_id=${TID}`);
        data=r.data;$('templateId').value=TID;
        $('modelInfo').innerHTML=`Modèle actif : <strong>${esc(data.template.nom)}</strong> · ${esc(data.template.code)} · v${Number(data.template.version_no||1)}`;
        render();
        <?php if($entity==='PROGRAM'): ?>await loadLocalProgramRequests();<?php endif; ?>
        <?php if($entity==='DEPARTMENT'): ?>await loadLocalDepartmentRequests();<?php endif; ?>
        <?php if($entity==='UNIT'): ?>await loadLocalUnitRequests();<?php endif; ?>
        <?php if($entity==='OPTION'): ?>await loadLocalRequests();<?php endif; ?>
    }catch(e){STAGIA.toast(e.message,'danger');}
}

function actions(entity,id){
    return `<button class="btn btn-sm btn-outline-primary edit-row" data-entity="${entity}" data-id="${id}"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-danger delete-row" data-entity="${entity}" data-id="${id}"><i class="bi bi-trash"></i></button>`;
}
function empty(cols,msg){return `<tr><td colspan="${cols}" class="text-center py-5 text-muted">${msg}</td></tr>`;}

function render(){
    let html='';
    if(ENTITY==='UNIT') html=data.units.length?data.units.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.type_unite)}</td><td>${esc(x.nom)}</td><td>${esc(x.parent_nom||'—')}</td><td class="text-center">${actions('UNIT',x.id)}</td></tr>`).join(''):empty(5,'Aucune unité configurée pour ce type.');
    if(ENTITY==='DEPARTMENT') html=data.departments.length?data.departments.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.nom)}</td><td>${Number(x.unit_reference_invalid)===1&&Number(data.template.unite_academique_active)===1?'<span class="badge bg-danger">Unité liée absente ou inactive</span>':esc(x.unit_nom||'Directement sous le type')}</td><td class="text-center">${actions('DEPARTMENT',x.id)}</td></tr>`).join(''):empty(4,'Aucun département configuré pour ce type.');
    if(ENTITY==='PROGRAM') html=data.programs.length?data.programs.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.nom)}</td><td>${Number(x.department_reference_invalid)===1?'<span class="badge bg-danger">Département lié absent ou inactif</span>':(Number(x.unit_reference_invalid)===1&&Number(data.template.unite_academique_active)===1?'<span class="badge bg-danger">Unité liée absente ou inactive</span>':esc(x.department_nom?'Département · '+x.department_nom:(x.unit_nom?'Unité · '+x.unit_nom:'Établissement')))}</td><td><strong>${esc(x.curriculum_code)}</strong><small class="d-block text-muted">${esc(x.curriculum_nom)}</small></td><td><small>${esc(x.niveaux||'—')}</small></td><td class="text-center">${actions('PROGRAM',x.id)}</td></tr>`).join(''):empty(6,'Aucun programme configuré pour ce type.');
    if(ENTITY==='OPTION') html=data.options.length?data.options.map(x=>`<tr><td><strong>${esc(x.code)}</strong></td><td>${esc(x.nom)}</td><td>${esc(x.program_nom)}</td><td class="text-center">${actions('OPTION',x.id)}</td></tr>`).join(''):empty(4,'Aucune option / spécialité configurée pour ce type.');
    if(ENTITY==='PROMOTION') html=(data.promotion_models||[]).length?data.promotion_models.map(x=>{
        const levels=(x.levels||[]).map(l=>`<span class="badge bg-light text-dark border me-1 mb-1">${esc(l.code)}</span>`).join('');
        return `<tr><td><strong>${esc(x.program_nom)}</strong></td><td><strong>${esc(x.curriculum_code)}</strong><small class="d-block text-muted">${esc(x.curriculum_nom)}</small></td><td>${levels}</td><td>${Number(x.preparatory_level_enabled)===1?'<span class="badge bg-success">L0 activé</span>':'<span class="badge bg-secondary">Non</span>'}</td><td class="text-center"><button class="btn btn-sm btn-outline-primary edit-promotion" data-id="${x.program_id}"><i class="bi bi-pencil me-1"></i> Configurer</button></td></tr>`;
    }).join(''):empty(5,'Aucun modèle de promotion : créez d’abord une filière / programme et associez-lui un référentiel de cursus.');

    $('tableBody').innerHTML=html;
    document.querySelectorAll('.edit-row').forEach(b=>b.onclick=()=>edit(b.dataset.entity,Number(b.dataset.id)));
    document.querySelectorAll('.delete-row').forEach(b=>b.onclick=()=>remove(b.dataset.entity,Number(b.dataset.id)));
    document.querySelectorAll('.edit-promotion').forEach(b=>b.onclick=()=>edit('PROGRAM',Number(b.dataset.id)));
}

<?php if($entity!=='PROMOTION'): ?>
$('addBtn').onclick=()=>open(ENTITY,null);
<?php endif; ?>

function selectOptions(rows,value=''){return rows.map(x=>`<option value="${x.id}" ${String(x.id)===String(value)?'selected':''}>${esc(x.nom)}</option>`).join('');}
function unitTypes(value=''){return data.unit_types.map(x=>`<option value="${x.type_unite}" ${x.type_unite===value?'selected':''}>${esc(x.libelle)}</option>`).join('');}
function curricula(value=''){return data.curricula.map(x=>`<option value="${x.id}" ${String(x.id)===String(value)?'selected':''}>${esc(x.nom)} (${esc(x.code)})</option>`).join('');}

function edit(entity,id){
    const map={UNIT:'units',DEPARTMENT:'departments',PROGRAM:'programs',OPTION:'options'};
    open(entity,data[map[entity]].find(x=>Number(x.id)===id));
}

function open(entity,x){
    form.reset();$('formEntity').value=entity;$('rowId').value=x?.id||'';$('templateId').value=TID;
    const label={UNIT:'Unité / Faculté',DEPARTMENT:'Département',PROGRAM:'Filière / Programme',OPTION:'Option / Spécialité'}[entity];
    $('modalTitle').textContent=(x?'Modifier ':'Ajouter ')+label;

    if(entity==='UNIT') $('fields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-5"><label class="form-label">Type d’unité *</label><select name="type_unite" class="form-select" required>${unitTypes(x?.type_unite||'')}</select></div>
        <div class="col-md-7"><label class="form-label">Nom *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        <div class="col-md-8"><label class="form-label">Parente</label><select name="parent_id" class="form-select"><option value="">Aucune</option>${selectOptions(data.units.filter(u=>u.id!==x?.id),x?.parent_id||'')}</select></div>
        <div class="col-md-4"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div>
        </div>`;

    if(entity==='DEPARTMENT') $('fields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-8"><label class="form-label">Nom *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        <div class="col-md-4"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div>
        <div class="col-12"><label class="form-label">Unité académique</label><select name="unit_id" class="form-select"><option value="">Aucune / directement sous le type</option>${Number(data.template.unite_academique_active)===1?selectOptions(data.units,x?.unit_id||''):''}</select></div>
        </div>`;

    if(entity==='PROGRAM'){
        const mode=x?.department_id?'DEPARTMENT':(x?.unit_id?'UNIT':'ESTABLISHMENT'),parent=x?.department_id||x?.unit_id||'';
        $('fields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-8"><label class="form-label">Nom du programme *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        <div class="col-md-4"><label class="form-label">Durée (ans)</label><input type="number" min="1" max="20" name="duree_annees" class="form-control" value="${x?.duree_annees||''}"></div>
        <div class="col-md-5"><label class="form-label">Rattachement *</label><select name="rattachement_type" id="programMode" class="form-select">
            ${Number(data.template.departement_active)===1?'<option value="DEPARTMENT">Département</option>':''}
            ${Number(data.template.unite_academique_active)===1&&Number(data.template.filiere_directe_unite_autorisee)===1?'<option value="UNIT">Unité académique</option>':''}
            ${Number(data.template.filiere_directe_etablissement_autorisee)===1?'<option value="ESTABLISHMENT">Directement sous établissement</option>':''}
        </select></div>
        <div class="col-md-7"><label class="form-label">Parent</label><select name="parent_id" id="programParent" class="form-select"></select></div>
        <div class="col-md-8"><label class="form-label">Référentiel de cursus *</label><select name="curriculum_reference_id" class="form-select" required>${curricula(x?.curriculum_reference_id||'')}</select></div>
        <div class="col-md-4"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div>
        <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="preparatory_level_enabled" value="0"><input class="form-check-input" type="checkbox" name="preparatory_level_enabled" value="1" id="prep" ${Number(x?.preparatory_level_enabled||0)===1?'checked':''}><label class="form-check-label" for="prep">Activer le niveau préparatoire L0 si disponible</label></div></div>
        </div>`;
        $('programMode').value=mode;fillProgramParent(mode,parent);$('programMode').onchange=e=>fillProgramParent(e.target.value,'');
    }

    if(entity==='OPTION') $('fields').innerHTML=`
        <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Programme *</label><select name="program_id" class="form-select" required><option value="">Sélectionner...</option>${selectOptions(data.programs,x?.program_id||'')}</select></div>
        <div class="col-md-6"><label class="form-label">Nom *</label><input name="nom" class="form-control" value="${esc(x?.nom||'')}" required></div>
        <div class="col-12"><label class="form-label">Ordre</label><input type="number" name="ordre" class="form-control" value="${x?.ordre||0}"></div>
        </div>`;

    modal.show();
}

function fillProgramParent(mode,value=''){
    const el=$('programParent');
    if(mode==='DEPARTMENT') el.innerHTML='<option value="">Sélectionner...</option>'+selectOptions(data.departments,value);
    else if(mode==='UNIT') el.innerHTML='<option value="">Sélectionner...</option>'+selectOptions(data.units,value);
    else{el.innerHTML='<option value="">Établissement</option>';el.disabled=true;return;}
    el.disabled=false;
}

async function remove(entity,id){
    if(!STAGIA.confirm('Supprimer cet élément du modèle ?'))return;
    const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('template_id',TID);d.append('entity',entity);d.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/configuration/modele-structure-delete.php',d);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}
}




<?php if($entity==='PROGRAM'): ?>
async function loadLocalProgramRequests(){
    if(!TID){
        $('localProgramRequestsBody').innerHTML=
            '<tr><td colspan="7" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr>';
        return;
    }

    const q=new URLSearchParams({template_id:String(TID)});
    if($('localProgramStatus').value)q.set('status',$('localProgramStatus').value);

    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/configuration/programme-local-list.php?${q}`);
        const list=r.data.items||[];

        $('localProgramRequestsBody').innerHTML=list.length?list.map(x=>{
            const status={
                EN_ATTENTE:'<span class="badge bg-warning-subtle text-warning">En attente</span>',
                VALIDE_LOCAL:'<span class="badge bg-success-subtle text-success">Validé local</span>',
                INTEGRE_REFERENTIEL:'<span class="badge bg-info-subtle text-info">Intégré national</span>',
                REFUSE:'<span class="badge bg-danger-subtle text-danger">Refusé</span>'
            }[x.validation_statut]||esc(x.validation_statut||'—');

            const parent=x.rattachement_type==='DEPARTEMENT'
                ?`Département · ${esc(x.departement_nom||'—')}`
                :x.rattachement_type==='UNITE'
                    ?`Unité · ${esc(x.unite_nom||'—')}`
                    :'Établissement';

            const decisions=x.validation_statut==='EN_ATTENTE'
                ?`<div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-success local-program-review" data-id="${x.id}" data-action="APPROVE_LOCAL" title="Valider localement"><i class="bi bi-check2"></i></button>
                    <button class="btn btn-outline-primary local-program-review" data-id="${x.id}" data-action="INTEGRATE_NATIONAL" title="Intégrer au national"><i class="bi bi-globe2"></i></button>
                    <button class="btn btn-outline-danger local-program-review" data-id="${x.id}" data-action="REFUSE" title="Refuser"><i class="bi bi-x-lg"></i></button>
                  </div>`
                :'<span class="text-muted">Traité</span>';

            return `<tr>
                <td>
                    <strong>${esc(x.nom)}</strong>
                    <small class="d-block text-muted">${esc(x.code||'')}${x.duree_annees?` · ${Number(x.duree_annees)} an(s)`:''}</small>
                </td>
                <td>
                    <strong>${esc(x.etablissement_nom)}</strong>
                    <small class="d-block text-muted">${esc(x.type_etablissement_nom||'')}</small>
                </td>
                <td>
                    <strong>${esc(x.curriculum_code||'—')}</strong>
                    <small class="d-block text-muted">${esc(x.curriculum_nom||'')}</small>
                </td>
                <td>${parent}</td>
                <td>${status}${x.review_comment?`<small class="d-block text-muted mt-1">${esc(x.review_comment)}</small>`:''}</td>
                <td><small>${esc(x.motif_ajout||'—')}</small></td>
                <td class="text-center">${decisions}</td>
            </tr>`;
        }).join('')
        :'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune proposition locale.</td></tr>';

        document.querySelectorAll('.local-program-review')
            .forEach(b=>b.onclick=()=>reviewLocalProgram(Number(b.dataset.id),b.dataset.action));
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

async function reviewLocalProgram(id,action){
    let comment='';

    if(action==='REFUSE'){
        comment=prompt('Motif du refus :')||'';
        if(!comment.trim())return;
    }else if(action==='INTEGRATE_NATIONAL'){
        if(!STAGIA.confirm('Intégrer cette filière / ce programme au référentiel national et le propager aux établissements concernés ?'))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }else{
        if(!STAGIA.confirm('Valider cette filière / ce programme uniquement pour cet établissement ?'))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }

    const fd=new FormData();
    fd.append('csrf','<?= $_SESSION['csrf'] ?>');
    fd.append('id',id);
    fd.append('action',action);
    fd.append('comment',comment);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/configuration/programme-local-review.php',
            fd
        );

        STAGIA.toast(r.message);
        await load();
        await loadLocalProgramRequests();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

$('localProgramStatus').onchange=loadLocalProgramRequests;
<?php endif; ?>

<?php if($entity==='DEPARTMENT'): ?>
async function loadLocalDepartmentRequests(){
    if(!TID){
        $('localDepartmentRequestsBody').innerHTML=
            '<tr><td colspan="6" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr>';
        return;
    }

    const q=new URLSearchParams({template_id:String(TID)});
    if($('localDepartmentStatus').value)q.set('status',$('localDepartmentStatus').value);

    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/configuration/departement-local-list.php?${q}`);
        const list=r.data.items||[];

        $('localDepartmentRequestsBody').innerHTML=list.length?list.map(x=>{
            const status={
                EN_ATTENTE:'<span class="badge bg-warning-subtle text-warning">En attente</span>',
                VALIDE_LOCAL:'<span class="badge bg-success-subtle text-success">Validé local</span>',
                INTEGRE_REFERENTIEL:'<span class="badge bg-info-subtle text-info">Intégré national</span>',
                REFUSE:'<span class="badge bg-danger-subtle text-danger">Refusé</span>'
            }[x.validation_statut]||esc(x.validation_statut||'—');

            const decisions=x.validation_statut==='EN_ATTENTE'
                ?`<div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-success local-dep-review" data-id="${x.id}" data-action="APPROVE_LOCAL" title="Valider localement"><i class="bi bi-check2"></i></button>
                    <button class="btn btn-outline-primary local-dep-review" data-id="${x.id}" data-action="INTEGRATE_NATIONAL" title="Intégrer au national"><i class="bi bi-globe2"></i></button>
                    <button class="btn btn-outline-danger local-dep-review" data-id="${x.id}" data-action="REFUSE" title="Refuser"><i class="bi bi-x-lg"></i></button>
                  </div>`
                :'<span class="text-muted">Traité</span>';

            return `<tr>
                <td><strong>${esc(x.nom)}</strong><small class="d-block text-muted">${esc(x.code||'')}</small></td>
                <td><strong>${esc(x.etablissement_nom)}</strong><small class="d-block text-muted">${esc(x.type_etablissement_nom||'')}</small></td>
                <td>${esc(x.faculte_nom||'Directement sous l’établissement')}</td>
                <td>${status}${x.review_comment?`<small class="d-block text-muted mt-1">${esc(x.review_comment)}</small>`:''}</td>
                <td><small>${esc(x.motif_ajout||'—')}</small></td>
                <td class="text-center">${decisions}</td>
            </tr>`;
        }).join('')
        :'<tr><td colspan="6" class="text-center py-5 text-muted">Aucune demande locale.</td></tr>';

        document.querySelectorAll('.local-dep-review')
            .forEach(b=>b.onclick=()=>reviewLocalDepartment(Number(b.dataset.id),b.dataset.action));
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

async function reviewLocalDepartment(id,action){
    let comment='';

    if(action==='REFUSE'){
        comment=prompt('Motif du refus :')||'';
        if(!comment.trim())return;
    }else if(action==='INTEGRATE_NATIONAL'){
        if(!STAGIA.confirm('Intégrer ce département au référentiel national et le propager aux établissements concernés ?'))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }else{
        if(!STAGIA.confirm('Valider ce département uniquement pour cet établissement ?'))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }

    const fd=new FormData();
    fd.append('csrf','<?= $_SESSION['csrf'] ?>');
    fd.append('id',id);
    fd.append('action',action);
    fd.append('comment',comment);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/configuration/departement-local-review.php',
            fd
        );
        STAGIA.toast(r.message);
        await load();
        await loadLocalDepartmentRequests();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

$('localDepartmentStatus').onchange=loadLocalDepartmentRequests;
<?php endif; ?>

<?php if($entity==='UNIT'): ?>
async function loadLocalUnitRequests(){if(!TID){$('localUnitRequestsBody').innerHTML='<tr><td colspan="7" class="text-center py-5 text-muted">Sélectionnez un type.</td></tr>';return;}const q=new URLSearchParams({template_id:String(TID)});if($('localUnitStatus').value)q.set('status',$('localUnitStatus').value);try{const r=await STAGIA.request(`${BASE_URL}/actions/configuration/unite-locale-list.php?${q}`),list=r.data.items||[];$('localUnitRequestsBody').innerHTML=list.length?list.map(x=>{const st={EN_ATTENTE:'<span class="badge bg-warning-subtle text-warning">En attente</span>',VALIDE_LOCAL:'<span class="badge bg-success-subtle text-success">Validé local</span>',INTEGRE_REFERENTIEL:'<span class="badge bg-info-subtle text-info">Intégré national</span>',REFUSE:'<span class="badge bg-danger-subtle text-danger">Refusé</span>'}[x.validation_statut]||esc(x.validation_statut||'—');const d=x.validation_statut==='EN_ATTENTE'?`<div class="btn-group btn-group-sm"><button class="btn btn-outline-success lur" data-id="${x.id}" data-a="APPROVE_LOCAL"><i class="bi bi-check2"></i></button><button class="btn btn-outline-primary lur" data-id="${x.id}" data-a="INTEGRATE_NATIONAL"><i class="bi bi-globe2"></i></button><button class="btn btn-outline-danger lur" data-id="${x.id}" data-a="REFUSE"><i class="bi bi-x-lg"></i></button></div>`:'<span class="text-muted">Traité</span>';return `<tr><td><strong>${esc(x.nom)}</strong><small class="d-block text-muted">${esc(x.code||'')}</small></td><td>${esc(x.etablissement_nom)}</td><td>${esc(x.type_unite)}</td><td>${esc(x.parent_nom||'—')}</td><td>${st}${x.review_comment?`<small class="d-block text-muted">${esc(x.review_comment)}</small>`:''}</td><td><small>${esc(x.motif_ajout||'—')}</small></td><td class="text-center">${d}</td></tr>`;}).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune demande locale.</td></tr>';document.querySelectorAll('.lur').forEach(b=>b.onclick=()=>reviewLocalUnit(Number(b.dataset.id),b.dataset.a));}catch(e){STAGIA.toast(e.message,'danger');}}
async function reviewLocalUnit(id,action){let comment='';if(action==='REFUSE'){comment=prompt('Motif du refus :')||'';if(!comment.trim())return;}else if(action==='INTEGRATE_NATIONAL'){if(!STAGIA.confirm('Intégrer cette unité au référentiel national ?'))return;comment=prompt('Commentaire (facultatif) :')||'';}else{if(!STAGIA.confirm('Valider uniquement pour cet établissement ?'))return;comment=prompt('Commentaire (facultatif) :')||'';}const fd=new FormData();fd.append('csrf','<?= $_SESSION['csrf'] ?>');fd.append('id',id);fd.append('action',action);fd.append('comment',comment);try{const r=await STAGIA.post(BASE_URL+'/actions/configuration/unite-locale-review.php',fd);STAGIA.toast(r.message);await load();await loadLocalUnitRequests();}catch(e){STAGIA.toast(e.message,'danger');}}
$('localUnitStatus').onchange=loadLocalUnitRequests;
<?php endif; ?>

<?php if($entity==='OPTION'): ?>
async function loadLocalRequests(){
    if(!TID){$('localRequestsBody').innerHTML='<tr><td colspan="6" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr>';return;}
    const q=new URLSearchParams({template_id:String(TID)});
    if($('localStatus').value)q.set('status',$('localStatus').value);
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/configuration/option-local-list.php?${q}`);
        const list=r.data.items||[];
        $('localRequestsBody').innerHTML=list.length?list.map(x=>{
            const status={
                EN_ATTENTE:'<span class="badge bg-warning-subtle text-warning">En attente</span>',
                VALIDE_LOCAL:'<span class="badge bg-success-subtle text-success">Validé local</span>',
                INTEGRE_REFERENTIEL:'<span class="badge bg-info-subtle text-info">Intégré national</span>',
                REFUSE:'<span class="badge bg-danger-subtle text-danger">Refusé</span>'
            }[x.validation_statut]||`<span class="badge bg-light text-dark">${esc(x.validation_statut)}</span>`;
            const decisions=x.validation_statut==='EN_ATTENTE'
                ?`<div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-success local-review" data-id="${x.id}" data-action="APPROVE_LOCAL" title="Valider pour cet établissement"><i class="bi bi-check2"></i></button>
                    <button class="btn btn-outline-primary local-review" data-id="${x.id}" data-action="INTEGRATE_NATIONAL" title="Intégrer au référentiel national"><i class="bi bi-globe2"></i></button>
                    <button class="btn btn-outline-danger local-review" data-id="${x.id}" data-action="REFUSE" title="Refuser"><i class="bi bi-x-lg"></i></button>
                  </div>`
                :'<span class="text-muted">Traité</span>';
            return `<tr>
                <td><strong>${esc(x.nom)}</strong><small class="d-block text-muted">${esc(x.code||'')}</small></td>
                <td><strong>${esc(x.etablissement_nom)}</strong><small class="d-block text-muted">${esc(x.type_etablissement_nom||'')}</small></td>
                <td>${esc(x.filiere_nom)}</td>
                <td>${status}${x.review_comment?`<small class="d-block text-muted mt-1">${esc(x.review_comment)}</small>`:''}</td>
                <td><small>${esc(x.motif_ajout||'—')}</small></td>
                <td class="text-center">${decisions}</td>
            </tr>`;
        }).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucun ajout local pour ce type.</td></tr>';
        document.querySelectorAll('.local-review').forEach(b=>b.onclick=()=>reviewLocal(Number(b.dataset.id),b.dataset.action));
    }catch(e){STAGIA.toast(e.message,'danger');}
}
async function reviewLocal(id,action){
    let comment='';
    if(action==='REFUSE'){
        comment=prompt('Motif du refus :')||'';
        if(!comment.trim())return;
    }else if(action==='INTEGRATE_NATIONAL'){
        if(!STAGIA.confirm('Intégrer cette option au référentiel national et la propager aux établissements concernés ?'))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }else{
        if(!STAGIA.confirm('Valider cette option uniquement pour cet établissement ?'))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }
    const fd=new FormData();fd.append('csrf','<?= $_SESSION['csrf'] ?>');fd.append('id',id);fd.append('action',action);fd.append('comment',comment);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/configuration/option-local-review.php',fd);
        STAGIA.toast(r.message);await load();await loadLocalRequests();
    }catch(e){STAGIA.toast(e.message,'danger');}
}
$('localStatus').onchange=loadLocalRequests;
<?php endif; ?>

form.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('saveBtn'),true);
    try{const r=await STAGIA.post(BASE_URL+'/actions/configuration/modele-structure-save.php',form);modal.hide();STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}
};
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

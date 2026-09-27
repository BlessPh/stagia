<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/academic-structure.php';
require_once __DIR__.'/../../includes/academic-labels.php';

requirePermission($pdo,'academic.view');

$settings=$_SESSION['academic_settings']??[];
if(!contextAcademicEnabled() || empty($settings['promotion_active'])){
    http_response_code(403);
    exit('Les promotions ne sont pas activées pour cet établissement.');
}

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$activePage='promotions';
$canManage=hasPermission($pdo,'academic.manage');
$etablissementId=currentEtablissementId($pdo);
$labels=academicLabels($pdo,$etablissementId);
$promotionSingular=academicLabelFromMap($labels,'PROMOTION',false,'Promotion');
$promotionPlural=academicLabelFromMap($labels,'PROMOTION',true,'Promotions');
$programSingular=academicLabelFromMap($labels,'PROGRAM',false,'Filière / Programme');
$programPlural=academicLabelFromMap($labels,'PROGRAM',true,'Filières / Programmes');
$optionSingular=academicLabelFromMap($labels,'OPTION',false,'Option / Spécialité');

$pageTitle=$promotionPlural;

require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.promo-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.promo-kpi,.promo-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px}
.promo-kpi{padding:16px}.promo-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}
.promo-kpi strong{display:block;font-size:27px;margin-top:4px}.promo-card{overflow:hidden}
@media(max-width:900px){.promo-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.promo-kpis{grid-template-columns:1fr}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1><?= htmlspecialchars($promotionPlural) ?></h1>
        <p>Matérialisez les <?= htmlspecialchars(mb_strtolower($promotionPlural)) ?> réelles à partir de votre structure académique, des années académiques et des niveaux STAGIA.</p>
    </div>
    <?php if($canManage): ?>
    <button class="btn btn-primary-stagia" id="addBtn">
        <i class="bi bi-plus-lg me-1"></i> Créer <?= htmlspecialchars($promotionSingular) ?>
    </button>
    <?php endif; ?>
</div>

<div class="alert alert-info border-0 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Distinction importante :</strong>
    le <strong>niveau</strong> (L1, L2, D1, M1...) peut venir du référentiel national ou être ajouté localement par votre établissement.
    Ici, vous créez une <strong><?= htmlspecialchars(mb_strtolower($promotionSingular)) ?> annuelle</strong>
    sous la forme <strong><?= htmlspecialchars($programSingular) ?> + Année académique + Niveau</strong>.
    Si un niveau manque, cliquez sur <strong>Ajouter / modifier les niveaux</strong>.
</div>

<div class="promo-kpis mb-4">
    <div class="promo-kpi"><small>Total <?= htmlspecialchars(mb_strtolower($promotionPlural)) ?></small><strong id="kTotal">0</strong></div>
    <div class="promo-kpi"><small>Actives</small><strong id="kActive">0</strong></div>
    <div class="promo-kpi"><small>Étudiants en cours</small><strong id="kStudents">0</strong></div>
    <div class="promo-kpi"><small>Anciennes à normaliser</small><strong id="kLegacy">0</strong></div>
</div>

<div class="promo-card">
<div class="p-3 border-bottom">
<div class="row g-2">
    <div class="col-lg-4">
        <input id="search" class="form-control" placeholder="Rechercher <?= htmlspecialchars(mb_strtolower($promotionSingular)) ?>, <?= htmlspecialchars(mb_strtolower($programSingular)) ?>, niveau...">
    </div>
    <div class="col-lg-3">
        <select id="yearFilter" class="form-select">
            <option value="">Toutes les années</option>
        </select>
    </div>
    <div class="col-lg-3">
        <select id="programFilter" class="form-select">
            <option value="">Tous les <?= htmlspecialchars(mb_strtolower($programPlural)) ?></option>
        </select>
    </div>
    <div class="col-lg-2">
        <select id="statusFilter" class="form-select">
            <option value="">Tous</option>
            <option value="1">Actives</option>
            <option value="0">Inactives</option>
        </select>
    </div>
</div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th><?= htmlspecialchars(mb_strtoupper($promotionSingular)) ?></th>
    <th><?= htmlspecialchars(mb_strtoupper($programSingular)) ?></th>
    <th>ANNÉE</th>
    <th>NIVEAU</th>
    <th>OPTION</th>
    <th class="text-center">ÉTUDIANTS</th>
    <th>STATUT</th>
    <th class="text-end">ACTION</th>
</tr>
</thead>
<tbody id="rows">
<tr><td colspan="8" class="text-center py-5 text-muted">Chargement...</td></tr>
</tbody>
</table>
</div>
</div>
</main>

<?php if($canManage): ?>
<div class="modal fade" id="promoModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
<form id="promoForm">
<div class="modal-header">
    <div>
        <h5 class="modal-title" id="modalTitle">Créer <?= htmlspecialchars($promotionSingular) ?></h5>
        <small class="text-muted"><?= htmlspecialchars($programSingular) ?> + année académique + niveau STAGIA</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="id" id="promoId">

<div id="creationFields" class="row g-3">
    <div class="col-md-6">
        <label class="form-label"><?= htmlspecialchars($programSingular) ?> *</label>
        <select name="filiere_id" id="program" class="form-select" required></select>
    </div>

    <div class="col-md-6">
        <label class="form-label">Année académique</label>
        <input id="yearLabel" class="form-control" readonly>
        <input type="hidden" name="annee_academique_id" id="year">
        <small class="text-muted">Déterminée automatiquement par STAGIA.</small>
    </div>

    <div class="col-md-6">
        <div class="d-flex justify-content-between align-items-center">
            <label class="form-label mb-1">Niveau *</label>
            <?php if($canManage): ?>
            <button type="button" class="btn btn-link btn-sm p-0 mb-1" id="manageLevelsBtn">
                <i class="bi bi-sliders me-1"></i> Ajouter / modifier
            </button>
            <?php endif; ?>
        </div>
        <select name="academic_level_id" id="level" class="form-select" required></select>
        <small class="text-muted">Liste générée depuis le cursus sélectionné.</small>
    </div>

    <div class="col-md-6 d-none" id="optionWrap">
        <label class="form-label"><?= htmlspecialchars($optionSingular) ?></label>
        <select name="option_specialite_id" id="option" class="form-select"></select>
        <small class="text-muted">Ce champ apparaît uniquement si la structure sélectionnée possède des options / spécialités.</small>
    </div>
</div>

<div class="mt-3">
    <label class="form-label">Description</label>
    <textarea name="description" id="description" class="form-control" rows="3"></textarea>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="saveBtn">
        <i class="bi bi-check-lg me-1"></i> Enregistrer
    </button>
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
      PROMOTION_SINGULAR=<?= json_encode($promotionSingular,JSON_UNESCAPED_UNICODE) ?>,
      PROMOTION_PLURAL=<?= json_encode($promotionPlural,JSON_UNESCAPED_UNICODE) ?>,
      PROGRAM_SINGULAR=<?= json_encode($programSingular,JSON_UNESCAPED_UNICODE) ?>,
      PROGRAM_PLURAL=<?= json_encode($programPlural,JSON_UNESCAPED_UNICODE) ?>,
      CURRENT_YEAR=<?= (int)date('Y') ?>,
      CURRENT_ACADEMIC_LABEL='<?= date('Y').'-'.(date('Y')+1) ?>';

let items=[],filieres=[],annees=[],currentAnnee=null,options=[],levels=[],rules={},timer;
const modal=canManage?new bootstrap.Modal($('promoModal')):null;

function fillFilters(){
    const currentYear=$('yearFilter').value;
    const currentProgram=$('programFilter').value;

    $('yearFilter').innerHTML=
        '<option value="">Toutes les années</option>'+
        annees.map(x=>`<option value="${x.id}">${esc(x.libelle)}</option>`).join('');

    $('programFilter').innerHTML=
        `<option value="">Tous les ${esc(PROGRAM_PLURAL.toLowerCase())}</option>`+
        filieres.map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');

    $('yearFilter').value=currentYear;
    $('programFilter').value=currentProgram;
}

function fillCreation(){
    if(!canManage)return;

    $('program').innerHTML=
        '<option value="">Sélectionner...</option>'+
        filieres.map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');

    $('year').value=currentAnnee?String(currentAnnee.id):'';
    $('yearLabel').value=currentAnnee
        ?String(currentAnnee.libelle)
        :'Aucune année académique '+CURRENT_ACADEMIC_LABEL+' disponible';

    updateDependentFields();
}

function updateDependentFields(){
    if(!canManage)return;

    const fid=Number($('program').value||0);

    $('level').innerHTML=
        '<option value="">Sélectionner...</option>'+
        levels.filter(x=>Number(x.filiere_id)===fid)
              .map(x=>`<option value="${x.academic_level_id}">${esc(x.code)} — ${esc(x.libelle)} · ${esc(x.cycle_libelle)}</option>`)
              .join('');

    const rows=options.filter(x=>Number(x.filiere_id)===fid),
          optionEnabled=!!rules.option_enabled && rows.length>0,
          optionRequired=optionEnabled && !!rules.option_required;

    $('option').innerHTML=
        `<option value="">${optionRequired?'Sélectionner...':'Aucune'}</option>`+
        rows.map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');

    $('optionWrap').classList.toggle('d-none',!optionEnabled);
    $('option').required=optionRequired;
    if(!optionEnabled)$('option').value='';
}

function statusBadge(x){
    if(Number(x.legacy_incomplete)===1)
        return '<span class="badge bg-warning-subtle text-warning">À normaliser</span>';

    return Number(x.actif)===1
        ?'<span class="badge bg-success-subtle text-success">Active</span>'
        :'<span class="badge bg-secondary-subtle text-secondary">Inactive</span>';
}

async function load(){
    const q=new URLSearchParams();

    if($('search').value.trim())q.set('search',$('search').value.trim());
    if($('yearFilter').value)q.set('annee_academique_id',$('yearFilter').value);
    if($('programFilter').value)q.set('filiere_id',$('programFilter').value);
    if($('statusFilter').value!=='')q.set('actif',$('statusFilter').value);

    try{
        const r=await STAGIA.request(BASE_URL+'/actions/academique/promotion-list.php?'+q);
        const d=r.data;

        items=d.items||[];
        filieres=d.filieres||[];
        annees=d.annees||[];
        currentAnnee=d.current_annee||null;
        options=d.options||[];
        levels=d.levels||[];
        rules=d.rules||{};

        $('kTotal').textContent=d.kpi.total||0;
        $('kActive').textContent=d.kpi.actifs||0;
        $('kStudents').textContent=d.kpi.students||0;
        $('kLegacy').textContent=d.kpi.legacy||0;

        fillFilters();

        $('rows').innerHTML=items.length?items.map(x=>{
            const legacy=Number(x.legacy_incomplete)===1;
            const canToggle=canManage&&!legacy;
            return `<tr>
                <td>
                    <strong>${esc(x.nom)}</strong>
                    <small class="d-block text-muted">${esc(x.code||'—')}</small>
                </td>
                <td>
                    <strong>${esc(x.filiere_nom)}</strong>
                    <small class="d-block text-muted">${esc(x.curriculum_code||'')}</small>
                </td>
                <td>${esc(x.annee_libelle||'—')}</td>
                <td>
                    <strong>${esc(x.niveau_code||x.niveau||'—')}</strong>
                    <small class="d-block text-muted">${esc(x.niveau_libelle||'')}</small>
                </td>
                <td>${esc(x.option_nom||'—')}</td>
                <td class="text-center">
                    <span class="badge bg-light text-dark border">${Number(x.nb_etudiants_en_cours)||0}</span>
                </td>
                <td>${statusBadge(x)}</td>
                <td class="text-end">
                    ${canManage?`
                    <button class="btn btn-sm btn-outline-primary edit-btn" data-id="${x.id}" title="Description"><i class="bi bi-pencil"></i></button>
                    ${canToggle?`<button class="btn btn-sm btn-outline-secondary status-btn" data-id="${x.id}" data-next="${Number(x.actif)===1?0:1}" title="${Number(x.actif)===1?'Désactiver':'Activer'}"><i class="bi bi-${Number(x.actif)===1?'pause-circle':'play-circle'}"></i></button>`:''}
                    `:'—'}
                </td>
            </tr>`;
        }).join('')
        :`<tr><td colspan="8" class="text-center py-5 text-muted">Aucune ${esc(PROMOTION_SINGULAR.toLowerCase())}.</td></tr>`;

        document.querySelectorAll('.edit-btn')
            .forEach(b=>b.onclick=()=>edit(Number(b.dataset.id)));

        document.querySelectorAll('.status-btn')
            .forEach(b=>b.onclick=()=>changeStatus(Number(b.dataset.id),Number(b.dataset.next)));

    }catch(e){
        $('rows').innerHTML=`<tr><td colspan="8" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

if(canManage){
    $('program').onchange=updateDependentFields;

    if($('manageLevelsBtn'))$('manageLevelsBtn').onclick=()=>{
        const fid=$('program').value;
        if(!fid){
            STAGIA.toast('Sélectionnez d’abord '+PROGRAM_SINGULAR.toLowerCase()+'.','warning');
            return;
        }
        window.open(BASE_URL+'/views/academique/niveaux-academiques.php?filiere_id='+encodeURIComponent(fid),'_blank');
    };

    $('addBtn').onclick=()=>{
        $('promoForm').reset();
        $('promoId').value='';
        $('creationFields').style.display='flex';
        $('modalTitle').textContent='Créer '+<?= json_encode($promotionSingular,JSON_UNESCAPED_UNICODE) ?>;
        fillCreation();
        modal.show();
    };

    function edit(id){
        const x=items.find(v=>Number(v.id)===id);
        if(!x)return;

        $('promoForm').reset();
        $('promoId').value=x.id;
        $('creationFields').style.display='none';
        $('description').value=x.description||'';
        $('modalTitle').textContent='Modifier la description';
        modal.show();
    }

    $('promoForm').onsubmit=async e=>{
        e.preventDefault();

        if(!$('promoId').value && !$('year').value){
            STAGIA.toast(
                `Aucune année académique ${CURRENT_ACADEMIC_LABEL} active n'est configurée.`,
                'warning'
            );
            return;
        }

        const fid=Number($('program').value||0);
        const availableOptions=options.filter(x=>Number(x.filiere_id)===fid);

        if(
            !$('promoId').value &&
            !!rules.option_required &&
            !!rules.option_enabled &&
            availableOptions.length===0
        ){
            STAGIA.toast(
                PROGRAM_SINGULAR+' exige une option / spécialité, mais aucune n’est configurée.',
                'warning'
            );
            return;
        }

        STAGIA.loading($('saveBtn'),true);

        try{
            const fd=new FormData(e.currentTarget);
            const id=$('promoId').value;
            const url=id
                ?BASE_URL+'/actions/academique/promotion-update.php'
                :BASE_URL+'/actions/academique/promotion-store.php';

            const r=await STAGIA.post(url,fd);
            STAGIA.toast(r.message);
            modal.hide();
            await load();
        }catch(e){
            STAGIA.toast(e.message,'danger');
        }finally{
            STAGIA.loading($('saveBtn'),false);
        }
    };

    async function changeStatus(id,next){
        if(!STAGIA.confirm(next?'Activer '+PROMOTION_SINGULAR.toLowerCase()+' ?':'Désactiver '+PROMOTION_SINGULAR.toLowerCase()+' ?'))return;

        const fd=new FormData();
        fd.append('csrf','<?= $_SESSION['csrf'] ?>');
        fd.append('id',id);
        fd.append('actif',String(next));

        try{
            const r=await STAGIA.post(BASE_URL+'/actions/academique/promotion-status.php',fd);
            STAGIA.toast(r.message);
            await load();
        }catch(e){
            STAGIA.toast(e.message,'danger');
        }
    }
}

$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};
$('yearFilter').onchange=load;
$('programFilter').onchange=load;
$('statusFilter').onchange=load;

load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

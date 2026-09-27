<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/academic-structure.php';
require_once __DIR__.'/../../includes/academic-labels.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) exit('Aucun établissement associé.');
$cfg=academicSettings($pdo,$etablissementId);
if(!$cfg || !(int)$cfg['unite_academique_active']) exit("Le module Unités académiques n'est pas activé pour cet établissement.");
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
$types=academicUnitTypes($pdo,$etablissementId);
$labels=academicLabels($pdo,$etablissementId);
$types=array_values(array_filter($types,function($t)use($labels){
    $code=strtoupper(trim((string)($t['type_unite']??'')));
    return $code!==''&&academicEntityEnabled($labels,$code,true);
}));
if(!$types) exit("Aucun type d'unité académique n'est autorisé par la configuration STAGIA de cet établissement.");

$unitLabels=[];
foreach($types as $t){
    $code=strtoupper(trim((string)($t['type_unite']??'')));
    if($code!=='')$unitLabels[$code]=academicLabelFromMap($labels,$code,false,(string)($t['libelle']??$code));
}
$singleType=count($unitLabels)===1;
$singleCode=$singleType?(string)array_key_first($unitLabels):'';
$singleLabel=$singleType?(string)reset($unitLabels):'';
$defaultUnitPlural=$singleType?match($singleCode){
    'FACULTE'=>'Facultés','SECTION'=>'Sections','ECOLE'=>'Écoles',
    'INSTITUT'=>'Instituts','CENTRE'=>'Centres',default=>$singleLabel
}:'Unités académiques';
$pageUnitLabel=$singleType?academicLabelFromMap($labels,$singleCode,true,$defaultUnitPlural):academicLabelFromMap($labels,'UNIT',true,'Unités académiques');
$pageUnitSingular=$singleType?academicLabelFromMap($labels,$singleCode,false,$singleLabel):academicLabelFromMap($labels,'UNIT',false,'Unité académique');
$departmentPlural=academicLabelFromMap($labels,'DEPARTMENT',true,'Départements');

$pageTitle=$pageUnitLabel; $activePage='facultes';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><a href="index.php" class="detail-back"><i class="bi bi-arrow-left"></i> Organisation académique</a>
        <h1><?= htmlspecialchars($pageUnitLabel) ?></h1>
        <p>La structure et les appellations sont adaptées à l'organisation de votre établissement.</p></div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-light border" href="<?= BASE_URL ?>/views/academique/appellations.php"><i class="bi bi-pencil-square me-1"></i> Renommer les appellations</a>
        <button class="btn btn-primary-stagia px-4" onclick="nouvelleUnite()"><i class="bi bi-plus-lg me-1"></i> Ajouter <?= htmlspecialchars($pageUnitSingular) ?></button>
    </div>
</div>
<div class="alert alert-light border mb-3">
    <i class="bi bi-shield-check me-1 text-primary"></i>
    <strong>Configuration STAGIA :</strong> types autorisés :
    <strong><?= htmlspecialchars(implode(', ',array_values($unitLabels))) ?></strong>.
    Votre établissement ne peut pas créer un niveau académique non prévu par ce modèle ; il ajoute uniquement les unités réelles manquantes.
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>TOTAL UNITÉS</span><strong id="statTotal">0</strong><small>Unités enregistrées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-buildings"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIVES</span><strong id="statActifs">0</strong><small>Unités actives</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span>INACTIVES</span><strong id="statInactifs">0</strong><small>Unités désactivées</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-pause-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span><?= htmlspecialchars(mb_strtoupper($departmentPlural)) ?></span><strong id="statDepartements">0</strong><small><?= htmlspecialchars($departmentPlural) ?> enregistrés</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-diagram-3"></i></div></div>
</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div class="stagia-tabs">
            <button class="stagia-tab active" data-status="">Toutes <span id="countTous">0</span></button>
            <button class="stagia-tab" data-status="1">Actives <span id="countActifs">0</span></button>
            <button class="stagia-tab" data-status="0">Inactives <span id="countInactifs">0</span></button>
        </div>
        <div class="stagia-list-filters">
            <div class="input-group stagia-table-search"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                <input id="uniteSearch" type="search" class="form-control border-start-0" placeholder="Rechercher une unité..."></div>
            <select id="uniteTypeFilter" class="form-select"><option value="">Tous les types</option>
                <?php foreach($types as $t): $tc=strtoupper((string)$t['type_unite']); ?><option value="<?= htmlspecialchars($t['type_unite']) ?>"><?= htmlspecialchars($unitLabels[$tc]??$t['libelle']) ?></option><?php endforeach; ?>
            </select>
            <select id="unitePerPage" class="form-select stagia-per-page"><option>10</option><option>25</option><option>50</option></select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>CODE</th><th>TYPE</th><th><?= htmlspecialchars(mb_strtoupper($pageUnitSingular)) ?></th><th>PARENTE</th><th>ORIGINE</th><th>VALIDATION</th><th>DÉPART.</th><th>CRÉÉ LE</th><th class="text-center">ACTIONS</th></tr></thead>
            <tbody id="unitesBody"><tr><td colspan="9" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
        </table>
    </div>
    <div class="stagia-list-footer"><span id="uniteInfo">Affichage 0 sur 0</span><nav><ul id="unitePagination" class="pagination pagination-sm mb-0"></ul></nav></div>
</div>
</main>

<div class="modal fade" id="uniteModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="uniteForm">
    <div class="modal-header"><div><h5 id="uniteModalTitle" class="modal-title">Ajouter <?= htmlspecialchars($pageUnitSingular) ?></h5><small class="text-muted">Structure réelle de votre établissement.</small></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>"><input type="hidden" name="id" id="uniteId">
        <div class="mb-3">
            <label class="form-label">Type défini par STAGIA *</label>
            <?php if($singleType): ?>
                <input type="hidden" name="type_unite" id="uniteType" value="<?= htmlspecialchars($singleCode) ?>">
                <div class="form-control bg-light"><i class="bi bi-shield-check text-success me-1"></i><strong><?= htmlspecialchars($singleLabel) ?></strong></div>
            <?php else: ?>
                <select name="type_unite" id="uniteType" class="form-select" required>
                    <option value="">Sélectionner parmi les types autorisés...</option>
                    <?php foreach($types as $t): $tc=strtoupper((string)$t['type_unite']); ?><option value="<?= htmlspecialchars($t['type_unite']) ?>"><?= htmlspecialchars($unitLabels[$tc]??$t['libelle']) ?></option><?php endforeach; ?>
                </select>
                <small class="text-muted">Cette liste vient du modèle STAGIA de votre établissement.</small>
            <?php endif; ?>
        </div>
        <div class="mb-3"><label class="form-label">Nom de <?= htmlspecialchars(mb_strtolower($pageUnitSingular)) ?> *</label><input name="nom" id="uniteNom" class="form-control" placeholder="Ex. <?= htmlspecialchars($pageUnitSingular) ?> de Médecine" required>
            <small class="text-muted">Le code est généré automatiquement par STAGIA.</small></div>
        <div class="form-check form-switch mb-3" id="parentQuestionWrap">
            <input class="form-check-input" type="checkbox" id="uniteHasParent">
            <label class="form-check-label" for="uniteHasParent"><strong>Cette structure a un parent</strong></label>
            <small class="d-block text-muted">Décochez si elle est directement rattachée à l’établissement.</small>
        </div>
        <div id="parentWrap" class="mb-3 d-none"><label class="form-label">Choisir le parent *</label>
            <select name="parent_id" id="uniteParent" class="form-select"><option value="">Sélectionner...</option></select>
        </div>
        <div><label class="form-label">Pourquoi cette unité manque-t-elle au référentiel ? *</label><textarea name="motif_ajout" id="uniteMotif" class="form-control" rows="2" maxlength="500" required></textarea></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary-stagia" id="uniteSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button></div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),form=$('uniteForm'),modal=new bootstrap.Modal($('uniteModal'));
let page=1,perPage=10,search='',statut='',type='',timer=null,items=[],parents=[],parentAllowed=<?= (int)$cfg['unite_parentale_autorisee'] ?>===1;
const DEFAULT_PARENT=<?= academicParentEnabled($labels,$singleCode?:'UNIT',false)?'true':'false' ?>;
const TYPE_LABELS=<?= json_encode($unitLabels,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
      SINGLE_TYPE=<?= $singleType?'true':'false' ?>,
      SINGLE_CODE=<?= json_encode($singleCode,JSON_UNESCAPED_UNICODE) ?>;
const typeLabel=t=>TYPE_LABELS[String(t||'').toUpperCase()]||t;

function toggleParent(){
    const checked=parentAllowed&&$('uniteHasParent').checked;
    $('parentWrap').classList.toggle('d-none',!checked);
    $('uniteParent').required=checked;
    if(!checked)$('uniteParent').value='';
}
function fillParents(currentId=0,selected=''){
    $('parentQuestionWrap').classList.toggle('d-none',!parentAllowed);
    const opts=parents.filter(p=>Number(p.actif)===1&&Number(p.id)!==Number(currentId))
        .map(p=>`<option value="${p.id}" ${String(p.id)===String(selected)?'selected':''}>${STAGIA.escape(p.nom)} · ${STAGIA.escape(typeLabel(p.type_unite))}</option>`).join('');
    $('uniteParent').innerHTML='<option value="">Sélectionner...</option>'+opts;
    $('uniteHasParent').checked=parentAllowed&&(selected?true:DEFAULT_PARENT);
    toggleParent();
}
$('uniteHasParent').onchange=toggleParent;
async function charger(p=1){
    const body=$('unitesBody'); body.innerHTML='<tr><td colspan="9" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr>';
    try{
        const q=new URLSearchParams({page:p,per_page:perPage,search,statut,type}),r=await STAGIA.request(BASE_URL+'/actions/academique/faculte-list.php?'+q);
        items=r.data.items||[]; parents=r.data.form?.parents||[]; parentAllowed=!!r.data.form?.parent_allowed; const pg=r.data.pagination||{},s=r.data.stats||{}; page=Number(pg.page||1);
        $('statTotal').textContent=s.total||0; $('statActifs').textContent=s.actifs||0; $('statInactifs').textContent=s.inactifs||0; $('statDepartements').textContent=s.departements||0;
        $('countTous').textContent=s.total||0; $('countActifs').textContent=s.actifs||0; $('countInactifs').textContent=s.inactifs||0;
        $('uniteInfo').textContent=pg.total?`Affichage ${pg.from}–${pg.to} sur ${pg.total}`:'Aucun résultat';
        if(!items.length){body.innerHTML='<tr><td colspan="9" class="text-center py-5 text-muted"><i class="bi bi-search fs-2 d-block mb-2"></i>Aucun élément académique trouvé.</td></tr>'; return pagination(pg);}
        body.innerHTML=items.map(u=>`<tr>
            <td><strong>${STAGIA.escape(u.code||'-')}</strong></td><td><span class="badge bg-light text-dark border">${STAGIA.escape(typeLabel(u.type_unite))}</span></td>
            <td><strong class="table-main-text">${STAGIA.escape(u.nom)}</strong>${Number(u.nb_enfants||0)?`<small class="d-block text-muted">${u.nb_enfants} sous-unité(s)</small>`:''}</td>
            <td>${STAGIA.escape(u.parent_nom||'—')}</td>
            <td>${Number(u.ajoute_localement)===1?'<span class="badge bg-light text-dark border">Ajout local</span>':'<span class="badge bg-primary">STAGIA national</span>'}</td>
            <td>${({NATIONAL:'<span class="badge bg-primary-subtle text-primary">National</span>',EN_ATTENTE:'<span class="badge bg-warning-subtle text-warning">En attente</span>',VALIDE_LOCAL:'<span class="badge bg-success-subtle text-success">Validé local</span>',INTEGRE_REFERENTIEL:'<span class="badge bg-info-subtle text-info">Intégré national</span>',REFUSE:'<span class="badge bg-danger-subtle text-danger">Refusé</span>'})[u.validation_statut]||STAGIA.escape(u.validation_statut||'—')}${u.review_comment?`<small class="d-block text-muted">${STAGIA.escape(u.review_comment)}</small>`:''}</td>
            <td><span class="badge bg-light text-dark border">${Number(u.nb_departements||0)}</span></td><td>${STAGIA.escape(u.date_creation||'-')}</td>
            <td class="text-center">${Number(u.ajoute_localement)===1&&['EN_ATTENTE','VALIDE_LOCAL'].includes(u.validation_statut)?`<button class="btn btn-sm btn-outline-primary btn-action btn-edit" data-id="${u.id}" title="Modifier"><i class="bi bi-pencil"></i></button>`:'<span class="text-muted">—</span>'}</td></tr>`).join('');
        body.querySelectorAll('.btn-edit').forEach(b=>b.onclick=()=>modifier(items.find(x=>Number(x.id)===Number(b.dataset.id)))); pagination(pg);
    }catch(e){body.innerHTML=`<tr><td colspan="9" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`; STAGIA.toast(e.message,'danger');}
}
function pagination(p){
    const el=$('unitePagination'),cur=Number(p.page||1),pages=Number(p.pages||1); if(pages<=1){el.innerHTML='';return;}
    let h=`<li class="page-item ${cur<=1?'disabled':''}"><button class="page-link" data-page="${cur-1}">‹</button></li>`;
    for(let i=Math.max(1,cur-2);i<=Math.min(pages,cur+2);i++) h+=`<li class="page-item ${i===cur?'active':''}"><button class="page-link" data-page="${i}">${i}</button></li>`;
    h+=`<li class="page-item ${cur>=pages?'disabled':''}"><button class="page-link" data-page="${cur+1}">›</button></li>`; el.innerHTML=h;
    el.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{const n=Number(b.dataset.page);if(n>=1&&n<=pages)charger(n);});
}
window.nouvelleUnite=()=>{form.reset();$('uniteId').value='';$('uniteModalTitle').textContent='Ajouter '+<?= json_encode($pageUnitSingular,JSON_UNESCAPED_UNICODE) ?>;if(SINGLE_TYPE)$('uniteType').value=SINGLE_CODE;fillParents();modal.show();};
function modifier(u){if(!u)return;const code=String(u.type_unite||'').toUpperCase();if(!TYPE_LABELS[code]){STAGIA.toast("Ce type n'est plus autorisé par la configuration STAGIA.",'danger');return;}form.reset();$('uniteId').value=u.id;$('uniteType').value=code;$('uniteNom').value=u.nom||'';$('uniteMotif').value=u.motif_ajout||'';$('uniteModalTitle').textContent="Modifier l'ajout local";fillParents(u.id,u.parent_id||'');modal.show();}
form.onsubmit=async e=>{e.preventDefault();STAGIA.loading($('uniteSaveBtn'),true);try{
    const url=$('uniteId').value?BASE_URL+'/actions/academique/faculte-update.php':BASE_URL+'/actions/academique/faculte-store.php';
    const r=await STAGIA.post(url,form);modal.hide();form.reset();STAGIA.toast(r.message);await charger(page);
}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('uniteSaveBtn'),false);}};
$('uniteSearch').oninput=e=>{clearTimeout(timer);timer=setTimeout(()=>{search=e.target.value.trim();charger(1);},300);};
$('uniteTypeFilter').onchange=e=>{type=e.target.value;charger(1);}; $('unitePerPage').onchange=e=>{perPage=Number(e.target.value);charger(1);};
document.querySelectorAll('.stagia-tab').forEach(t=>t.onclick=()=>{document.querySelectorAll('.stagia-tab').forEach(x=>x.classList.remove('active'));t.classList.add('active');statut=t.dataset.status;charger(1);});
charger();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

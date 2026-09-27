<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
if(!contextAcademicEnabled()){http_response_code(403);exit("Cette page est réservée aux établissements académiques.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Grilles d’évaluation';
$activePage='evaluation-grids';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.grid-card{background:#fff;border:1px solid #e6e9ef;border-radius:16px}
.grid-status{font-size:.78rem;font-weight:700}
.criterion-row td{vertical-align:middle}
.criterion-new{background:#fff8f1}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
  <div>
    <h1>Grilles d’évaluation</h1>
    <p>Configurez le barème par promotion afin que le Maître de stage reçoive automatiquement la bonne fiche de cotation.</p>
  </div>
</div>

<div id="migrationAlert" class="alert alert-warning d-none">
  <strong>Migration requise.</strong> Exécutez <code>20260923_grilles_evaluation_promotion.sql</code> avant d’utiliser cette page.
</div>

<div class="grid-card p-3 mb-3">
  <div class="row g-3 align-items-end">
    <div class="col-lg-3"><label class="form-label">Année académique</label><select id="yearId" class="form-select"></select></div>
    <div class="col-lg-3"><label class="form-label">Type de stage</label><select id="stageTypeId" class="form-select"></select></div>
    <div class="col-lg-3"><label class="form-label">Promotion</label><select id="promotionId" class="form-select"></select></div>
    <div class="col-lg-3"><button id="loadBtn" class="btn btn-primary-stagia w-100"><i class="bi bi-arrow-repeat me-1"></i>Charger la grille</button></div>
  </div>
</div>

<div id="editor" class="d-none">
  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="grid-card p-3"><small class="text-muted">STATUT</small><h4 id="kStatus" class="mb-0">—</h4></div></div>
    <div class="col-md-4"><div class="grid-card p-3"><small class="text-muted">CRITÈRES</small><h4 id="kCriteria" class="mb-0">0</h4></div></div>
    <div class="col-md-4"><div class="grid-card p-3"><small class="text-muted">TOTAL DU BARÈME</small><h4 id="kTotal" class="mb-0">0</h4></div></div>
  </div>

  <div class="grid-card p-3 mb-3">
    <div class="row g-3">
      <div class="col-lg-7">
        <label class="form-label">Nom de la grille</label>
        <input id="gridLabel" class="form-control" placeholder="Ex. Fiche de cotation D4 Médecine">
      </div>
      <div class="col-lg-5">
        <label class="form-label">Copier depuis une autre promotion</label>
        <div class="input-group">
          <select id="copySource" class="form-select"><option value="">Choisir...</option></select>
          <button id="copyBtn" class="btn btn-outline-secondary" type="button">Copier</button>
        </div>
      </div>
    </div>
  </div>

  <div class="grid-card overflow-hidden mb-3">
    <div class="p-3 border-bottom d-flex flex-wrap justify-content-between gap-2">
      <div><h5 class="mb-1">Critères de cotation</h5><small class="text-muted">La note maximale définit directement le barème : /12, /8, /6, /3…</small></div>
      <button id="selectAllBtn" type="button" class="btn btn-sm btn-light border">Tout sélectionner</button>
    </div>
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead class="table-light"><tr>
          <th style="width:55px"></th><th>Critère</th><th style="width:210px">Section</th>
          <th style="width:130px">Note max</th><th style="width:120px">Obligatoire</th>
        </tr></thead>
        <tbody id="criteriaBody"></tbody>
      </table>
    </div>
  </div>

  <div class="grid-card p-3 mb-3">
    <h6><i class="bi bi-plus-circle me-1"></i>Ajouter un nouveau critère local</h6>
    <div class="row g-2 align-items-end">
      <div class="col-lg-4"><label class="form-label">Libellé</label><input id="newName" class="form-control" placeholder="Ex. Esprit d’initiative"></div>
      <div class="col-lg-2"><label class="form-label">Catégorie</label>
        <select id="newCategory" class="form-select">
          <option>CLINIQUE</option><option>TECHNIQUE</option><option>COMMUNICATION</option>
          <option>ETHIQUE</option><option>PROFESSIONNALISME</option><option>ORGANISATION</option>
        </select>
      </div>
      <div class="col-lg-3"><label class="form-label">Section</label><input id="newSection" class="form-control" placeholder="Ex. Pratiques professionnelles"></div>
      <div class="col-lg-1"><label class="form-label">/ Max</label><input id="newMax" type="number" min=".01" step=".5" class="form-control" value="5"></div>
      <div class="col-lg-2"><button id="addCriterionBtn" class="btn btn-outline-primary w-100" type="button">Ajouter</button></div>
    </div>
  </div>

  <div class="d-flex justify-content-end">
    <button id="saveBtn" class="btn btn-primary-stagia px-4"><i class="bi bi-check2-circle me-1"></i>Enregistrer la grille</button>
  </div>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',CSRF='<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
let data={promotions:[],years:[],stage_types:[],criteria:[],sources:[]},criteria=[],newSeq=0;

function option(items,empty='Choisir...'){return `<option value="">${empty}</option>`+items.map(x=>`<option value="${x.id}">${esc(x.label||('#'+x.id))}</option>`).join('')}
function mapBy(list){return Object.fromEntries((list||[]).map(x=>[String(x.id),x.label||('#'+x.id)]));}

async function baseLoad(){
  const r=await STAGIA.request(BASE_URL+'/actions/stages/evaluation-grid-data.php');data=r.data||data;
  $('migrationAlert').classList.toggle('d-none',!!data.migration_ready);
  $('yearId').innerHTML=option(data.years,'Année académique...');
  $('stageTypeId').innerHTML=option(data.stage_types,'Type de stage...');
  $('promotionId').innerHTML=option(data.promotions,'Promotion...');
  renderSources();
}
function renderSources(){
  const pm=mapBy(data.promotions),ym=mapBy(data.years),tm=mapBy(data.stage_types);
  $('copySource').innerHTML='<option value="">Choisir...</option>'+
    (data.sources||[]).map(x=>`<option value="${x.id}">${esc(pm[x.promotion_id]||('Promotion #'+x.promotion_id))} • ${esc(tm[x.stage_type_id]||('Type #'+x.stage_type_id))} • ${esc(ym[x.annee_academique_id]||('Année #'+x.annee_academique_id))}</option>`).join('');
}
async function loadGrid(){
  const p=$('promotionId').value,y=$('yearId').value,t=$('stageTypeId').value;
  if(!p||!y||!t)return STAGIA.toast('Choisissez l’année, le type de stage et la promotion.','warning');
  const qs=new URLSearchParams({promotion_id:p,annee_academique_id:y,stage_type_id:t});
  const r=await STAGIA.request(BASE_URL+'/actions/stages/evaluation-grid-data.php?'+qs.toString());
  data={...data,...(r.data||{})};criteria=(data.criteria||[]).map(x=>({...x,selected:Number(x.selected||0)===1}));
  $('editor').classList.remove('d-none');$('gridLabel').value=data.referential?.libelle||'';
  renderSources();renderCriteria();
}
function renderCriteria(){
  $('criteriaBody').innerHTML=criteria.length?criteria.map((c,i)=>`<tr class="criterion-row ${c.is_new?'criterion-new':''}" data-i="${i}">
    <td><input class="form-check-input c-select" type="checkbox" ${c.selected?'checked':''}></td>
    <td><strong>${esc(c.nom||'-')}</strong><div class="small text-muted">${esc(c.categorie||'')} ${c.is_new?'• nouveau critère local':''}</div></td>
    <td><input class="form-control form-control-sm c-section" value="${esc(c.section||'')}" placeholder="Section"></td>
    <td><input type="number" min=".01" max="100" step=".5" class="form-control form-control-sm c-max" value="${Number(c.note_max||5)}"></td>
    <td class="text-center"><input class="form-check-input c-required" type="checkbox" ${Number(c.obligatoire??1)?'checked':''}></td>
  </tr>`).join(''):'<tr><td colspan="5" class="text-center py-4 text-muted">Aucun critère disponible.</td></tr>';
  updateKpi();
}
function sync(){
  document.querySelectorAll('#criteriaBody tr[data-i]').forEach(tr=>{
    const c=criteria[Number(tr.dataset.i)];if(!c)return;
    c.selected=tr.querySelector('.c-select').checked;
    c.section=tr.querySelector('.c-section').value.trim();
    c.note_max=Number(tr.querySelector('.c-max').value||0);
    c.obligatoire=tr.querySelector('.c-required').checked?1:0;
  });
}
function updateKpi(){
  sync();
  const chosen=criteria.filter(x=>x.selected),total=chosen.reduce((s,x)=>s+Number(x.note_max||0),0);
  $('kCriteria').textContent=chosen.length;$('kTotal').textContent=total.toLocaleString('fr-FR')+' pts';
  $('kStatus').innerHTML=chosen.length?'<span class="text-success">CONFIGURÉE</span>':'<span class="text-warning">À COMPLÉTER</span>';
}
$('criteriaBody').addEventListener('input',updateKpi);$('criteriaBody').addEventListener('change',updateKpi);

$('addCriterionBtn').addEventListener('click',()=>{
  const nom=$('newName').value.trim(),max=Number($('newMax').value||0);
  if(!nom||max<=0)return STAGIA.toast('Saisissez le libellé et une note maximale valide.','warning');
  criteria.push({competency_id:0,id:0,local_key:'new-'+(++newSeq),nom,categorie:$('newCategory').value,section:$('newSection').value.trim(),note_max:max,obligatoire:1,selected:true,is_new:true});
  $('newName').value='';$('newSection').value='';$('newMax').value='5';renderCriteria();
});

$('selectAllBtn').addEventListener('click',()=>{criteria.forEach(x=>x.selected=true);renderCriteria()});
$('loadBtn').addEventListener('click',loadGrid);

$('copyBtn').addEventListener('click',async()=>{
  const id=$('copySource').value;if(!id)return;
  try{
    const r=await STAGIA.request(BASE_URL+'/actions/stages/evaluation-grid-copy.php?config_id='+encodeURIComponent(id));
    const copied=r.data.criteria||[];
    if(!copied.length)return STAGIA.toast('La grille source est vide.','warning');
    criteria=copied.map(x=>({...x,selected:true,competency_id:Number(x.id)}));
    renderCriteria();STAGIA.toast('Grille copiée. Vous pouvez l’adapter avant enregistrement.');
  }catch(e){STAGIA.toast(e.message,'danger')}
});

$('saveBtn').addEventListener('click',async()=>{
  sync();
  const chosen=criteria.filter(x=>x.selected);
  if(!chosen.length)return STAGIA.toast('Sélectionnez au moins un critère.','warning');
  const fd=new FormData();
  fd.append('csrf',CSRF);fd.append('promotion_id',$('promotionId').value);
  fd.append('annee_academique_id',$('yearId').value);fd.append('stage_type_id',$('stageTypeId').value);
  fd.append('libelle',$('gridLabel').value.trim());
  fd.append('criteria',JSON.stringify(chosen.map(x=>({
    selected:1,competency_id:Number(x.competency_id||x.id||0),nom:x.nom||'',
    categorie:x.categorie||'CLINIQUE',section:x.section||'',note_max:Number(x.note_max||0),
    obligatoire:Number(x.obligatoire??1)
  }))));
  try{
    STAGIA.loading($('saveBtn'),true);
    const r=await STAGIA.post(BASE_URL+'/actions/stages/evaluation-grid-save.php',fd);
    STAGIA.toast(r.message||'Grille enregistrée.');
    await baseLoad();await loadGrid();
  }catch(e){STAGIA.toast(e.message,'danger')}
  finally{STAGIA.loading($('saveBtn'),false)}
});

baseLoad();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
requirePermission($pdo,'campaign.university.view');

if(!contextAcademicEnabled()){http_response_code(403);exit("Cet espace n'est pas configuré pour gérer des sessions de stage.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$eid=(int)($_SESSION['etablissement_id']??0);$hospitals=[];
if($eid){
    $s=$pdo->prepare("SELECT id,nom FROM etablissements WHERE id<>? AND type_etablissement='HOPITAL' ORDER BY nom");
    $s->execute([$eid]);$hospitals=$s->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle='Sessions de stage';$activePage='stages-campagnes';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.campaign-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.campaign-kpi,.campaign-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px}.campaign-kpi{padding:16px}.campaign-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.campaign-kpi strong{display:block;font-size:27px;margin-top:4px}.campaign-card{overflow:hidden}
.promo-checks,.hospital-checks{max-height:250px;overflow:auto;border:1px solid #dee2e6;border-radius:10px;padding:10px}.hospital-demand{width:120px;flex:0 0 120px}
.finance-box{background:#fffaf5;border:1px solid #fed7aa;border-radius:12px;padding:14px}.stage-type-create{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:14px}.stage-type-levels{max-height:150px;overflow:auto;border:1px solid #dee2e6;border-radius:10px;padding:8px;background:#fff}
#campaignModal .modal-dialog{height:calc(100vh - 24px);max-height:calc(100vh - 24px);margin:12px auto}
#campaignModal .modal-content{height:100%;max-height:100%;overflow:hidden}#campaignModal form{height:100%;min-height:0;display:flex;flex-direction:column}
#campaignModal .modal-header,#campaignModal .modal-footer{flex:0 0 auto;background:#fff;z-index:5}
#campaignModal .modal-body{flex:1 1 auto;min-height:0;overflow-y:auto!important;overflow-x:hidden;scrollbar-gutter:stable;padding-bottom:30px}
#campaignModal .modal-footer{border-top:1px solid #dee2e6;box-shadow:0 -6px 18px rgba(15,23,42,.08)}
.filiere-title{font-size:13px;color:#f97316;background:#fff7ed;border-radius:7px;padding:7px 9px;margin:5px 0}
@media(max-width:900px){.campaign-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.campaign-kpis{grid-template-columns:1fr}}
@media(max-width:767.98px){#campaignModal .modal-dialog{height:100vh;max-height:100vh;margin:0}#campaignModal .modal-content{border-radius:0}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Sessions de stage</h1><p>Créez les stages, ciblez les promotions et sollicitez les structures d'accueil.</p></div>
    <button class="btn btn-primary-stagia d-none" id="addBtn"><i class="bi bi-plus-lg me-1"></i>Lancer une session</button>
</div>

<div class="alert alert-light border mb-3"><i class="bi bi-info-circle text-primary me-1"></i>Le comportement d'une session dépend du <strong>type de stage</strong> et de ses politiques STAGIA.</div>

<div class="campaign-kpis mb-4">
    <div class="campaign-kpi"><small>Total</small><strong id="kTotal">0</strong></div>
    <div class="campaign-kpi"><small>Brouillons</small><strong id="kDraft">0</strong></div>
    <div class="campaign-kpi"><small>En préparation</small><strong id="kPreparing">0</strong></div>
    <div class="campaign-kpi"><small>Ouvertes</small><strong id="kOpen">0</strong></div>
</div>

<div class="campaign-card">
<div class="p-3 border-bottom"><div class="row g-2">
    <div class="col-lg-4"><input id="search" class="form-control" placeholder="Rechercher une session..."></div>
    <div class="col-lg-3"><select id="typeFilter" class="form-select"><option value="">Tous les types</option></select></div>
    <div class="col-lg-3"><select id="yearFilter" class="form-select"><option value="">Toutes les années</option></select></div>
    <div class="col-lg-2"><select id="statusFilter" class="form-select">
        <option value="">Tous les statuts</option><option value="BROUILLON">Brouillon</option><option value="EN_PREPARATION">En préparation</option><option value="OUVERTE">Ouverte</option><option value="CLOTUREE">Clôturée</option><option value="TERMINEE">Terminée</option><option value="ANNULEE">Annulée</option>
    </select></div>
</div></div>

<div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>SESSION</th><th>TYPE / ANNÉE</th><th>PÉRIODE</th><th>ÉLIGIBILITÉ</th><th>FINANCE</th><th>ACCUEIL</th><th>STATUT</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="rows"><tr><td colspan="8" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table></div>
</div>
</main>

<div class="modal fade" id="campaignModal" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
<form id="campaignForm">
<div class="modal-header">
    <div><h5 class="modal-title" id="modalTitle">Lancer une session</h5><small class="text-muted">Enregistrement et publication en une seule étape.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
<input type="hidden" name="id" id="campaignId">

<div class="row g-3">
    <div class="col-md-5"><label class="form-label">Titre *</label><input name="titre" id="title" class="form-control" maxlength="200" required></div>

    <div class="col-md-4">
        <label class="form-label">Type de stage *</label>
        <div class="input-group">
            <select name="stage_type_id" id="stageType" class="form-select" required></select>
            <button type="button" class="btn btn-outline-primary" id="newStageTypeBtn"><i class="bi bi-plus-lg"></i></button>
        </div>
        <small class="text-muted">Les types locaux sont propres à votre établissement.</small>
    </div>

    <div class="col-md-3"><label class="form-label">Année académique *</label><select name="annee_academique_id" id="year" class="form-select" required></select></div>

    <div class="col-12 d-none" id="newStageTypePanel">
        <div class="stage-type-create">
            <div class="d-flex justify-content-between mb-3">
                <div><strong>Nouveau type de stage</strong><small class="d-block text-muted">Propre à votre établissement.</small></div>
                <button type="button" class="btn-close" id="closeStageTypePanel"></button>
            </div>

            <div class="row g-3">
                <div class="col-md-5"><label class="form-label">Libellé *</label><input id="newStageTypeLabel" class="form-control" maxlength="150"></div>
                <div class="col-md-7"><label class="form-label">Description</label><input id="newStageTypeDescription" class="form-control" maxlength="500"></div>

                <div class="col-12"><div class="finance-box">
                    <label class="form-label fw-semibold">Condition financière *</label>
                    <div class="d-flex gap-4 flex-wrap">
                        <label class="form-check"><input class="form-check-input new-type-finance" type="radio" name="quick_type_finance" id="newTypeFree" value="GRATUIT" checked> Gratuit</label>
                        <label class="form-check"><input class="form-check-input new-type-finance" type="radio" name="quick_type_finance" id="newTypePaid" value="PAYANT"> Payant</label>
                    </div>
                    <div class="row g-2 mt-1 d-none" id="newTypeFinancialFields">
                        <div class="col-md-6"><input type="number" min=".01" step=".01" id="newTypeAmount" class="form-control" placeholder="Montant"></div>
                        <div class="col-md-6"><select id="newTypeCurrency" class="form-select"><option>USD</option><option>CDF</option><option>EUR</option></select></div>
                    </div>
                </div></div>

                <div class="col-12"><label class="form-label">Niveaux éligibles</label><div class="stage-type-levels" id="newStageTypeLevels"></div></div>
                <div class="col-12 text-end">
                    <button type="button" class="btn btn-light border" id="cancelStageTypeBtn">Annuler</button>
                    <button type="button" class="btn btn-outline-primary" id="saveStageTypeBtn"><i class="bi bi-plus-circle me-1"></i>Créer</button>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <label class="form-label">Objectif du stage <span class="text-muted fw-normal">(facultatif)</span></label>
        <select name="objectif_stage" id="objective" class="form-select">
            <option value="">Aucun objectif</option>
        </select>
        <small class="text-muted">Le choix est chargé automatiquement selon le type de stage sélectionné.</small>
    </div>

    <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="description" class="form-control" rows="2"></textarea></div>

    <div class="col-md-3"><label class="form-label">Ouverture candidatures *</label><input type="datetime-local" name="ouverture_candidatures" id="applicationsOpen" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">Clôture candidatures *</label><input type="datetime-local" name="cloture_candidatures" id="applicationsClose" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">Début du stage *</label><input type="date" name="date_debut" id="startDate" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label">Fin du stage *</label><input type="date" name="date_fin" id="endDate" class="form-control" required></div>

    <div class="col-lg-7">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
            <div>
                <label class="form-label mb-0">Promotions éligibles *</label>
                <small class="d-block text-muted" id="promotionFilterHint">
                    Choisissez d’abord le type de stage.
                </small>
            </div>

            <div class="form-check form-switch flex-shrink-0">
                <input class="form-check-input" type="checkbox" id="showAllPromotions">
                <label class="form-check-label small" for="showAllPromotions">
                    Toutes les promotions
                </label>
            </div>
        </div>

        <div class="promo-checks" id="promotionChecks">
            <div class="text-muted small">Chargement...</div>
        </div>

        <small class="text-muted">
            Les niveaux restent séparés. La liste affiche uniquement les promotions.
        </small>
    </div>

    <div class="col-lg-5">
        <label class="form-label">Pièces demandées</label>
        <textarea name="requirements_text" id="requirements" class="form-control" rows="8" placeholder="Une pièce par ligne"></textarea>
        <small class="text-muted">Une ligne = une pièce obligatoire.</small>
    </div>

    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div><label class="form-label mb-0">Hôpitaux à solliciter</label><small class="d-block text-muted" id="hospitalHint">Sélection facultative.</small></div>
            <div><button type="button" class="btn btn-sm btn-link" id="hospitalAll">Tout sélectionner</button><button type="button" class="btn btn-sm btn-link text-secondary" id="hospitalNone">Aucun</button></div>
        </div>

        <input type="search" id="hospitalSearch" class="form-control mb-2" placeholder="Rechercher un hôpital...">

        <div class="hospital-checks" id="hospitalChecks">
        <?php if($hospitals):foreach($hospitals as $h): ?>
            <label class="d-flex align-items-center gap-2 py-2 border-bottom hospital-row">
                <input class="form-check-input hospital-choice" type="checkbox" name="host_ids[]" value="<?=$h['id']?>">
                <span class="flex-grow-1"><i class="bi bi-hospital me-1 text-primary"></i><strong><?=htmlspecialchars($h['nom'])?></strong></span>
                <input type="number" min="1" class="form-control form-control-sm hospital-demand" name="host_capacity[<?=$h['id']?>]" placeholder="Places" disabled>
            </label>
        <?php endforeach;else: ?>
            <div class="text-muted small py-2">Aucun hôpital enregistré.</div>
        <?php endif; ?>
        </div>
    </div>

    <div class="col-12"><div class="finance-box">
        <h6 class="mb-2"><i class="bi bi-cash-coin me-1"></i>Condition financière héritée</h6>
        <div id="typeFinanceSummary" class="text-muted">Sélectionnez un type de stage.</div>
    </div></div>
</div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia px-4" id="saveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer et publier</button>
</div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('campaignModal'));
let items=[],types=[],years=[],units=[],filieres=[],promotions=[],permissions={},timer;
const hospitalChoices=()=>[...document.querySelectorAll('.hospital-choice')],hospitalDemand=h=>h.closest('.hospital-row').querySelector('.hospital-demand');

const truthy=v=>v===true||v===1||v==='1'||['true','yes','oui','on'].includes(String(v??'').toLowerCase());
const localDateTime=v=>v?String(v).replace(' ','T').slice(0,16):'';
function displayDate(v){if(!v)return '—';const d=new Date(v.includes('T')?v:v+'T00:00:00');return Number.isNaN(d.getTime())?esc(v):d.toLocaleDateString('fr-FR');}
function typeObject(){return types.find(x=>String(x.id)===$('stageType').value);}
function policyValue(t,k,d=null){return t?.policies&&Object.prototype.hasOwnProperty.call(t.policies,k)?t.policies[k]:d;}
function typeLabel(t){return t?.libelle||'';}
function typeFinance(t){return t?.financial||{configured:false,mode:'GRATUIT',required:false};}

function statusBadge(s){
    const m={BROUILLON:['Brouillon','secondary'],EN_PREPARATION:['En préparation','warning'],OUVERTE:['Ouverte','success'],CLOTUREE:['Clôturée','info'],TERMINEE:['Terminée','dark'],ANNULEE:['Annulée','danger']}[s]||[s,'secondary'];
    return `<span class="badge bg-${m[1]}-subtle text-${m[1]}">${esc(m[0])}</span>`;
}

function financeSummaryHtml(f,legacy=false){
    if(legacy&&!f?.configured)return '<span class="badge bg-light text-dark border">Historique</span> <strong>GRATUIT</strong>';
    if(!f?.configured)return '<span class="badge bg-danger-subtle text-danger">À CONFIGURER</span>';
    return f.mode==='PAYANT'||f.required?`<span class="badge bg-warning-subtle text-warning">PAYANT</span> <strong>${esc(f.amount||'—')} ${esc(f.currency||'')}</strong>`:'<span class="badge bg-success-subtle text-success">GRATUIT</span>';
}
function renderTypeFinance(financial=null,legacy=null){const t=typeObject(),f=financial||typeFinance(t);$('typeFinanceSummary').innerHTML=t||financial?financeSummaryHtml(f,legacy===null?!!t?.legacy:legacy):'<span class="text-muted">Sélectionnez un type de stage.</span>';}
function toggleQuickTypeFinance(){const p=$('newTypePaid').checked;$('newTypeFinancialFields').classList.toggle('d-none',!p);$('newTypeAmount').required=p;if(!p)$('newTypeAmount').value='';}

function localLevels(){
    const m=new Map();
    promotions.forEach(p=>{const c=String(p.niveau_code||'').trim();if(c&&!m.has(c))m.set(c,{code:c,libelle:p.niveau_libelle||c});});
    return [...m.values()].sort((a,b)=>a.code.localeCompare(b.code,'fr',{numeric:true}));
}
function renderNewStageTypeLevels(){
    const l=localLevels();
    $('newStageTypeLevels').innerHTML=l.length?l.map(x=>`<label class="form-check form-check-inline mb-2 me-3"><input class="form-check-input new-stage-level" type="checkbox" value="${esc(x.code)}"><span class="form-check-label"><strong>${esc(x.code)}</strong>${x.libelle!==x.code?` · ${esc(x.libelle)}`:''}</span></label>`).join(''):'<span class="text-muted small">Aucun niveau disponible.</span>';
}
function toggleNewStageTypePanel(show){$('newStageTypePanel').classList.toggle('d-none',!show);if(show){renderNewStageTypeLevels();setTimeout(()=>$('newStageTypeLabel').focus(),50);}}

function eligibleLevelCodes(t=typeObject()){
    const raw=policyValue(t,'eligible_level_codes',[]),a=Array.isArray(raw)?raw:(raw?[raw]:[]);
    return [...new Set(a.map(v=>String(v??'').trim().toUpperCase()).filter(Boolean))];
}
function eligiblePromotionIds(t=typeObject()){
    const keys=['eligible_promotion_ids','promotion_ids','eligible_promotions'];
    for(const k of keys){const raw=policyValue(t,k,[]),a=Array.isArray(raw)?raw:(raw?[raw]:[]),ids=[...new Set(a.map(v=>Number(v)).filter(Boolean))];if(ids.length)return ids;}
    return [];
}
const promoName=p=>p.filiere_nom||p.promotion_label||p.promotion||p.nom||p.niveau_code||'Promotion';

function renderPromotions(selectedIds=[]){
    const t=typeObject(),selected=new Set(selectedIds.map(Number)),all=$('showAllPromotions').checked;
    if(!t){$('promotionFilterHint').textContent='Choisissez d’abord le type de stage.';$('promotionChecks').innerHTML='<div class="text-muted small py-3">Aucune promotion à afficher avant le choix du type de stage.</div>';return;}
    const yearId=Number($('year').value||0);if(!yearId){$('promotionFilterHint').textContent='Choisissez une année académique.';$('promotionChecks').innerHTML='<div class="text-muted small py-3">Choisissez une année académique.</div>';return;}

    const yp=promotions.filter(p=>Number(p.annee_academique_id)===yearId),ids=eligiblePromotionIds(t),levels=eligibleLevelCodes(t);
    const list=all?yp:(ids.length?yp.filter(p=>ids.includes(Number(p.id))):(levels.length?yp.filter(p=>levels.includes(String(p.niveau_code||'').trim().toUpperCase())):[]));
    const lv=[...new Set(list.map(p=>String(p.niveau_code||'').trim()).filter(Boolean))];

    $('promotionFilterHint').innerHTML=all?'<span class="text-warning">Toutes les promotions de l’année.</span>':ids.length?'Promotions liées au type sélectionné.':levels.length?'Niveau(x) du type : <strong>'+levels.map(esc).join(', ')+'</strong>':'Ce type n’a aucune promotion/niveau configuré.';
    if(!yp.length){$('promotionChecks').innerHTML='<div class="text-muted small py-3">Aucune promotion enregistrée pour cette année académique.</div>';return;}
    if(!list.length){$('promotionChecks').innerHTML='<div class="text-muted small py-3">Aucune promotion liée à ce type. Modifiez le type de stage ou activez « Toutes les promotions ».</div>';return;}

    $('promotionChecks').innerHTML=(lv.length?`<div class="small text-muted mb-2">Niveau(x) : <strong>${lv.map(esc).join(', ')}</strong></div>`:'')+
        list.map(p=>`<label class="d-flex align-items-center gap-2 py-2 border-bottom"><input class="form-check-input promotion-choice" type="checkbox" name="promotion_ids[]" value="${p.id}" ${selected.has(Number(p.id))?'checked':''}><strong>${esc(promoName(p))}</strong></label>`).join('');
}

function updateTypeRules(selectedIds=[]){
    const t=typeObject(),
          required=truthy(policyValue(t,'requires_hosting_participation',false))||t?.code==='MEDICAL_D4';

    $('hospitalHint').innerHTML=required
        ?'<span class="text-danger">Au moins un hôpital est obligatoire pour ce type.</span>'
        :'Vous pouvez sélectionner un ou plusieurs hôpitaux à solliciter.';

    renderTypeFinance();
    renderPromotions(selectedIds);
}

/*
 * Le type de stage définit l'objectif.
 * La session choisit facultativement cet objectif.
 */
async function loadObjectiveChoices(selected=''){
    const select=$('objective'),
          id=Number($('stageType').value||0);

    select.innerHTML='<option value="">Aucun objectif</option>';

    if(!id)return;

    select.disabled=true;

    try{
        const r=await STAGIA.request(
            BASE_URL+'/actions/stages/stage-type-objective.php?id='+encodeURIComponent(id)
        );

        const objective=String(r.data?.objectif||'').trim();

        if(objective){
            const option=document.createElement('option');
            option.value=objective;
            option.textContent=objective;
            select.appendChild(option);
        }

        /*
         * Si on modifie une ancienne session et que l'objectif
         * du type a changé depuis, on conserve le snapshot historique.
         */
        const saved=String(selected||'').trim();

        if(saved && saved!==objective){
            const option=document.createElement('option');
            option.value=saved;
            option.textContent=saved+' (objectif enregistré)';
            select.appendChild(option);
        }

        select.value=saved||'';

    }catch(e){
        STAGIA.toast(e.message,'warning');
    }finally{
        select.disabled=false;
    }
}

function fillFormLists(){
    const sv=$('stageType').value,yv=$('year').value,tf=$('typeFilter').value,yf=$('yearFilter').value;
    $('stageType').innerHTML='<option value="">Sélectionner...</option>'+types.map(x=>`<option value="${x.id}">${esc(typeLabel(x))}</option>`).join('');
    $('year').innerHTML='<option value="">Sélectionner...</option>'+years.filter(x=>Number(x.actif)===1).map(x=>`<option value="${x.id}">${esc(x.libelle)}</option>`).join('');
    $('typeFilter').innerHTML='<option value="">Tous les types</option>'+types.map(x=>`<option value="${x.id}">${esc(typeLabel(x))}</option>`).join('');
    $('yearFilter').innerHTML='<option value="">Toutes les années</option>'+years.map(x=>`<option value="${x.id}">${esc(x.libelle)}</option>`).join('');
    if(sv)$('stageType').value=sv;if(yv)$('year').value=yv;$('typeFilter').value=tf;$('yearFilter').value=yf;
}

function financeHtml(x){const f=x.financial||{};return f.required?`<span class="badge bg-warning-subtle text-warning">PAYANT</span><small class="d-block">${esc(f.amount||'—')} ${esc(f.currency||'')}</small>`:'<span class="badge bg-success-subtle text-success">GRATUIT</span>';}
function hostingHtml(x){
    const n=Number(x.hosting_requests)||0,a=Number(x.hosting_accepted)||0,s=(x.configuration?.selected_host_ids||[]).length;
    if(n)return `<small>${n} sollicitation(s)<br><strong>${a}</strong> acceptée(s)</small>`;
    if(s)return `<small>${s} hôpital(s) sélectionné(s)<br><span class="text-muted">à solliciter à la publication</span></small>`;
    return '<span class="text-muted">Non sollicité</span>';
}

function actionButtons(x){
    const b=[];
    if(permissions.update&&x.statut==='BROUILLON')b.push(`<button class="btn btn-sm btn-outline-primary edit-btn" data-id="${x.id}"><i class="bi bi-pencil"></i></button>`);
    if(permissions.publish&&x.statut==='BROUILLON')b.push(`<button class="btn btn-sm btn-outline-success campaign-action" data-id="${x.id}" data-action="PUBLISH"><i class="bi bi-send-check"></i></button>`);
    if(permissions.publish&&x.statut==='EN_PREPARATION')b.push(`<button class="btn btn-sm btn-outline-success campaign-action" data-id="${x.id}" data-action="OPEN"><i class="bi bi-play-circle"></i></button>`);
    if(permissions.publish&&x.statut==='OUVERTE')b.push(`<button class="btn btn-sm btn-outline-info campaign-action" data-id="${x.id}" data-action="CLOSE"><i class="bi bi-lock"></i></button>`);
    if(permissions.publish&&x.statut==='CLOTUREE')b.push(`<button class="btn btn-sm btn-outline-dark campaign-action" data-id="${x.id}" data-action="FINISH"><i class="bi bi-check2-circle"></i></button>`);
    if(permissions.cancel&&!['TERMINEE','ANNULEE'].includes(x.statut))b.push(`<button class="btn btn-sm btn-outline-danger campaign-action" data-id="${x.id}" data-action="CANCEL"><i class="bi bi-x-circle"></i></button>`);
    return b.length?`<div class="btn-group btn-group-sm">${b.join('')}</div>`:'—';
}

async function load(){
    const q=new URLSearchParams();
    if($('search').value.trim())q.set('search',$('search').value.trim());
    if($('typeFilter').value)q.set('stage_type_id',$('typeFilter').value);
    if($('yearFilter').value)q.set('annee_academique_id',$('yearFilter').value);
    if($('statusFilter').value)q.set('statut',$('statusFilter').value);

    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/campagne-list.php?'+q),d=r.data;
        items=d.items||[];types=d.types||[];years=d.years||[];units=d.units||[];filieres=d.filieres||[];promotions=d.promotions||[];permissions=d.permissions||{};
        $('addBtn').classList.toggle('d-none',!permissions.create);$('kTotal').textContent=d.kpi.total||0;$('kDraft').textContent=d.kpi.draft||0;$('kPreparing').textContent=d.kpi.preparing||0;$('kOpen').textContent=d.kpi.opened||0;fillFormLists();

        $('rows').innerHTML=items.length?items.map(x=>{
            const rows=x.promotions||[],ps=rows.slice(0,2).map(p=>esc(promoName(p))).join('<br>'),
                  lv=[...new Set(rows.map(p=>p.niveau_code).filter(Boolean))].join(', '),
                  more=rows.length>2?`<small class="d-block text-muted">+${rows.length-2} autre(s)</small>`:'';
            return `<tr>
                <td><strong>${esc(x.titre)}</strong><small class="d-block text-muted">${esc(x.code||'—')}</small></td>
                <td><span class="badge bg-light text-dark border">${esc(x.stage_type_libelle)}</span><small class="d-block text-muted">${esc(x.annee_libelle||'—')}</small></td>
                <td><strong>${displayDate(x.date_debut)} → ${displayDate(x.date_fin)}</strong><small class="d-block text-muted">Candidatures : ${displayDate(String(x.ouverture_candidatures||'').slice(0,10))} → ${displayDate(String(x.cloture_candidatures||'').slice(0,10))}</small></td>
                <td>${lv?`<small class="d-block text-muted">Niveau(x) : ${esc(lv)}</small>`:''}${ps||'—'}${more}<small class="d-block text-muted">${Number(x.eligible_students)||0} étudiant(s)</small></td>
                <td>${financeHtml(x)}</td><td>${hostingHtml(x)}</td>
                <td>${statusBadge(x.statut)}</td><td class="text-end">${actionButtons(x)}</td>
            </tr>`;
        }).join(''):'<tr><td colspan="8" class="text-center py-5 text-muted">Aucune campagne.</td></tr>';

        document.querySelectorAll('.edit-btn').forEach(b=>b.onclick=()=>edit(Number(b.dataset.id)));
        document.querySelectorAll('.campaign-action').forEach(b=>b.onclick=()=>changeStatus(Number(b.dataset.id),b.dataset.action));
    }catch(e){$('rows').innerHTML=`<tr><td colspan="8" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;STAGIA.toast(e.message,'danger');}
}

$('addBtn').onclick=()=>{
    $('campaignForm').reset();$('campaignId').value='';$('showAllPromotions').checked=false;fillFormLists();$('modalTitle').textContent='Lancer une session';

    const py=new Set(promotions.map(p=>Number(p.annee_academique_id)).filter(Boolean));
    const y=years.find(x=>Number(x.actif)===1&&py.has(Number(x.id)))||years.find(x=>Number(x.actif)===1);
    if(y)$('year').value=String(y.id);

    hospitalChoices().forEach(x=>{x.checked=false;const d=hospitalDemand(x);d.value='';d.disabled=true;d.required=false;});$('hospitalSearch').value='';
    $('newStageTypeLabel').value='';$('newStageTypeDescription').value='';$('newTypeFree').checked=true;$('newTypePaid').checked=false;$('newTypeAmount').value='';$('newTypeCurrency').value='USD';

    toggleQuickTypeFinance();toggleNewStageTypePanel(false);updateTypeRules();loadObjectiveChoices();modal.show();
};

async function edit(id){
    const x=items.find(v=>Number(v.id)===id);if(!x)return;
    $('campaignForm').reset();$('campaignId').value=x.id;$('showAllPromotions').checked=false;fillFormLists();
    $('title').value=x.titre||'';$('stageType').value=x.stage_type_id||'';$('year').value=x.annee_academique_id||'';
    $('description').value=x.description||'';$('applicationsOpen').value=localDateTime(x.ouverture_candidatures);$('applicationsClose').value=localDateTime(x.cloture_candidatures);$('startDate').value=x.date_debut||'';$('endDate').value=x.date_fin||'';

    const cfg=x.configuration||{},ids=(cfg.selected_host_ids||[]).map(Number),demands=cfg.selected_host_demands||{};
    hospitalChoices().forEach(h=>{const on=ids.includes(Number(h.value)),d=hospitalDemand(h);h.checked=on;d.disabled=!on;d.required=on;d.value=on?(demands[h.value]||''):'';});
    $('requirements').value=(x.requirements||[]).map(r=>r.libelle).join('\n');

    toggleNewStageTypePanel(false);

    const selectedPromotionIds=(x.promotions||[]).map(p=>Number(p.id)),type=typeObject(),allowedIds=eligiblePromotionIds(type),allowedLevels=eligibleLevelCodes(type),selectedRows=promotions.filter(p=>selectedPromotionIds.includes(Number(p.id))),hasOutsideType=allowedIds.length?selectedRows.some(p=>!allowedIds.includes(Number(p.id))):(allowedLevels.length?selectedRows.some(p=>!allowedLevels.includes(String(p.niveau_code||'').trim().toUpperCase())):false);

    /*
     * Compatibilité historique :
     * si une ancienne session contient déjà une promotion hors filtre
     * du type, on active "Toutes les promotions" afin de ne pas la masquer.
     */
    $('showAllPromotions').checked=hasOutsideType;

    updateTypeRules(selectedPromotionIds);
    renderTypeFinance(x.financial||{},false);
    await loadObjectiveChoices(x.objectif_stage||'');
    $('modalTitle').textContent='Modifier le brouillon';
    modal.show();
}

document.querySelectorAll('.new-type-finance').forEach(x=>x.onchange=toggleQuickTypeFinance);
$('newStageTypeBtn').onclick=()=>{toggleNewStageTypePanel(true);toggleQuickTypeFinance();};
$('closeStageTypePanel').onclick=$('cancelStageTypeBtn').onclick=()=>toggleNewStageTypePanel(false);

$('saveStageTypeBtn').onclick=async()=>{
    const label=$('newStageTypeLabel').value.trim();if(!label){STAGIA.toast('Saisissez le libellé du type de stage.','warning');return;}
    const fd=new FormData();fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('libelle',label);fd.append('description',$('newStageTypeDescription').value.trim());fd.append('financial_mode',$('newTypePaid').checked?'PAYANT':'GRATUIT');
    if($('newTypePaid').checked){fd.append('financial_amount',$('newTypeAmount').value);fd.append('financial_currency',$('newTypeCurrency').value);}
    document.querySelectorAll('.new-stage-level:checked').forEach(x=>fd.append('eligible_level_codes[]',x.value));

    STAGIA.loading($('saveStageTypeBtn'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/stages/stage-type-store.php',fd),t=r.data?.type;
        if(t){types=types.filter(x=>Number(x.id)!==Number(t.id));types.push(t);fillFormLists();$('stageType').value=String(t.id);$('showAllPromotions').checked=false;updateTypeRules();await loadObjectiveChoices();}
        toggleNewStageTypePanel(false);STAGIA.toast(r.message,'success');
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('saveStageTypeBtn'),false);}
};

function refreshEligiblePromotions(){
    const selected=[...document.querySelectorAll('.promotion-choice:checked')].map(x=>Number(x.value));
    updateTypeRules(selected);
}
$('stageType').onchange=()=>{
    /*
     * Chaque changement de type revient volontairement
     * au filtre prioritaire défini dans ce type.
     */
    $('showAllPromotions').checked=false;
    refreshEligiblePromotions();
    loadObjectiveChoices();
};

$('year').onchange=refreshEligiblePromotions;

$('showAllPromotions').onchange=()=>{
    const selected=
        [...document.querySelectorAll('.promotion-choice:checked')]
        .map(x=>Number(x.value));

    renderPromotions(selected);
};

$('hospitalSearch').oninput=e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('.hospital-row').forEach(x=>x.classList.toggle('d-none',!x.textContent.toLowerCase().includes(q)));};
hospitalChoices().forEach(h=>h.onchange=()=>{const d=hospitalDemand(h);d.disabled=!h.checked;d.required=h.checked;if(!h.checked)d.value='';});
$('hospitalAll').onclick=()=>hospitalChoices().filter(h=>!h.closest('.hospital-row').classList.contains('d-none')).forEach(h=>{h.checked=true;const d=hospitalDemand(h);d.disabled=false;d.required=true;});
$('hospitalNone').onclick=()=>hospitalChoices().forEach(h=>{h.checked=false;const d=hospitalDemand(h);d.value='';d.disabled=true;d.required=false;});

$('campaignForm').onsubmit=async e=>{
    e.preventDefault();

    if(!document.querySelector('.promotion-choice:checked')){
        STAGIA.toast(
            'Sélectionnez au moins une promotion.',
            'warning'
        );
        return;
    }

    const t=typeObject();

    const requiresHosting=
        t?.code==='MEDICAL_D4' ||
        truthy(
            policyValue(
                t,
                'requires_hosting_participation',
                false
            )
        );

    if(
        requiresHosting &&
        !document.querySelector('.hospital-choice:checked')
    ){
        STAGIA.toast(
            'Sélectionnez au moins un hôpital à solliciter.',
            'warning'
        );
        return;
    }

    STAGIA.loading(
        $('saveBtn'),
        true
    );

    try{

        /* ==========================================
           1. ENREGISTRER LA SESSION
        ========================================== */

        const fd=
            new FormData(
                e.currentTarget
            );

        const existingId=
            Number(
                $('campaignId').value||0
            );

        const url=
            existingId
                ?BASE_URL+
                 '/actions/stages/campagne-update.php'

                :BASE_URL+
                 '/actions/stages/campagne-store.php';


        const saved=
            await STAGIA.post(
                url,
                fd
            );


        const sessionId=
            existingId ||
            Number(
                saved.data?.id||0
            );


        if(!sessionId){
            throw new Error(
                'Session enregistrée mais identifiant introuvable.'
            );
        }


        /*
         * Important :
         * si la publication échoue,
         * le formulaire devient une modification.
         *
         * Un deuxième clic ne recréera donc pas
         * une nouvelle session.
         */
        $('campaignId').value=
            sessionId;


        /* ==========================================
           2. PUBLICATION AUTOMATIQUE
        ========================================== */

        const publishFd=
            new FormData();

        publishFd.append(
            'csrf',
            '<?= $_SESSION['csrf'] ?>'
        );

        publishFd.append(
            'id',
            sessionId
        );

        publishFd.append(
            'action',
            'PUBLISH'
        );

        publishFd.append(
            'reason',
            ''
        );


        try{

            const published=
                await STAGIA.post(
                    BASE_URL+
                    '/actions/stages/campagne-status.php',
                    publishFd
                );


            STAGIA.toast(
                published.message,
                'success'
            );


            modal.hide();

            await load();


        }catch(publishError){

            /*
             * La session est déjà enregistrée.
             * On ne la recrée pas.
             */
            STAGIA.toast(
                'Session enregistrée, mais publication impossible : '+
                publishError.message,
                'warning'
            );

            await load();
        }


    }catch(e){

        STAGIA.toast(
            e.message,
            'danger'
        );

    }finally{

        STAGIA.loading(
            $('saveBtn'),
            false
        );
    }
};

async function changeStatus(id,action){
    let reason='';const p={PUBLISH:'Publier cette session ?',OPEN:'Publier cette session aux étudiants ?',CLOSE:'Clôturer les candidatures ?',FINISH:'Terminer cette session ?'};
    if(action==='CANCEL'){reason=prompt("Motif d'annulation :")||'';if(!reason.trim())return;}
    else if(!STAGIA.confirm(p[action]||'Confirmer ?'))return;

    const fd=new FormData();fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('id',id);fd.append('action',action);fd.append('reason',reason);
    try{const r=await STAGIA.post(BASE_URL+'/actions/stages/campagne-status.php',fd);STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}
}

$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300);};
$('typeFilter').onchange=load;$('yearFilter').onchange=load;$('statusFilter').onchange=load;
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
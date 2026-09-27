<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'campaign.hosting.respond');
if(!contextHostEnabled()){http_response_code(403);exit("Cet établissement n'est pas une structure d'accueil.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Sollicitations';$activePage='hospital-solicitations';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Sollicitations</h1><p>Examinez toutes les demandes institutionnelles reçues des établissements de formation.</p></div></div>
<div class="alert alert-light border"><i class="bi bi-hospital text-primary me-1"></i><strong>L'établissement d'accueil définit lui-même la capacité qu'il propose.</strong> Le besoin de l'université reste indicatif.</div>

<div class="row g-3 mb-4">
<div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">TOTAL</small><h3 id="kTotal">0</h3></div></div>
<div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">NOUVELLES</small><h3 id="kNew">0</h3></div></div>
<div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">EN ÉTUDE</small><h3 id="kStudy">0</h3></div></div>
<div class="col-md-3"><div class="stagia-list-card p-3"><small class="text-muted">ACCEPTÉES</small><h3 id="kAccepted">0</h3></div></div>
</div>

<div class="stagia-list-card">
<div class="p-3 border-bottom"><select id="statusFilter" class="form-select" style="max-width:260px">
<option value="">Tous les statuts</option><option value="SOLLICITEE">Nouvelles</option><option value="EN_ETUDE">En étude</option><option value="ACCEPTEE">Acceptées</option><option value="REFUSEE">Refusées</option><option value="ANNULEE">Annulées</option>
</select></div>
<div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>ÉTABLISSEMENT / CAMPAGNE</th><th>PÉRIODE</th><th>BESOIN SOUHAITÉ</th><th>OFFRE ACCUEIL</th><th>STATUT</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="rows"><tr><td colspan="6" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table></div>
</div>
</main>

<div class="modal fade" id="responseModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
<form id="responseForm">
<div class="modal-header"><div><h5 class="modal-title">Répondre à la sollicitation</h5><small class="text-muted" id="responseTitle"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><input type="hidden" name="id" id="responseId"><input type="hidden" name="host_campaign_id" id="hostCampaignId">

<div class="mb-3">
<label class="form-label">Campagne d'accueil</label>
<input id="hostCampaignLabel" class="form-control" readonly>
<small class="text-muted">STAGIA rattache automatiquement la réponse à une campagne compatible ou en crée une si nécessaire.</small>
</div>

<div class="mb-3"><label class="form-label">Décision *</label><select name="decision" id="decision" class="form-select" required>
<option value="ACCEPTER">Accepter et proposer une capacité</option><option value="REFUSER">Refuser la sollicitation</option>
</select></div>

<div id="acceptFields"><div class="row g-3">
<div class="col-md-5"><label class="form-label">Capacité proposée *</label><input type="number" min="1" step="1" inputmode="numeric" name="capacite_proposee" id="capacity" class="form-control" placeholder="Ex. 10"></div>
<div class="col-12"><label class="form-label">Conditions particulières</label><textarea name="conditions" id="conditions" class="form-control" rows="3"></textarea></div>
<div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input type="hidden" name="frais_requis" value="0"><input class="form-check-input" type="checkbox" name="frais_requis" value="1" id="feeRequired"><label class="form-check-label" for="feeRequired">Frais éventuels</label></div></div>
<div class="col-md-4"><label class="form-label">Montant</label><input type="text" inputmode="decimal" name="montant_frais" id="amount" class="form-control" placeholder="0.00" autocomplete="off" disabled></div>
<div class="col-md-4"><label class="form-label">Devise</label><select name="devise" id="currency" class="form-select" disabled><option value="USD">USD</option><option value="CDF">CDF</option><option value="EUR">EUR</option></select></div>
</div></div>

<div class="d-none" id="refuseFields"><label class="form-label">Motif du refus *</label><textarea name="motif_refus" id="refusalReason" class="form-control" rows="4"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="responseBtn">Enregistrer la décision</button></div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('responseModal'));
let data={items:[],host_campaigns:[]},current=null;

function badge(s){const m={SOLLICITEE:['Nouvelle','bg-danger-subtle text-danger'],EN_ETUDE:['En étude','bg-warning-subtle text-warning'],ACCEPTEE:['Acceptée','bg-success-subtle text-success'],REFUSEE:['Refusée','bg-danger-subtle text-danger'],ANNULEE:['Annulée','bg-dark-subtle text-dark']}[s]||[s,'bg-light text-dark'];return `<span class="badge ${m[1]}">${esc(m[0])}</span>`;}
function overlap(c,x){return Number(c.stage_type_id)===Number(x.stage_type_id)&&(!x.date_debut||!c.date_fin||c.date_fin>=x.date_debut)&&(!x.date_fin||!c.date_debut||c.date_debut<=x.date_fin);}
function resolveCampaign(){
    if(!current)return;
    const need=Number($('capacity').value||0),cs=data.host_campaigns||[];
    let c=cs.find(v=>Number(v.id)===Number(current.host_campaign_id));
    if(!c)c=cs.find(v=>overlap(v,current)&&(!need||Number(v.capacite_disponible)>=need));
    $('hostCampaignId').value=c?.id||'';
    $('hostCampaignLabel').value=c?`${c.code} — ${c.titre}`:'Création automatique lors de l’acceptation';
}

async function load(){
    const q=$('statusFilter').value?`?statut=${encodeURIComponent($('statusFilter').value)}`:'';
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/host-request-list.php'+q);data=r.data;
        $('kTotal').textContent=data.kpi?.total||0;$('kNew').textContent=data.kpi?.new_count||0;$('kStudy').textContent=data.kpi?.studying||0;$('kAccepted').textContent=data.kpi?.accepted||0;
        $('rows').innerHTML=(data.items||[]).length?data.items.map(x=>`<tr>
        <td><strong>${esc(x.university_name)}</strong><small class="d-block text-muted">${esc(x.university_campaign_code)} — ${esc(x.university_campaign_title)}</small><span class="badge bg-light text-dark border mt-1">${esc(x.stage_type_libelle||'Stage')}</span></td>
        <td>${esc(x.date_debut||'—')} → ${esc(x.date_fin||'—')}</td>
        <td>${x.capacite_demandee?`<strong>${Number(x.capacite_demandee)} place(s)</strong>`:'—'}</td>
        <td>${x.capacite_proposee?`<strong>${Number(x.capacite_proposee)} place(s)</strong>`:'—'}${x.host_campaign_title?`<small class="d-block text-muted">${esc(x.host_campaign_title)}</small>`:''}${x.capacite_acceptee?'<small class="d-block text-success">Offre retenue par l’université</small>':''}</td>
        <td>${badge(x.statut)}${x.motif_refus?`<small class="d-block text-danger">${esc(x.motif_refus)}</small>`:''}</td>
        <td class="text-end">${x.statut==='SOLLICITEE'?`<button class="btn btn-sm btn-outline-warning study" data-id="${x.id}" title="Mettre en étude"><i class="bi bi-eye"></i></button>`:''}
        ${['SOLLICITEE','EN_ETUDE'].includes(x.statut)||(x.statut==='ACCEPTEE'&&!x.capacite_acceptee)?`<button class="btn btn-sm btn-outline-primary respond" data-id="${x.id}"><i class="bi bi-reply me-1"></i>Répondre</button>`:''}</td></tr>`).join('')
        :'<tr><td colspan="6" class="text-center py-5 text-muted">Aucune sollicitation.</td></tr>';
        document.querySelectorAll('.study').forEach(b=>b.onclick=()=>study(Number(b.dataset.id)));
        document.querySelectorAll('.respond').forEach(b=>b.onclick=()=>openResponse(Number(b.dataset.id)));
    }catch(e){STAGIA.toast(e.message,'danger');}
}

async function study(id){
    const fd=new FormData();fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('id',id);fd.append('decision','ETUDIER');
    try{const r=await STAGIA.post(BASE_URL+'/actions/stages/host-participation-response.php',fd);STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}
}

function openResponse(id){
    current=data.items.find(x=>Number(x.id)===id);if(!current)return;
    $('responseForm').reset();$('responseId').value=current.id;
    $('responseTitle').textContent=`${current.university_name} — ${current.stage_type_libelle||'Stage'} — besoin : ${current.capacite_demandee||'—'} place(s)`;
    $('capacity').value=current.capacite_proposee||'';$('conditions').value=current.conditions||'';
    $('feeRequired').checked=Number(current.frais_requis)===1;$('amount').value=current.montant_frais||'';$('currency').value=current.devise||'USD';
    $('decision').value='ACCEPTER';toggleDecision();toggleFees();resolveCampaign();modal.show();
}

function toggleDecision(){
    const a=$('decision').value==='ACCEPTER';$('acceptFields').classList.toggle('d-none',!a);$('refuseFields').classList.toggle('d-none',a);
    $('capacity').required=a;$('refusalReason').required=!a;if(!a){$('feeRequired').checked=false;toggleFees();}
}
function toggleFees(){const on=$('feeRequired').checked;$('amount').disabled=!on;$('currency').disabled=!on;$('amount').required=on;if(!on)$('amount').value='';}

$('amount').oninput=e=>{let v=e.target.value.replace(',','.').replace(/[^0-9.]/g,''),p=v.indexOf('.');if(p!==-1)v=v.slice(0,p+1)+v.slice(p+1).replace(/\./g,'');e.target.value=v;};
$('capacity').oninput=()=>resolveCampaign();
$('capacity').onkeydown=e=>{if(['e','E','+','-','.',','].includes(e.key))e.preventDefault();};
$('decision').onchange=toggleDecision;$('feeRequired').onchange=toggleFees;

$('responseForm').onsubmit=async e=>{
    e.preventDefault();
    if($('decision').value==='ACCEPTER'&&Number($('capacity').value)<1){STAGIA.toast('Saisissez une capacité valide.','warning');return;}
    if($('feeRequired').checked&&!/^\d+(?:[.,]\d{1,2})?$/.test($('amount').value)){STAGIA.toast('Saisissez un montant valide.','warning');return;}
    STAGIA.loading($('responseBtn'),true);
    try{const r=await STAGIA.post(BASE_URL+'/actions/stages/host-participation-response.php',new FormData(e.currentTarget));STAGIA.toast(r.message);modal.hide();await load();}
    catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('responseBtn'),false);}
};

$('statusFilter').onchange=load;load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

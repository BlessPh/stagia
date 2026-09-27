<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId)exit('Aucun établissement associé.');
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Candidatures de stage';
$activePage='stages-candidatures';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Candidatures de stage</h1>
        <p>Validez rapidement les choix d'établissement d'accueil des étudiants.</p>
    </div>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>TOTAL</span><strong id="statTotal">0</strong><small>Candidatures enregistrées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-files"></i></div></div>
    <div class="stagia-kpi-card"><div><span>À TRAITER</span><strong id="statSubmitted">0</strong><small>Décision requise</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-hourglass-split"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACCEPTÉES</span><strong id="statAccepted">0</strong><small>Candidatures acceptées</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-check2-square"></i></div></div>
    <div class="stagia-kpi-card"><div><span>CONFIRMÉES</span><strong id="statConfirmed">0</strong><small>Places définitives</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-patch-check"></i></div></div>
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar">
    <div class="stagia-tabs">
        <button class="stagia-tab active" data-status="">Toutes</button>
        <button class="stagia-tab" data-status="SOUMISE">À traiter</button>
        <button class="stagia-tab" data-status="ACCEPTEE">Acceptées</button>
        <button class="stagia-tab" data-status="CONFIRMEE">Confirmées</button>
        <button class="stagia-tab" data-status="REFUSEE">Refusées</button>
    </div>
    <div style="max-width:300px;width:100%"><input type="search" id="applicationSearch" class="form-control" placeholder="Rechercher un étudiant..."></div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead><tr>
    <th>ÉTUDIANT</th><th>PROMOTION</th><th>CAMPAGNE</th><th>HÔPITAL</th>
    <th>CANDIDATURE</th><th>RÉSERVATION</th><th class="text-end">ACTION</th>
</tr></thead>
<tbody id="applicationBody"><tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="decisionModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">
<form id="decisionForm">
<div class="modal-header">
    <div><h5 class="modal-title">Traiter la candidature</h5><small class="text-muted" id="decisionStudent"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
    <input type="hidden" name="application_id" id="decisionId">
    <input type="hidden" name="decision" id="decisionValue">

    <div class="border rounded-3 p-3 mb-3 bg-light">
        <div class="small text-muted">Hôpital</div><strong id="decisionHospital">-</strong>
        <div class="small text-muted mt-2">Campagne</div><strong id="decisionCampaign">-</strong>
        <div id="decisionFee" class="small mt-2"></div>
    </div>

    <div id="acceptInfo" class="alert alert-success mb-0 d-none">
        <i class="bi bi-check-circle me-1"></i>
        <span id="acceptText">La candidature sera acceptée.</span>
    </div>

    <div id="refuseBox" class="d-none">
        <label class="form-label">Motif du refus *</label>
        <textarea name="motif_refus" id="refusalReason" class="form-control" rows="3" maxlength="1000" placeholder="Indiquez brièvement le motif..."></textarea>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="decisionBtn">Confirmer</button>
</div>
</form>
</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id),modal=new bootstrap.Modal($('decisionModal'));
let items=[],status='';

async function charger(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/university-application-list.php');
        items=r.data.items||[];const s=r.data.stats||{};
        $('statTotal').textContent=s.total||0;$('statSubmitted').textContent=s.soumises||0;
        $('statAccepted').textContent=s.acceptees||0;$('statConfirmed').textContent=s.confirmees||0;
        afficher();
    }catch(e){$('applicationBody').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;}
}

function afficher(){
    const q=$('applicationSearch').value.trim().toLowerCase();
    render(items.filter(x=>{
        const txt=[x.nom,x.postnom,x.prenom,x.stagia_code,x.hospital_name,x.campaign_title].filter(Boolean).join(' ').toLowerCase();
        const ok=!status||(status==='CONFIRMEE'?x.reservation_statut==='CONFIRMEE':x.statut===status);
        return (!q||txt.includes(q))&&ok;
    }));
}

function render(list){
    const body=$('applicationBody');
    if(!list.length){body.innerHTML='<tr><td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Aucune candidature trouvée.</td></tr>';return;}
    body.innerHTML=list.map(x=>{
        const actionable=['SOUMISE','EN_ETUDE'].includes(x.statut);
        const letterUrl=BASE_URL+'/views/documents/print-stage-document.php?type=lettre_stage&application_id='+encodeURIComponent(x.application_id);
        const doneText=x.invoice_statut==='EMISE'?'Paiement attendu':x.reservation_statut==='CONFIRMEE'?'Terminé':'-';
        const actions=actionable?`
            <div class="d-flex justify-content-end gap-1 flex-wrap">
                <button class="btn btn-sm btn-success decision" data-id="${x.application_id}" data-decision="ACCEPTEE"><i class="bi bi-check-lg me-1"></i>Accepter</button>
                <button class="btn btn-sm btn-outline-danger decision" data-id="${x.application_id}" data-decision="REFUSEE"><i class="bi bi-x-lg me-1"></i>Refuser</button>
            </div>`:
            (x.statut==='ACCEPTEE'||x.reservation_statut==='CONFIRMEE'?`
            <div class="d-flex justify-content-end align-items-center gap-1 flex-wrap">
                <a class="btn btn-sm btn-outline-primary" href="${letterUrl}" target="_blank"><i class="bi bi-file-earmark-text me-1"></i>Lettre</a>
                <span class="small text-muted">${doneText}</span>
            </div>`:`<span class="small text-muted">${doneText}</span>`);
        return `<tr>
        <td><strong>${STAGIA.escape([x.nom,x.postnom,x.prenom].filter(Boolean).join(' ')||'Étudiant')}</strong><div class="small text-muted">${STAGIA.escape(x.stagia_code||x.matricule||'-')}</div></td>
        <td><strong>${STAGIA.escape(x.promotion||'-')}</strong><div class="small text-muted">${STAGIA.escape(x.filiere||'-')}</div></td>
        <td><strong>${STAGIA.escape(x.campaign_title||'-')}</strong><div class="small text-muted">${STAGIA.escape(x.campaign_code||'-')}</div></td>
        <td><strong>${STAGIA.escape(x.hospital_name||'-')}</strong><div class="small text-muted">${STAGIA.escape([x.ville,x.province].filter(Boolean).join(', ')||'-')}</div></td>
        <td><span class="badge ${applicationBadge(x.statut)}">${applicationLabel(x.statut)}</span><div class="small text-muted mt-1">${dateTime(x.submitted_at)}</div></td>
        <td><span class="badge ${reservationBadge(x.reservation_statut)}">${reservationLabel(x.reservation_statut)}</span>${x.invoice_statut?`<div class="small text-muted mt-1">${invoiceLabel(x.invoice_statut)}</div>`:''}</td>
        <td class="text-end">${actions}</td></tr>`;
    }).join('');
    document.querySelectorAll('.decision').forEach(b=>b.onclick=()=>openDecision(Number(b.dataset.id),b.dataset.decision));
}

function openDecision(id,decision){
    const x=items.find(i=>Number(i.application_id)===id);if(!x)return;
    $('decisionForm').reset();$('decisionId').value=id;$('decisionValue').value=decision;
    $('decisionStudent').textContent=[x.nom,x.postnom,x.prenom].filter(Boolean).join(' ');
    $('decisionHospital').textContent=x.hospital_name||'-';$('decisionCampaign').textContent=x.campaign_title||'-';
    const fee=Number(x.frais_requis)===1;
    $('decisionFee').innerHTML=fee?`<span class="text-warning-emphasis"><i class="bi bi-credit-card me-1"></i>Stage payant : <strong>${money(x.montant_frais)} ${STAGIA.escape(x.devise||'USD')}</strong></span>`:'<span class="text-success"><i class="bi bi-check-circle me-1"></i>Stage gratuit</span>';
    const accept=decision==='ACCEPTEE';
    $('acceptInfo').classList.toggle('d-none',!accept);$('refuseBox').classList.toggle('d-none',accept);
    $('acceptText').textContent=fee?'Après acceptation, la lettre de stage sera envoyée à l’hôpital. Le paiement bloquera seulement l’affectation au service.':'La place sera confirmée, publiée côté hôpital et la lettre de stage sera envoyée.';
    $('decisionBtn').className='btn '+(accept?'btn-success':'btn-danger');
    $('decisionBtn').innerHTML=accept?'<i class="bi bi-check-lg me-1"></i>Accepter':'<i class="bi bi-x-lg me-1"></i>Refuser';
    modal.show();
}

$('decisionForm').onsubmit=async e=>{
    e.preventDefault();
    if($('decisionValue').value==='REFUSEE'&&!$('refusalReason').value.trim()){STAGIA.toast('Le motif du refus est obligatoire.','warning');return;}
    const btn=$('decisionBtn');STAGIA.loading(btn,true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/stages/university-application-decision.php',new FormData(e.currentTarget));
        STAGIA.toast(r.message);modal.hide();await charger();
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading(btn,false);}
};

function applicationLabel(s){return {BROUILLON:'BROUILLON',SOUMISE:'SOUMISE',EN_ETUDE:'EN ÉTUDE',ACCEPTEE:'ACCEPTÉE',REFUSEE:'REFUSÉE',ANNULEE:'ANNULÉE'}[s]||s||'-';}
function applicationBadge(s){return {BROUILLON:'bg-secondary',SOUMISE:'bg-primary',EN_ETUDE:'bg-warning text-dark',ACCEPTEE:'bg-success',REFUSEE:'bg-danger',ANNULEE:'bg-secondary'}[s]||'bg-secondary';}
function reservationLabel(s){return {RESERVEE_TEMPORAIREMENT:'TEMPORAIRE',EN_ATTENTE_PAIEMENT:'PAIEMENT',CONFIRMEE:'CONFIRMÉE',EXPIREE:'EXPIRÉE',ANNULEE:'ANNULÉE'}[s]||'-';}
function reservationBadge(s){return {RESERVEE_TEMPORAIREMENT:'bg-warning text-dark',EN_ATTENTE_PAIEMENT:'bg-primary',CONFIRMEE:'bg-success',EXPIREE:'bg-secondary',ANNULEE:'bg-danger'}[s]||'bg-secondary';}
function invoiceLabel(s){return {EMISE:'Facture émise',PARTIELLEMENT_PAYEE:'Paiement partiel',PAYEE:'Payée',ANNULEE:'Facture annulée',EXPIREE:'Facture expirée'}[s]||s;}
function dateTime(v){return v?new Date(v.replace(' ','T')).toLocaleString('fr-FR'):'-';}
function money(v){return Number(v||0).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2});}

document.querySelectorAll('.stagia-tab').forEach(tab=>tab.onclick=()=>{
    document.querySelectorAll('.stagia-tab').forEach(x=>x.classList.remove('active'));tab.classList.add('active');status=tab.dataset.status||'';afficher();
});
$('applicationSearch').addEventListener('input',afficher);
charger();
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

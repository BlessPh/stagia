<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle='Mes paiements';
$activePage='my-payments';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Mes paiements</h1>
        <p>Toutes vos obligations financières et transactions sont regroupées sur cette page.</p>
    </div>
    <button type="button" class="btn btn-light border" id="refreshPayments"><i class="bi bi-arrow-clockwise me-1"></i>Actualiser</button>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>TOTAL</span><strong id="statTotal">0</strong><small>Obligations</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-receipt"></i></div></div>
    <div class="stagia-kpi-card"><div><span>À PAYER</span><strong id="statPayable">0</strong><small>Paiement disponible</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-wallet2"></i></div></div>
    <div class="stagia-kpi-card"><div><span>EN ATTENTE</span><strong id="statPending">0</strong><small>Confirmation opérateur</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-phone-vibrate"></i></div></div>
    <div class="stagia-kpi-card"><div><span>PAYÉES</span><strong id="statPaid">0</strong><small>Obligations soldées</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div></div>
</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div><h5 class="mb-1">Obligations financières</h5><small class="text-muted">Stage, abonnement et futures fonctionnalités payantes.</small></div>
        <select id="statusFilter" class="form-select" style="max-width:220px">
            <option value="">Tous les statuts</option>
            <option value="PENDING,PARTIALLY_PAID">À payer</option>
            <option value="PAID">Payées</option>
            <option value="CANCELLED">Annulées</option>
            <option value="EXPIRED">Expirées</option>
        </select>
    </div>
    <div id="paymentNotice" class="alert alert-info mx-3 mt-3 d-none" role="status"></div>
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>OBLIGATION</th><th>RÉFÉRENCE</th><th>MONTANT</th><th>ÉCHÉANCE</th><th>STATUT</th><th class="text-center">ACTION</th></tr></thead>
            <tbody id="obligationBody"><tr><td colspan="6" class="text-center py-5">Chargement...</td></tr></tbody>
        </table>
    </div>
</div>
</main>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="paymentForm">
    <div class="modal-header"><div><h5 class="modal-title">Payer avec Mobile Money</h5><small class="text-muted" id="paymentLabel"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>">
        <input type="hidden" name="obligation_uuid" id="obligationUuid">
        <input type="hidden" name="idempotency_key" id="idempotencyKey">
        <div class="alert alert-light border">Montant restant : <strong id="paymentAmount">-</strong></div>
        <div class="mb-3"><label class="form-label" for="paymentChannel">Opérateur *</label>
            <select class="form-select" name="channel" id="paymentChannel" required>
                <option value="">Sélectionner...</option><option value="MPESA">M-Pesa</option>
                <option value="ORANGE_MONEY">Orange Money</option><option value="AIRTEL_MONEY">Airtel Money</option>
                <option value="AFRIMONEY">Afrimoney</option>
            </select>
        </div>
        <div><label class="form-label" for="phoneNumber">Numéro Mobile Money *</label><input class="form-control" type="tel" inputmode="tel" autocomplete="tel" name="phone_number" id="phoneNumber" placeholder="Ex. 0812345678" required><div class="form-text">Une demande de confirmation sera envoyée sur ce téléphone. Ne communiquez jamais votre code PIN à STAGIA.</div></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Fermer</button><button class="btn btn-primary-stagia" id="submitPayment"><i class="bi bi-phone me-1"></i>Envoyer la demande</button></div>
</form>
</div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE=<?= json_encode(BASE_URL,JSON_UNESCAPED_SLASHES) ?>,csrf=<?= json_encode($_SESSION['csrf']) ?>;
const $=id=>document.getElementById(id),modal=new bootstrap.Modal($('paymentModal'));
let items=[],pollTimer=null;

const esc=v=>STAGIA.escape(String(v??''));
const money=(v,c)=>`${Number(v||0).toLocaleString('fr-FR',{minimumFractionDigits:0,maximumFractionDigits:2})} ${esc(c||'')}`;
const dateFr=v=>v?new Intl.DateTimeFormat('fr-CD',{dateStyle:'medium',timeStyle:String(v).includes(':')?'short':undefined}).format(new Date(String(v).replace(' ','T'))):'-';
const statusBadge=item=>{
    if(item.payment_pending)return '<span class="badge bg-info text-dark">EN ATTENTE OPÉRATEUR</span>';
    const state=item.status;
    const map={PENDING:['bg-warning text-dark','À PAYER'],PARTIALLY_PAID:['bg-warning text-dark','PARTIELLE'],PAID:['bg-success','PAYÉE'],CANCELLED:['bg-danger','ANNULÉE'],EXPIRED:['bg-secondary','EXPIRÉE']};
    const value=map[state]||['bg-secondary',state];return `<span class="badge ${value[0]}">${value[1]}</span>`;
};

async function load(){
    const status=$('statusFilter').value;
    const query=status?'?status='+encodeURIComponent(status):'';
    try{
        const response=await STAGIA.request(BASE+'/actions/payments/my-obligations.php'+query);
        items=response.data.items||[];const stats=response.data.stats||{};
        $('statTotal').textContent=stats.total||0;$('statPayable').textContent=stats.payable||0;
        $('statPending').textContent=stats.pending||0;$('statPaid').textContent=stats.paid||0;
        render();
    }catch(error){$('obligationBody').innerHTML=`<tr><td colspan="6" class="text-center text-danger py-5">${esc(error.message)}</td></tr>`;}
}

function render(){
    if(!items.length){$('obligationBody').innerHTML='<tr><td colspan="6" class="text-center text-muted py-5">Aucune obligation financière.</td></tr>';return;}
    $('obligationBody').innerHTML=items.map(item=>`<tr>
        <td><strong>${esc(item.label)}</strong><div class="small text-muted">${esc(item.type)}</div></td>
        <td><code>${esc(item.reference)}</code></td>
        <td><strong>${money(item.amount,item.currency)}</strong>${Number(item.amount_remaining)>0?`<div class="small text-muted">Reste : ${money(item.amount_remaining,item.currency)}</div>`:''}</td>
        <td>${dateFr(item.due_at)}</td><td>${statusBadge(item)}</td>
        <td class="text-center">${item.payable?`<button type="button" class="btn btn-sm btn-primary-stagia btn-pay" data-uuid="${esc(item.uuid)}"><i class="bi bi-credit-card me-1"></i>Payer</button>`:item.payment_pending?`<button type="button" class="btn btn-sm btn-outline-primary btn-sync" data-uuid="${esc(item.uuid)}"><i class="bi bi-arrow-repeat me-1"></i>Vérifier</button>`:''}</td>
    </tr>`).join('');
}

function openPayment(uuid){
    const item=items.find(x=>x.uuid===uuid);if(!item)return;
    $('paymentForm').reset();$('obligationUuid').value=item.uuid;
    $('idempotencyKey').value=crypto.randomUUID?crypto.randomUUID():'web-'+Date.now()+'-'+Math.random().toString(16).slice(2);
    $('paymentLabel').textContent=item.label;$('paymentAmount').textContent=money(item.amount_remaining,item.currency);modal.show();
}

async function sync(uuid,silent=false){
    const data=new FormData();data.set('csrf',csrf);data.set('obligation_uuid',uuid);
    try{
        const response=await STAGIA.post(BASE+'/actions/payments/my-payment-sync.php',data);
        const item=response.data||{};
        if(item.status==='PAID'){$('paymentNotice').classList.remove('d-none');$('paymentNotice').className='alert alert-success mx-3 mt-3';$('paymentNotice').textContent='Paiement confirmé avec succès.';stopPolling();}
        else if(item.payment_status==='FAILED'){$('paymentNotice').classList.remove('d-none');$('paymentNotice').className='alert alert-danger mx-3 mt-3';$('paymentNotice').textContent='Le paiement a échoué. Vous pouvez effectuer une nouvelle tentative.';stopPolling();}
        else if(!silent)STAGIA.toast('Le paiement attend encore la confirmation de l’opérateur.','info');
        await load();return item;
    }catch(error){if(!silent)STAGIA.toast(error.message,'danger');return null;}
}

function stopPolling(){if(pollTimer){clearInterval(pollTimer);pollTimer=null;}}
function startPolling(uuid){stopPolling();let checks=0;pollTimer=setInterval(async()=>{checks++;const item=await sync(uuid,true);if(item&&['PAID','CANCELLED','EXPIRED'].includes(item.status))stopPolling();if(checks>=24)stopPolling();},5000);}

$('obligationBody').addEventListener('click',event=>{const pay=event.target.closest('.btn-pay'),syncBtn=event.target.closest('.btn-sync');if(pay)openPayment(pay.dataset.uuid);if(syncBtn)sync(syncBtn.dataset.uuid);});
$('paymentForm').addEventListener('submit',async event=>{
    event.preventDefault();const button=$('submitPayment');STAGIA.loading(button,true);
    try{
        const response=await STAGIA.post(BASE+'/actions/payments/my-payment-initiate.php',new FormData(event.target));
        const uuid=$('obligationUuid').value;modal.hide();
        $('paymentNotice').className='alert alert-info mx-3 mt-3';$('paymentNotice').textContent='Demande envoyée. Validez-la avec votre code PIN sur votre téléphone; cette page vérifiera ensuite le résultat.';
        await load();startPolling(uuid);STAGIA.toast(response.message);
    }catch(error){STAGIA.toast(error.message,'danger');}finally{STAGIA.loading(button,false);}
});
$('statusFilter').addEventListener('change',load);$('refreshPayments').addEventListener('click',load);load();
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>


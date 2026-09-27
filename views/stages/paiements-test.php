<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$host=strtolower((string)($_SERVER['HTTP_HOST']??''));
$remote=(string)($_SERVER['REMOTE_ADDR']??'');
$isLocal=str_contains($host,'localhost')
    ||str_starts_with($host,'127.0.0.1')
    ||in_array($remote,['127.0.0.1','::1'],true);

if(!$isLocal){
    http_response_code(403);
    exit('Le simulateur de paiement est disponible uniquement en environnement local.');
}

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Paiements test';
$activePage='stages-payments-test';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Paiements — mode test local</h1>
        <p>Validez les paiements en attente pour tester le workflow complet avant l’intégration des opérateurs réels.</p>
    </div>
    <button class="btn btn-light border" id="refreshBtn">
        <i class="bi bi-arrow-clockwise me-1"></i>Actualiser
    </button>
</div>

<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Développement uniquement.</strong>
    Ce raccourci simule la confirmation de l’opérateur. Il doit rester inaccessible en production.
</div>

<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card">
        <div><span>EN ATTENTE</span><strong id="pendingCount">0</strong><small>Paiements à simuler</small></div>
        <div class="stagia-kpi-icon kpi-orange"><i class="bi bi-hourglass-split"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>VALIDÉS</span><strong id="validatedCount">0</strong><small>Paiements déjà confirmés</small></div>
        <div class="stagia-kpi-icon kpi-green"><i class="bi bi-check2-circle"></i></div>
    </div>
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar">
    <div>
        <h5 class="mb-1">Paiements étudiants</h5>
        <small class="text-muted">Un clic suffit pour simuler la réponse positive de l’opérateur.</small>
    </div>
</div>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>ÉTUDIANT</th>
    <th>CAMPAGNE</th>
    <th>HÔPITAL</th>
    <th>PAIEMENT</th>
    <th>MONTANT</th>
    <th>STATUT</th>
    <th class="text-end">ACTION</th>
</tr>
</thead>
<tbody id="rows">
<tr><td colspan="7" class="text-center py-5">Chargement...</td></tr>
</tbody>
</table>
</div>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id);
let items=[];

async function load(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/payment-simulate-list.php');
        items=r.data.items||[];
        $('pendingCount').textContent=r.data.pending||0;
        $('validatedCount').textContent=r.data.validated||0;
        render();
    }catch(e){
        $('rows').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;
    }
}

function render(){
    if(!items.length){
        $('rows').innerHTML='<tr><td colspan="7" class="text-center py-5 text-muted"><i class="bi bi-check2-circle fs-2 d-block mb-2"></i>Aucun paiement disponible.</td></tr>';
        return;
    }

    $('rows').innerHTML=items.map(x=>`
        <tr>
            <td>
                <strong>${STAGIA.escape(x.student_name)}</strong>
                <div class="small text-muted">${STAGIA.escape(x.stagia_code||'-')}</div>
            </td>
            <td>
                <strong>${STAGIA.escape(x.campaign_title)}</strong>
                <div class="small text-muted">${STAGIA.escape(x.campaign_code)}</div>
            </td>
            <td>${STAGIA.escape(x.hospital_name)}</td>
            <td>
                <strong>${STAGIA.escape(x.payment_reference)}</strong>
                <div class="small text-muted">${STAGIA.escape(x.operator||x.channel||'-')}</div>
            </td>
            <td><strong>${money(x.amount)} ${STAGIA.escape(x.currency)}</strong></td>
            <td>${statusBadge(x.status)}</td>
            <td class="text-end">
                ${x.status==='EN_ATTENTE'
                    ?`<button class="btn btn-sm btn-success validate-btn" data-id="${x.payment_id}">
                        <i class="bi bi-check-lg me-1"></i>Valider test
                      </button>`
                    :'<span class="small text-muted">Déjà validé</span>'}
            </td>
        </tr>
    `).join('');

    document.querySelectorAll('.validate-btn').forEach(btn=>btn.onclick=()=>validatePayment(Number(btn.dataset.id),btn));
}

function statusBadge(s){
    const m={
        EN_ATTENTE:['En attente','bg-warning text-dark'],
        VALIDE:['Validé','bg-success']
    }[s]||[s,'bg-secondary'];
    return `<span class="badge ${m[1]}">${STAGIA.escape(m[0])}</span>`;
}

async function validatePayment(id,btn){
    const x=items.find(v=>Number(v.payment_id)===id);
    if(!x)return;

    if(!STAGIA.confirm(
        `Simuler la validation du paiement ${x.payment_reference} de ${money(x.amount)} ${x.currency} ?`
    ))return;

    const fd=new FormData();
    fd.append('csrf','<?=htmlspecialchars($_SESSION['csrf'])?>');
    fd.append('payment_id',id);

    STAGIA.loading(btn,true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/stages/payment-simulate-success.php',fd);
        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading(btn,false);
    }
}

function money(v){
    return Number(v||0).toLocaleString('fr-FR',{
        minimumFractionDigits:0,
        maximumFractionDigits:2
    });
}

$('refreshBtn').onclick=load;
load();
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

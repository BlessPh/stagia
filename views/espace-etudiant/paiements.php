<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Mes paiements';
$activePage='student-payments';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- ENTÊTE -->
<div class="stagia-page-head">

    <div>
        <h1>Mes paiements</h1>

        <p>
            Consultez vos factures et suivez
            vos paiements de stage.
        </p>
    </div>

</div>


<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>STAGES</span>
            <strong id="statTotal">0</strong>
            <small>Stages confirmés</small>
        </div>
        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-briefcase"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>À PAYER</span>
            <strong id="statPending">0</strong>
            <small>Factures non réglées</small>
        </div>
        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>PAYÉES</span>
            <strong id="statPaid">0</strong>
            <small>Factures réglées</small>
        </div>
        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>SANS FRAIS</span>
            <strong id="statFree">0</strong>
            <small>Paiement non requis</small>
        </div>
        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-gift"></i>
        </div>
    </div>

</div>


<!-- LISTE -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>
            <h5 class="mb-1">
                Facturation des stages
            </h5>

            <small class="text-muted">
                Une facture est générée automatiquement
                lorsqu'un paiement est requis.
            </small>
        </div>

    </div>


    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">

            <thead>
            <tr>
                <th>STAGE</th>
                <th>HÔPITAL</th>
                <th>FACTURE</th>
                <th>MONTANT</th>
                <th>STATUT</th>
                <th class="text-center">ACTION</th>
            </tr>
            </thead>

            <tbody id="paymentBody">

            <tr>
                <td colspan="6"
                    class="text-center py-5">
                    Chargement...
                </td>
            </tr>

            </tbody>

        </table>

    </div>

</div>

</main>


<!-- MODAL PAIEMENT -->
<div class="modal fade"
     id="paymentModal"
     tabindex="-1">

<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<form id="paymentForm">

<div class="modal-header">

    <div>
        <h5 class="modal-title">
            Effectuer le paiement
        </h5>

        <small class="text-muted"
               id="invoiceLabel"></small>
    </div>

    <button type="button"
            class="btn-close"
            data-bs-dismiss="modal">
    </button>

</div>


<div class="modal-body">

    <input type="hidden"
           name="csrf"
           value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

    <input type="hidden"
           name="invoice_id"
           id="invoiceId">

    <input type="hidden"
           name="idempotency_key"
           id="paymentIdempotencyKey">


    <div class="mb-3">

        <label class="form-label">
            Canal de paiement *
        </label>

        <select name="canal"
                id="paymentChannel"
                class="form-select"
                required>

            <option value="">
                Sélectionner...
            </option>

            <option value="MPESA">
                M-Pesa
            </option>

            <option value="ORANGE_MONEY">
                Orange Money
            </option>

            <option value="AIRTEL_MONEY">
                Airtel Money
            </option>

            <option value="AFRIMONEY">
                Afrimoney
            </option>

            <option value="BANQUE">
                Banque
            </option>

            <option value="CARTE">
                Carte bancaire
            </option>

        </select>

    </div>


    <div id="phoneBox">

        <label class="form-label">
            Numéro de téléphone
        </label>

        <input type="tel"
               name="phone_number"
               id="phoneNumber"
               class="form-control"
               placeholder="Ex. 0812345678">

    </div>


    <div class="alert alert-light border mt-3 mb-0">

        Montant à payer :

        <strong id="amountLabel">
            -
        </strong>

    </div>

</div>


<div class="modal-footer">

    <button type="button"
            class="btn btn-light"
            data-bs-dismiss="modal">
        Annuler
    </button>

    <button type="submit"
            class="btn btn-primary-stagia"
            id="payBtn">

        <i class="bi bi-credit-card me-1"></i>
        Payer

    </button>

</div>

</form>

</div>
</div>
</div>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id);

const modal=
    new bootstrap.Modal(
        $('paymentModal')
    );

let items=[];


/* CHARGER */
async function charger(){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-payment-list.php'
        );

        items=r.data.items||[];

        const s=r.data.stats||{};

        $('statTotal').textContent=s.total||0;
        $('statPending').textContent=s.a_payer||0;
        $('statPaid').textContent=s.payees||0;
        $('statFree').textContent=s.sans_frais||0;

        render();

    }catch(e){

        $('paymentBody').innerHTML=`
            <tr>
                <td colspan="6"
                    class="text-center py-5 text-danger">
                    ${STAGIA.escape(e.message)}
                </td>
            </tr>
        `;
    }
}


/* TABLE */
function render(){

    if(!items.length){

        $('paymentBody').innerHTML=`
            <tr>
                <td colspan="6"
                    class="text-center py-5 text-muted">
                    Aucun paiement disponible.
                </td>
            </tr>
        `;

        return;
    }


    $('paymentBody').innerHTML=
        items.map(x=>{

            const invoice=x.invoice;

            return `
                <tr>

                    <td>
                        <strong>
                            ${STAGIA.escape(x.campaign_title||'-')}
                        </strong>

                        <div class="small text-muted">
                            ${STAGIA.escape(x.campaign_code||'-')}
                        </div>
                    </td>


                    <td>
                        <strong>
                            ${STAGIA.escape(x.host_name||'-')}
                        </strong>
                    </td>


                    <td>
                        ${
                            invoice
                            ?`
                                <strong>
                                    ${STAGIA.escape(invoice.reference)}
                                </strong>

                                <div class="small text-muted">
                                    ${dateFr(invoice.date_emission)}
                                </div>
                            `
                            :`
                                <span class="text-muted">
                                    Non requise
                                </span>
                            `
                        }
                    </td>


                    <td>
                        ${
                            invoice
                            ?`
                                <strong>
                                    ${money(invoice.montant)}
                                    ${STAGIA.escape(invoice.devise)}
                                </strong>

                                ${
                                    Number(x.remaining)>0
                                    ?`
                                        <div class="small text-muted">
                                            Reste :
                                            ${money(x.remaining)}
                                            ${STAGIA.escape(invoice.devise)}
                                        </div>
                                    `
                                    :''
                                }
                            `
                            :'-'
                        }
                    </td>


                    <td>
                        ${paymentBadge(x)}
                    </td>


                    <td class="text-center">

                        ${
                            x.payment_required &&
                            x.payment_allowed &&
                            invoice &&
                            Number(x.remaining)>0 &&
                            ['EMISE','PARTIELLEMENT_PAYEE'].includes(x.payment_status)

                            ?`
                                <button type="button"
                                        class="btn btn-sm
                                               btn-primary-stagia
                                               btn-pay"
                                        data-id="${Number(invoice.id)}">

                                    <i class="bi bi-credit-card me-1"></i>
                                    Payer

                                </button>
                            `

                            :x.payment_required && invoice && !x.payment_allowed
                                ?`<span class="small text-muted"><i class="bi bi-clock me-1"></i>En attente</span>`
                                :''
                        }

                    </td>

                </tr>
            `;

        }).join('');
}


/* BADGE */
function paymentBadge(x){

    if(!x.payment_required)
        return `
            <span class="badge bg-info text-dark">
                NON REQUIS
            </span>
        `;


    const map={

        EMISE:
            ['bg-warning text-dark','À PAYER'],

        PARTIELLEMENT_PAYEE:
            ['bg-warning text-dark','PARTIEL'],

        PAYEE:
            ['bg-success','PAYÉE'],

        ANNULEE:
            ['bg-danger','ANNULÉE'],

        EXPIREE:
            ['bg-secondary','EXPIRÉE']

    };


    const v=
        map[x.payment_status]||
        ['bg-secondary',x.payment_status];


    return `
        <span class="badge ${v[0]}">
            ${v[1]}
        </span>
    `;
}


/* OUVRIR PAIEMENT */
$('paymentBody')
.addEventListener('click',e=>{

    const btn=
        e.target.closest('.btn-pay');

    if(!btn)
        return;


    const x=items.find(v=>
        v.invoice &&
        Number(v.invoice.id)===
        Number(btn.dataset.id)
    );


    if(!x)
        return;


    $('paymentForm').reset();

    $('invoiceId').value=
        x.invoice.id;

    $('paymentIdempotencyKey').value=
        window.crypto&&typeof window.crypto.randomUUID==='function'
            ?window.crypto.randomUUID()
            :'web-'+Date.now()+'-'+Math.random().toString(16).slice(2);

    $('invoiceLabel').textContent=
        x.invoice.reference;

    $('amountLabel').textContent=
        money(x.remaining)+
        ' '+
        x.invoice.devise;


    updatePhone();

    modal.show();
});


/* CANAL */
$('paymentChannel')
.addEventListener(
    'change',
    updatePhone
);


function updatePhone(){

    const mobile=[
        'MPESA',
        'ORANGE_MONEY',
        'AIRTEL_MONEY',
        'AFRIMONEY'
    ].includes(
        $('paymentChannel').value
    );


    $('phoneBox')
        .classList.toggle(
            'd-none',
            !mobile
        );

    $('phoneNumber').required=
        mobile;
}


/* PAYER */
$('paymentForm')
.addEventListener('submit',async e=>{

    e.preventDefault();

    const btn=$('payBtn');

    STAGIA.loading(btn,true);

    try{

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/etudiants/student-payment-initiate.php',
            new FormData(e.target)
        );

        modal.hide();

        STAGIA.toast(
            r.message
        );

        await charger();

    }catch(e){

        STAGIA.toast(
            e.message,
            'danger'
        );

    }finally{

        STAGIA.loading(
            btn,
            false
        );
    }
});


function dateFr(v){

    if(!v) return '-';

    const p=
        String(v)
        .substring(0,10)
        .split('-');

    return p.length===3
        ?`${p[2]}/${p[1]}/${p[0]}`
        :v;
}


function money(v){

    return Number(v||0)
        .toLocaleString(
            'fr-FR',
            {
                minimumFractionDigits:0,
                maximumFractionDigits:2
            }
        );
}


charger();

});
</script>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>

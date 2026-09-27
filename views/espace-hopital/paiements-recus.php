<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL']);

$hostId=currentEtablissementId($pdo);

if(!$hostId)
    exit('Aucun établissement associé.');

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Paiements reçus';
$activePage='hospital-payments';

/* Boutons simulation uniquement en local */
$server=strtolower($_SERVER['SERVER_NAME']??'');

$isLocal=in_array(
    $server,
    ['localhost','127.0.0.1'],
    true
);

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- =========================================================
     ENTÊTE
========================================================= -->
<div class="stagia-page-head">

    <div>

        <h1>Paiements reçus</h1>

        <p>
            Suivez les factures et confirmations
            de paiement des stagiaires.
        </p>

    </div>

</div>


<!-- =========================================================
     KPI
========================================================= -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">

        <div>
            <span>FACTURES</span>
            <strong id="statTotal">0</strong>
            <small>Factures générées</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-receipt"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>À ENCAISSER</span>
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
            <i class="bi bi-patch-check"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>EN ATTENTE</span>
            <strong id="statWaiting">0</strong>
            <small>Confirmations opérateur</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-arrow-repeat"></i>
        </div>

    </div>

</div>


<!-- =========================================================
     LISTE
========================================================= -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>

            <h5 class="mb-1">
                Suivi des paiements
            </h5>

            <small class="text-muted">
                Factures émises et dernières tentatives.
            </small>

        </div>


        <div style="max-width:320px;width:100%">

            <input type="search"
                   id="paymentSearch"
                   class="form-control"
                   placeholder="Rechercher...">

        </div>

    </div>


    <?php if($isLocal): ?>

    <div class="alert alert-warning rounded-0 border-0 mb-0 py-2">

        <i class="bi bi-exclamation-triangle me-1"></i>

        <strong>Mode développement :</strong>
        les boutons de simulation sont actifs uniquement sur localhost.

    </div>

    <?php endif; ?>


    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">

            <thead>

            <tr>
                <th>STAGIAIRE</th>
                <th>UNIVERSITÉ / STAGE</th>
                <th>FACTURE</th>
                <th>MONTANT</th>
                <th>PAIEMENT</th>
                <th>STATUT</th>
                <th class="text-center">ACTION</th>
            </tr>

            </thead>


            <tbody id="paymentBody">

            <tr>

                <td colspan="7"
                    class="text-center py-5">

                    Chargement...

                </td>

            </tr>

            </tbody>

        </table>

    </div>

</div>

</main>

<div class="modal fade" id="cashPaymentModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">
<form id="cashPaymentForm">
<div class="modal-header">
    <div>
        <h5 class="modal-title">Effectuer le paiement</h5>
        <small class="text-muted" id="cashPaymentSubtitle">Encaissement de la facture</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>">
    <input type="hidden" name="invoice_id" id="cashInvoiceId">

    <div class="alert alert-light border py-2">
        <div class="small text-muted">Facture</div>
        <strong id="cashInvoiceRef">-</strong>
        <div class="small text-muted mt-1" id="cashStudentLabel"></div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Montant encaissé *</label>
            <input type="number" step="0.01" min="0.01" name="amount" id="cashAmount" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Devise</label>
            <input type="text" id="cashCurrency" class="form-control" readonly>
        </div>
        <div class="col-md-6">
            <label class="form-label">Mode de paiement *</label>
            <select name="canal" id="cashCanal" class="form-select" required>
                <option value="ESPECES">Espèces</option>
                <option value="MOBILE_MONEY">Mobile money</option>
                <option value="BANQUE">Banque</option>
                <option value="AUTRE">Autre</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">Référence</label>
            <input name="payment_reference" id="cashReference" class="form-control" placeholder="Ex. reçu, transaction, caisse...">
        </div>
        <div class="col-md-6">
            <label class="form-label">Téléphone</label>
            <input name="phone_number" id="cashPhone" class="form-control" placeholder="Optionnel">
        </div>
        <div class="col-md-6">
            <label class="form-label">Opérateur</label>
            <input name="operateur" id="cashOperator" class="form-control" placeholder="Ex. Orange Money, M-Pesa...">
        </div>
        <div class="col-12">
            <label class="form-label">Observation</label>
            <textarea name="observation" id="cashObservation" class="form-control" rows="2" placeholder="Note interne optionnelle"></textarea>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="cashSaveBtn">
        <i class="bi bi-check-lg me-1"></i> Valider le paiement
    </button>
</div>
</form>
</div>
</div>
</div>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      CSRF='<?= $_SESSION['csrf'] ?>',
      IS_LOCAL=<?= $isLocal?'true':'false' ?>,
      $=id=>document.getElementById(id);

let items=[];


/* =========================================================
   CHARGER
========================================================= */
async function charger(){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/payments/host-payment-list.php'
        );


        items=r.data.items||[];


        const s=r.data.stats||{};


        $('statTotal').textContent=
            s.total||0;

        $('statPending').textContent=
            s.a_encaisser||0;

        $('statPaid').textContent=
            s.payees||0;

        $('statWaiting').textContent=
            s.en_attente||0;


        afficher();


    }catch(e){

        $('paymentBody').innerHTML=`

            <tr>

                <td colspan="7"
                    class="text-center py-5 text-danger">

                    ${STAGIA.escape(e.message)}

                </td>

            </tr>
        `;
    }
}


/* =========================================================
   RECHERCHE
========================================================= */
function afficher(){

    const q=$('paymentSearch')
        .value
        .trim()
        .toLowerCase();


    const list=items.filter(x=>{

        if(!q)
            return true;


        return [
            x.nom,
            x.postnom,
            x.prenom,
            x.stagia_code,
            x.university_name,
            x.campaign_title,
            x.invoice_reference,
            x.payment_reference,
            x.transaction_reference,
            x.canal,
            x.operateur
        ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(q);
    });


    render(list);
}


/* =========================================================
   TABLEAU
========================================================= */
function render(list){

    if(!list.length){

        $('paymentBody').innerHTML=`

            <tr>

                <td colspan="7"
                    class="text-center py-5 text-muted">

                    <i class="bi bi-receipt
                              fs-2
                              d-block
                              mb-2"></i>

                    Aucun paiement à afficher.

                </td>

            </tr>
        `;

        return;
    }


    $('paymentBody').innerHTML=
        list.map(x=>`

            <tr>

                <!-- STAGIAIRE -->
                <td>

                    <strong>

                        ${STAGIA.escape(
                            [
                                x.nom,
                                x.postnom,
                                x.prenom
                            ]
                            .filter(Boolean)
                            .join(' ')
                        )}

                    </strong>


                    <div class="small text-muted">

                        ${STAGIA.escape(
                            x.stagia_code||'-'
                        )}

                    </div>

                </td>


                <!-- UNIVERSITÉ / STAGE -->
                <td>

                    <strong>
                        ${STAGIA.escape(
                            x.university_name||'-'
                        )}
                    </strong>


                    <div class="small text-muted">

                        ${STAGIA.escape(
                            x.campaign_title||'-'
                        )}

                    </div>

                </td>


                <!-- FACTURE -->
                <td>

                    <strong>
                        ${STAGIA.escape(
                            x.invoice_reference||'-'
                        )}
                    </strong>


                    <div class="small text-muted">

                        ${dateTime(
                            x.date_emission
                        )}

                    </div>

                </td>


                <!-- MONTANT -->
                <td>

                    <strong>

                        ${money(
                            x.invoice_amount
                        )}

                        ${STAGIA.escape(
                            x.invoice_currency||''
                        )}

                    </strong>


                    ${
                        Number(x.remaining)>0

                        ?`
                            <div class="small text-muted">

                                Reste :
                                ${money(x.remaining)}
                                ${STAGIA.escape(
                                    x.invoice_currency||''
                                )}

                            </div>
                        `

                        :`
                            <div class="small text-success">
                                Soldée
                            </div>
                        `
                    }

                </td>


                <!-- PAIEMENT -->
                <td>

                    ${
                        x.payment_id

                        ?`
                            <strong>

                                ${STAGIA.escape(
                                    x.payment_reference||'-'
                                )}

                            </strong>


                            <div class="small text-muted">

                                ${STAGIA.escape(
                                    x.operateur||
                                    x.canal||
                                    '-'
                                )}

                            </div>


                            ${
                                x.phone_number

                                ?`
                                    <div class="small text-muted">

                                        ${STAGIA.escape(
                                            x.phone_number
                                        )}

                                    </div>
                                `

                                :''
                            }
                        `

                        :`
                            <span class="text-muted">
                                Aucun paiement initié
                            </span>
                        `
                    }

                </td>


                <!-- STATUT -->
                <td>

                    ${invoiceBadge(
                        x.invoice_status
                    )}

                    ${
                        x.payment_status

                        ?`
                            <div class="mt-1">

                                ${paymentBadge(
                                    x.payment_status
                                )}

                            </div>
                        `

                        :''
                    }

                </td>


                <!-- ACTION -->
                <td class="text-center">

                    ${actions(x)}

                </td>

            </tr>

        `).join('');
}


/* =========================================================
   ACTIONS
========================================================= */
function actions(x){

    const invoiceStatus=String(x.invoice_status||'');
    const remaining=Number(x.remaining||0);
    const invoiceId=Number(x.invoice_id||0);

    if(invoiceId && remaining>0 && ['EMISE','PARTIELLEMENT_PAYEE'].includes(invoiceStatus)){
        return `
            <button type="button"
                    class="btn btn-sm btn-primary-stagia btn-cash"
                    data-id="${invoiceId}">
                <i class="bi bi-cash-coin me-1"></i>Encaisser
            </button>
        `;
    }

    if(
        IS_LOCAL &&
        x.payment_id &&
        x.payment_status==='EN_ATTENTE'
    ){
        return `
            <div class="d-flex justify-content-center gap-1">
                <button type="button" class="btn btn-sm btn-outline-success btn-simulate" data-id="${Number(x.payment_id)}" data-result="VALIDE" title="Valider paiement test">
                    <i class="bi bi-check-lg"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger btn-simulate" data-id="${Number(x.payment_id)}" data-result="ECHOUE" title="Marquer échoué">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        `;
    }

    return `<span class="text-muted small">-</span>`;
}


/* =========================================================
   SIMULATION
========================================================= */
$('paymentBody')
.addEventListener('click',async e=>{

    const btn=
        e.target.closest(
            '.btn-simulate'
        );


    if(!btn)
        return;


    const result=
        btn.dataset.result;


    const label=
        result==='VALIDE'
            ?'VALIDER'
            :'MARQUER COMME ÉCHOUÉ';


    if(
        !confirm(
            label+
            ' ce paiement de test ?'
        )
    ){
        return;
    }


    const data=new FormData();


    data.append(
        'csrf',
        CSRF
    );


    data.append(
        'payment_id',
        btn.dataset.id
    );


    data.append(
        'result',
        result
    );


    btn.disabled=true;


    try{

        const r=await STAGIA.post(

            BASE_URL+
            '/actions/payments/simulate-callback.php',

            data
        );


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

        btn.disabled=false;
    }
});


/* =========================================================
   ENCAISSEMENT DIRECT
========================================================= */
const cashModalEl=$('cashPaymentModal'),
      cashModal=cashModalEl?new bootstrap.Modal(cashModalEl):null,
      cashForm=$('cashPaymentForm');

$('paymentBody').addEventListener('click',e=>{
    const btn=e.target.closest('.btn-cash');
    if(!btn||!cashModal)return;

    const x=items.find(v=>Number(v.invoice_id)===Number(btn.dataset.id));
    if(!x)return;

    const name=[x.nom,x.postnom,x.prenom].filter(Boolean).join(' ')||'Stagiaire';
    $('cashPaymentForm').reset();
    $('cashInvoiceId').value=Number(x.invoice_id||0);
    $('cashInvoiceRef').textContent=x.invoice_reference||'-';
    $('cashStudentLabel').textContent=name+' · Reste : '+money(x.remaining)+' '+(x.invoice_currency||'');
    $('cashPaymentSubtitle').textContent='Paiement de '+name;
    $('cashAmount').value=Number(x.remaining||0).toFixed(2);
    $('cashAmount').max=Number(x.remaining||0).toFixed(2);
    $('cashCurrency').value=x.invoice_currency||'';
    cashModal.show();
});

if(cashForm){
    cashForm.addEventListener('submit',async e=>{
        e.preventDefault();
        const btn=$('cashSaveBtn');
        STAGIA.loading(btn,true);

        try{
            const r=await STAGIA.post(BASE_URL+'/actions/payments/host-cash-payment.php',new FormData(cashForm));
            cashModal.hide();
            STAGIA.toast(r.message||'Paiement enregistré.');
            await charger();
            window.dispatchEvent(new CustomEvent('stagia:force-notification-refresh'));
        }catch(e){
            STAGIA.toast(e.message,'danger');
        }finally{
            STAGIA.loading(btn,false);
        }
    });
}


/* =========================================================
   BADGE FACTURE
========================================================= */
function invoiceBadge(s){

    const map={

        EMISE:[
            'bg-warning text-dark',
            'À PAYER'
        ],

        PARTIELLEMENT_PAYEE:[
            'bg-warning text-dark',
            'PARTIELLE'
        ],

        PAYEE:[
            'bg-success',
            'PAYÉE'
        ],

        ANNULEE:[
            'bg-danger',
            'ANNULÉE'
        ],

        EXPIREE:[
            'bg-secondary',
            'EXPIRÉE'
        ]
    };


    const x=
        map[s]||
        ['bg-secondary',s||'-'];


    return `
        <span class="badge ${x[0]}">
            ${x[1]}
        </span>
    `;
}


/* =========================================================
   BADGE PAIEMENT
========================================================= */
function paymentBadge(s){

    const map={

        INITIE:[
            'bg-secondary',
            'INITIÉ'
        ],

        EN_ATTENTE:[
            'bg-info text-dark',
            'EN ATTENTE'
        ],

        VALIDE:[
            'bg-success',
            'VALIDÉ'
        ],

        ECHOUE:[
            'bg-danger',
            'ÉCHOUÉ'
        ],

        ANNULE:[
            'bg-secondary',
            'ANNULÉ'
        ],

        REMBOURSE:[
            'bg-dark',
            'REMBOURSÉ'
        ]
    };


    const x=
        map[s]||
        ['bg-secondary',s||'-'];


    return `
        <span class="badge ${x[0]}">
            ${x[1]}
        </span>
    `;
}


/* =========================================================
   HELPERS
========================================================= */
function money(v){

    return Number(v||0)
        .toLocaleString(
            'fr-FR',
            {
                maximumFractionDigits:2
            }
        );
}


function dateTime(v){

    if(!v)
        return '-';


    return new Date(
        String(v).replace(' ','T')
    ).toLocaleString(
        'fr-FR'
    );
}


/* RECHERCHE */
$('paymentSearch')
.addEventListener(
    'input',
    afficher
);


/* INIT */
charger();

});
</script>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
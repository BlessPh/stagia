<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));
requireRole(['STAGIAIRE']);

$pageTitle='Mes réservations';
$activePage='student-reservations';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- ENTÊTE -->
<div class="stagia-page-head">

    <div>
        <h1>Mes réservations</h1>

        <p>
            Suivez les places réservées pour vos stages.
        </p>
    </div>

</div>


<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>TOTAL</span>
            <strong id="statTotal">0</strong>
            <small>Toutes mes réservations</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-calendar-check"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>TEMPORAIRES</span>
            <strong id="statTemporary">0</strong>
            <small>Places temporairement bloquées</small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>PAIEMENT</span>
            <strong id="statPayment">0</strong>
            <small>En attente de paiement</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-credit-card"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>CONFIRMÉES</span>
            <strong id="statConfirmed">0</strong>
            <small>Places définitivement confirmées</small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>

</div>


<!-- LISTE -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>
            <h5 class="mb-1">
                Historique des réservations
            </h5>

            <small class="text-muted">
                Campagnes et établissements sélectionnés.
            </small>
        </div>

    </div>


    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">

            <thead>
                <tr>
                    <th>CAMPAGNE</th>
                    <th>HÔPITAL</th>
                    <th>STATUT</th>
                    <th>EXPIRATION</th>
                    <th>FRAIS</th>
                    <th class="text-center">ACTION</th>
                </tr>
            </thead>

            <tbody id="reservationBody">

                <tr>
                    <td colspan="6"
                        class="text-center py-5">

                        <div class="spinner-border
                                    spinner-border-sm me-2"></div>

                        Chargement...

                    </td>
                </tr>

            </tbody>

        </table>

    </div>

</div>

</main>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id);


/* =====================================================
   CHARGER
===================================================== */
async function charger(){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/stages/student-reservation-list.php'
        );

        const items=r.data.items||[];
        const s=r.data.stats||{};

        $('statTotal').textContent=s.total||0;
        $('statTemporary').textContent=s.temporaires||0;
        $('statPayment').textContent=s.paiement||0;
        $('statConfirmed').textContent=s.confirmees||0;

        afficher(items);

    }catch(e){

        $('reservationBody').innerHTML=`
            <tr>
                <td colspan="6"
                    class="text-center py-5 text-danger">

                    ${STAGIA.escape(e.message)}

                </td>
            </tr>
        `;
    }
}


/* =====================================================
   AFFICHAGE
===================================================== */
function afficher(items){

    const body=$('reservationBody');

    if(!items.length){

        body.innerHTML=`
            <tr>

                <td colspan="6"
                    class="text-center py-5 text-muted">

                    <i class="bi bi-calendar-x
                              fs-2 d-block mb-2"></i>

                    Aucune réservation enregistrée.

                </td>

            </tr>
        `;

        return;
    }


    body.innerHTML=items.map(x=>`

        <tr>

            <!-- CAMPAGNE -->
            <td>

                <strong>
                    ${STAGIA.escape(x.campaign_title)}
                </strong>

                <div class="small text-muted">
                    ${STAGIA.escape(x.campaign_code)}
                </div>

            </td>


            <!-- HÔPITAL -->
            <td>

                <strong>
                    ${STAGIA.escape(x.hospital_name)}
                </strong>

                <div class="small text-muted">

                    ${STAGIA.escape(
                        [x.ville,x.province]
                        .filter(Boolean)
                        .join(', ')||'-'
                    )}

                </div>

            </td>


            <!-- STATUT -->
            <td>

                <span class="badge ${badge(x.statut)}">

                    ${label(x.statut)}

                </span>

            </td>


            <!-- EXPIRATION -->
            <td>

                ${
                    x.statut==='RESERVEE_TEMPORAIREMENT'

                    ?`
                        <div>
                            ${dateTime(x.expires_at)}
                        </div>

                        <small class="text-muted">
                            Réservation temporaire
                        </small>
                    `

                    :'-'
                }

            </td>


            <!-- FRAIS -->
            <td>

                ${
                    x.frais_requis

                    ?`
                        <strong>
                            ${Number(x.montant_frais||0)}
                            ${STAGIA.escape(x.devise||'')}
                        </strong>
                    `

                    :`
                        <span class="text-muted">
                            Aucun
                        </span>
                    `
                }

            </td>


            <!-- ACTION -->
            <td class="text-center">

                ${action(x)}

            </td>

        </tr>

    `).join('');
}


/* =====================================================
   ACTION SELON STATUT
===================================================== */
function action(x){

    if(x.statut==='RESERVEE_TEMPORAIREMENT'){

        return `
            <button type="button"
                    class="btn btn-sm btn-primary-stagia"
                    disabled>

                <i class="bi bi-hourglass-split me-1"></i>
                Décision universitaire en attente

            </button>
        `;
    }


    if(x.statut==='EN_ATTENTE_PAIEMENT'){

        return `
            <button type="button"
                    class="btn btn-sm btn-primary-stagia"
                    disabled>

                <i class="bi bi-credit-card me-1"></i>
                Payer

            </button>
        `;
    }


    if(x.statut==='CONFIRMEE'){

        return `
            <span class="text-success fw-semibold">

                <i class="bi bi-check-circle-fill me-1"></i>
                Confirmée

            </span>
        `;
    }


    if(x.statut==='EXPIREE'){

        return `
            <span class="text-muted">
                Expirée
            </span>
        `;
    }


    if(x.statut==='ANNULEE'){

        return `
            <span class="text-danger">
                Annulée
            </span>
        `;
    }


    return '-';
}


/* =====================================================
   LIBELLÉS
===================================================== */
function label(s){

    return {

        RESERVEE_TEMPORAIREMENT:
            'RÉSERVÉE TEMPORAIREMENT',

        EN_ATTENTE_PAIEMENT:
            'EN ATTENTE PAIEMENT',

        CONFIRMEE:
            'CONFIRMÉE',

        EXPIREE:
            'EXPIRÉE',

        ANNULEE:
            'ANNULÉE'

    }[s]||s;
}


function badge(s){

    return {

        RESERVEE_TEMPORAIREMENT:
            'bg-warning text-dark',

        EN_ATTENTE_PAIEMENT:
            'bg-primary',

        CONFIRMEE:
            'bg-success',

        EXPIREE:
            'bg-secondary',

        ANNULEE:
            'bg-danger'

    }[s]||'bg-secondary';
}


/* =====================================================
   DATE
===================================================== */
function dateTime(v){

    if(!v)
        return '-';

    const d=new Date(
        v.replace(' ','T')
    );

    return d.toLocaleString(
        'fr-FR'
    );
}


/* INITIALISATION */
charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

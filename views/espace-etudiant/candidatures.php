<?php

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

$pageTitle='Mes candidatures';
$activePage='student-applications';

require_once __DIR__.'/../../includes/app-header.php';
?>


<main class="dashboard-content">


<div class="stagia-page-head">

    <div>

        <h1>Mes candidatures</h1>

        <p>
            Suivez vos demandes de stage
            et leur état de traitement.
        </p>

    </div>

</div>


<!-- KPI -->

<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">

        <div>
            <span>CANDIDATURES</span>
            <strong id="statTotal">0</strong>
            <small>Total enregistré</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-send"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>SOUMISES</span>
            <strong id="statSubmitted">0</strong>
            <small>Demandes envoyées</small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-hourglass-split"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>ADMISSIONS</span>
            <strong id="statAdmissions">0</strong>
            <small>Stages admis</small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>

    </div>


    <div class="stagia-kpi-card">

        <div>
            <span>TERMINÉS</span>
            <strong id="statCompleted">0</strong>
            <small>Stages validés</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-patch-check"></i>
        </div>

    </div>

</div>


<!-- LISTE -->

<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>

            <h5 class="mb-1">
                Historique des candidatures
            </h5>

            <small class="text-muted">
                Toutes vos demandes de stage.
            </small>

        </div>


        <div style="max-width:320px;width:100%">

            <input
                type="search"
                id="search"
                class="form-control"
                placeholder="Rechercher..."
            >

        </div>

    </div>


    <div id="applicationContainer">

        <div class="text-center py-5">

            <div class="spinner-border spinner-border-sm me-2"></div>

            Chargement...

        </div>

    </div>

</div>


</main>


<script>

document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id);

let items=[];


/* =========================================================
   CHARGEMENT
========================================================= */

async function charger(){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-application-list.php'
        );

        items=r.data.items||[];


        $('statTotal').textContent=
            items.length;


        $('statSubmitted').textContent=
            items.filter(
                x=>x.statut==='SOUMISE'
            ).length;


        $('statAdmissions').textContent=
            items.filter(
                x=>x.admission_id
            ).length;


        $('statCompleted').textContent=
            items.filter(
                x=>x.completion_status==='VALIDE'
            ).length;


        afficher();


    }catch(e){

        $('applicationContainer').innerHTML=`

            <div class="text-center py-5 text-danger">

                ${STAGIA.escape(e.message)}

            </div>
        `;
    }
}


/* =========================================================
   RECHERCHE
========================================================= */

function afficher(){

    const q=
        $('search')
        .value
        .trim()
        .toLowerCase();


    const list=
        items.filter(x=>{

            if(!q)
                return true;


            return [

                x.campaign_code,
                x.campaign_title,
                x.host_name,
                x.ville,
                x.province,
                x.statut,
                x.reservation_status

            ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(q);

        });


    render(list);
}


/* =========================================================
   AFFICHAGE
========================================================= */

function render(list){

    if(!list.length){

        $('applicationContainer').innerHTML=`

            <div class="text-center py-5 text-muted">

                <i class="bi bi-send fs-1 d-block mb-3"></i>

                <h5>
                    Aucune candidature
                </h5>

                <p class="mb-0">
                    Vos demandes de stage apparaîtront ici.
                </p>

            </div>
        `;

        return;
    }


    $('applicationContainer').innerHTML=

        `<div class="p-3">`

        +

        list.map(x=>`

            <div class="border rounded-3 p-3 mb-3 bg-white">


                <div class="d-flex
                            justify-content-between
                            align-items-start
                            gap-3
                            flex-wrap">


                    <div>

                        <div class="small text-muted">

                            ${STAGIA.escape(
                                x.campaign_code||'-'
                            )}

                        </div>


                        <h5 class="mb-1">

                            ${STAGIA.escape(
                                x.campaign_title||'-'
                            )}

                        </h5>


                        <div class="small text-muted">

                            <i class="bi bi-hospital me-1"></i>

                            ${STAGIA.escape(
                                x.host_name||'-'
                            )}

                            ${x.ville
                                ?' • '+STAGIA.escape(x.ville)
                                :''
                            }

                        </div>

                    </div>


                    <div class="text-end">

                        ${workflowBadge(x)}

                    </div>

                </div>


                <div class="row g-3 mt-2">


                    <div class="col-md-3">

                        <small class="text-muted d-block">
                            Candidature
                        </small>

                        <strong>
                            ${labelApplication(
                                x.statut
                            )}
                        </strong>

                    </div>


                    <div class="col-md-3">

                        <small class="text-muted d-block">
                            Réservation
                        </small>

                        <strong>
                            ${labelReservation(
                                x.reservation_status
                            )}
                        </strong>

                    </div>


                    <div class="col-md-3">

                        <small class="text-muted d-block">
                            Date de soumission
                        </small>

                        <strong>
                            ${formatDateTime(
                                x.submitted_at
                            )}
                        </strong>

                    </div>


                    <div class="col-md-3">

                        <small class="text-muted d-block">
                            Période du stage
                        </small>

                        <strong>

                            ${formatDate(x.date_debut)}

                            →

                            ${formatDate(x.date_fin)}

                        </strong>

                    </div>


                </div>


                ${
                    x.motivation

                    ?`

                    <div class="mt-3">

                        <small class="text-muted">
                            Motivation / observation
                        </small>

                        <div>
                            ${STAGIA.escape(
                                x.motivation
                            )}
                        </div>

                    </div>

                    `

                    :''
                }


                ${
                    x.motif_refus

                    ?`

                    <div class="alert alert-danger mt-3 mb-0">

                        <strong>
                            Motif du refus :
                        </strong>

                        ${STAGIA.escape(
                            x.motif_refus
                        )}

                    </div>

                    `

                    :''
                }


            </div>

        `).join('')

        +

        `</div>`;
}


/* =========================================================
   ÉTAPE GLOBALE
========================================================= */

function workflowBadge(x){

    if(
        x.completion_status==='VALIDE'
    ){

        return `
            <span class="badge bg-success">
                STAGE VALIDÉ
            </span>
        `;
    }


    if(
        x.assignment_status==='TERMINEE'
    ){

        return `
            <span class="badge bg-primary">
                STAGE TERMINÉ
            </span>
        `;
    }


    if(
        x.assignment_status==='ACTIVE'
    ){

        return `
            <span class="badge bg-success">
                STAGE EN COURS
            </span>
        `;
    }


    if(
        x.assignment_id
    ){

        return `
            <span class="badge bg-primary">
                STAGE PLANIFIÉ
            </span>
        `;
    }


    if(
        x.admission_id
    ){

        return `
            <span class="badge bg-success">
                ADMIS
            </span>
        `;
    }


    if(
        x.reservation_status==='CONFIRMEE'
    ){

        return `
            <span class="badge bg-success">
                PLACE CONFIRMÉE
            </span>
        `;
    }


    if(
        x.reservation_status==='EN_ATTENTE_PAIEMENT'
    ){

        return `
            <span class="badge bg-warning text-dark">
                PAIEMENT ATTENDU
            </span>
        `;
    }


    if(
        x.reservation_status==='RESERVEE_TEMPORAIREMENT'
    ){

        return `
            <span class="badge bg-warning text-dark">
                PLACE RÉSERVÉE
            </span>
        `;
    }


    if(
        x.statut==='REFUSEE'
    ){

        return `
            <span class="badge bg-danger">
                REFUSÉE
            </span>
        `;
    }


    return `
        <span class="badge bg-secondary">
            ${STAGIA.escape(
                labelApplication(
                    x.statut
                )
            )}
        </span>
    `;
}


/* =========================================================
   LIBELLÉS
========================================================= */

function labelApplication(v){

    return {

        BROUILLON:'Brouillon',

        SOUMISE:'Soumise',

        EN_EXAMEN:'En examen',

        ACCEPTEE:'Acceptée',

        REFUSEE:'Refusée',

        ANNULEE:'Annulée'

    }[v]||v||'-';
}


function labelReservation(v){

    return {

        RESERVEE_TEMPORAIREMENT:
            'Réservée temporairement',

        EN_ATTENTE_PAIEMENT:
            'En attente de paiement',

        CONFIRMEE:
            'Confirmée',

        EXPIREE:
            'Expirée',

        ANNULEE:
            'Annulée'

    }[v]||v||'Aucune';
}


/* =========================================================
   DATES
========================================================= */

function formatDate(v){

    if(!v)
        return '-';


    const p=
        String(v)
        .substring(0,10)
        .split('-');


    return p.length===3
        ?`${p[2]}/${p[1]}/${p[0]}`
        :v;
}


function formatDateTime(v){

    if(!v)
        return '-';


    const d=
        new Date(
            String(v)
            .replace(' ','T')
        );


    if(
        Number.isNaN(
            d.getTime()
        )
    )
        return v;


    return d.toLocaleString(
        'fr-FR'
    );
}


/* =========================================================
   ÉVÉNEMENTS
========================================================= */

$('search')
    .addEventListener(
        'input',
        afficher
    );


charger();


});

</script>


<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
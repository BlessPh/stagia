<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole([
    'ADMIN_ETABLISSEMENT',
    'RESPONSABLE_PEDAGOGIQUE'
]);

$etablissementId=currentEtablissementId($pdo);

if(!$etablissementId)
    exit('Aucun établissement associé.');

$pageTitle='Suivi des stages';
$activePage='stages-suivi';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.stage-progress{
    min-width:130px;
}

.stage-progress .progress{
    height:7px;
    margin-top:6px;
}

.monitoring-indicator{
    display:flex;
    align-items:center;
    gap:5px;
    font-size:12px;
    white-space:nowrap;
}

.monitoring-stack{
    display:flex;
    flex-direction:column;
    gap:5px;
}

.monitoring-alert{
    display:inline-flex;
    align-items:center;
    gap:5px;
    font-size:12px;
    font-weight:600;
}

.stage-period{
    white-space:nowrap;
}

.monitoring-student small{
    display:block;
    margin-top:3px;
}

@media(max-width:900px){
    .monitoring-toolbar{
        flex-direction:column;
        align-items:stretch!important;
    }
}
</style>


<main class="dashboard-content">

<!-- =========================================================
     ENTÊTE
========================================================= -->
<div class="stagia-page-head">

    <div>

        <h1>Suivi des stages</h1>

        <p>
            Suivez la progression, les présences,
            les activités, les rotations et les évaluations
            de vos étudiants en stage.
        </p>

    </div>


    <button type="button"
            id="refreshBtn"
            class="btn btn-light border">

        <i class="bi bi-arrow-clockwise me-1"></i>
        Actualiser

    </button>

</div>


<!-- =========================================================
     KPI
========================================================= -->
<div class="stagia-kpi-grid">

    <!-- TOTAL -->
    <div class="stagia-kpi-card">

        <div>
            <span>STAGES SUIVIS</span>

            <strong id="statTotal">
                0
            </strong>

            <small>
                Tous les stages affectés
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-briefcase"></i>
        </div>

    </div>


    <!-- EN COURS -->
    <div class="stagia-kpi-card">

        <div>
            <span>EN COURS</span>

            <strong id="statEnCours">
                0
            </strong>

            <small>
                Stages actuellement suivis
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-play-circle"></i>
        </div>

    </div>


    <!-- A CLOTURER -->
    <div class="stagia-kpi-card">

        <div>
            <span>À CLÔTURER</span>

            <strong id="statACloturer">
                0
            </strong>

            <small>
                Période arrivée à terme
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-check2-square"></i>
        </div>

    </div>


    <!-- ALERTES -->
    <div class="stagia-kpi-card">

        <div>
            <span>ALERTES</span>

            <strong id="statAlertes">
                0
            </strong>

            <small>
                Situations nécessitant un suivi
            </small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-exclamation-triangle"></i>
        </div>

    </div>

</div>


<!-- =========================================================
     LISTE
========================================================= -->
<div class="stagia-list-card">

    <!-- TOOLBAR -->
    <div class="stagia-list-toolbar monitoring-toolbar">

        <div class="stagia-tabs">

            <button type="button"
                    class="stagia-tab active"
                    data-status="">

                Tous
                <span id="countTous">0</span>

            </button>


            <button type="button"
                    class="stagia-tab"
                    data-status="EN_COURS">

                En cours
                <span id="countEnCours">0</span>

            </button>


            <button type="button"
                    class="stagia-tab"
                    data-status="A_CLOTURER">

                À clôturer
                <span id="countACloturer">0</span>

            </button>


            <button type="button"
                    class="stagia-tab"
                    data-status="ALERTE">

                Alertes
                <span id="countAlertes">0</span>

            </button>

        </div>


        <div class="stagia-list-filters">

            <div class="input-group stagia-table-search">

                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search"></i>
                </span>

                <input type="search"
                       id="stageSearch"
                       class="form-control border-start-0"
                       placeholder="Étudiant, code STAGIA, hôpital...">

            </div>


            <select id="perPage"
                    class="form-select stagia-per-page">

                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>

            </select>

        </div>

    </div>


    <!-- =====================================================
         TABLEAU
    ====================================================== -->
    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">

            <thead>

                <tr>

                    <th>
                        ÉTUDIANT
                    </th>

                    <th>
                        STAGE
                    </th>

                    <th>
                        HÔPITAL / SERVICE
                    </th>

                    <th>
                        PÉRIODE
                    </th>

                    <th>
                        PRÉSENCE
                    </th>

                    <th>
                        SUIVI
                    </th>

                    <th>
                        ÉTAT
                    </th>

                    <th class="text-center">
                        ACTIONS
                    </th>

                </tr>

            </thead>


            <tbody id="stageBody">

                <tr>

                    <td colspan="8"
                        class="text-center py-5">

                        <div class="spinner-border spinner-border-sm me-2"></div>

                        Chargement des stages...

                    </td>

                </tr>

            </tbody>

        </table>

    </div>


    <!-- PAGINATION -->
    <div class="stagia-list-footer">

        <span id="stageInfo">
            Affichage 0 sur 0
        </span>

        <nav>

            <ul id="stagePagination"
                class="pagination pagination-sm mb-0">
            </ul>

        </nav>

    </div>

</div>

</main>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>';
const $=id=>document.getElementById(id);

let page=1;
let limit=10;
let search='';
let status='';
let timer;


/* =========================================================
   CHARGER LES STAGES
========================================================= */
async function charger(p=1){

    const body=$('stageBody');

    body.innerHTML=`
        <tr>
            <td colspan="8"
                class="text-center py-5">

                <div class="spinner-border spinner-border-sm me-2"></div>

                Chargement des stages...

            </td>
        </tr>
    `;


    try{

        const params=new URLSearchParams({
            page:p,
            per_page:limit,
            search:search,
            status:status
        });


        const r=await STAGIA.request(
            BASE_URL+
            '/actions/stages/stage-monitoring-list.php?'+
            params
        );


        const items=r.data.items||[];
        const pg=r.data.pagination||{};
        const stats=r.data.stats||{};

        page=Number(pg.page||1);


        /* KPI */
        $('statTotal').textContent=
            stats.total||0;

        $('statEnCours').textContent=
            stats.en_cours||0;

        $('statACloturer').textContent=
            stats.a_cloturer||0;

        $('statAlertes').textContent=
            stats.alertes||0;


        /* Onglets */
        $('countTous').textContent=
            stats.total||0;

        $('countEnCours').textContent=
            stats.en_cours||0;

        $('countACloturer').textContent=
            stats.a_cloturer||0;

        $('countAlertes').textContent=
            stats.alertes||0;


        $('stageInfo').textContent=
            pg.total
                ?`Affichage ${pg.from}–${pg.to} sur ${pg.total}`
                :'Aucun résultat';


        afficher(items);

        pagination(pg);


    }catch(e){

        body.innerHTML=`
            <tr>
                <td colspan="8"
                    class="text-center py-5 text-danger">

                    <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>

                    ${STAGIA.escape(e.message)}

                </td>
            </tr>
        `;

        STAGIA.toast(
            e.message,
            'danger'
        );
    }
}


/* =========================================================
   AFFICHAGE
========================================================= */
function afficher(items){

    const body=$('stageBody');


    if(!items.length){

        body.innerHTML=`
            <tr>
                <td colspan="8"
                    class="text-center py-5 text-muted">

                    <i class="bi bi-clipboard-check fs-2 d-block mb-2"></i>

                    Aucun stage à afficher.

                </td>
            </tr>
        `;

        return;
    }


    body.innerHTML=items.map(x=>`

        <tr>

            <!-- ETUDIANT -->
            <td>

                <div class="monitoring-student">

                    <strong class="table-main-text">

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

                    <small class="text-muted">

                        ${STAGIA.escape(
                            x.stagia_code||'-'
                        )}

                    </small>

                    ${
                        x.matricule

                        ?`
                        <small class="text-muted">
                            Matricule :
                            ${STAGIA.escape(x.matricule)}
                        </small>
                        `

                        :''
                    }

                </div>

            </td>


            <!-- STAGE -->
            <td>

                <strong>
                    ${STAGIA.escape(
                        x.campaign_title||'-'
                    )}
                </strong>

                <div class="small text-muted">

                    ${STAGIA.escape(
                        x.campaign_code||''
                    )}

                </div>

                ${
                    x.promotion_nom

                    ?`
                    <div class="small text-muted">
                        ${STAGIA.escape(x.promotion_nom)}
                    </div>
                    `

                    :''
                }

            </td>


            <!-- HOPITAL -->
            <td>

                <div>
                    <i class="bi bi-hospital me-1 text-muted"></i>

                    ${STAGIA.escape(
                        x.host_name||'-'
                    )}
                </div>

                <small class="text-muted">

                    <i class="bi bi-geo-alt me-1"></i>

                    ${STAGIA.escape(
                        x.unit_name||'Service non affecté'
                    )}

                </small>

            </td>


            <!-- PERIODE -->
            <td>

                <div class="stage-period small">

                    ${dateFr(x.date_debut)}

                    <br>

                    <span class="text-muted">
                        au
                    </span>

                    ${dateFr(x.date_fin)}

                </div>


                <div class="stage-progress mt-2">

                    <div class="d-flex justify-content-between small">

                        <span>
                            Progression
                        </span>

                        <strong>
                            ${Number(x.progression||0)} %
                        </strong>

                    </div>


                    <div class="progress">

                        <div class="progress-bar"
                             role="progressbar"
                             style="width:${progress(x.progression)}%">
                        </div>

                    </div>

                </div>

            </td>


            <!-- PRESENCE -->
            <td>

                <strong>
                    ${
                        x.taux_presence!==null &&
                        x.taux_presence!==undefined

                        ?Number(x.taux_presence)
                            .toLocaleString('fr-FR')+' %'

                        :'-'
                    }
                </strong>

                <div class="small text-muted">

                    ${Number(x.presences||0)}
                    présence(s)

                </div>

                ${
                    Number(x.absences||0)>0

                    ?`
                    <div class="small text-warning">
                        ${Number(x.absences)} absence(s)
                    </div>
                    `

                    :''
                }

            </td>


            <!-- SUIVI -->
            <td>

                <div class="monitoring-stack">

                    <div class="monitoring-indicator">

                        <i class="bi bi-arrow-repeat"></i>

                        Rotations :

                        <strong>
                            ${Number(x.rotations_terminees||0)}
                            /
                            ${Number(x.rotations_total||0)}
                        </strong>

                    </div>


                    <div class="monitoring-indicator">

                        <i class="bi bi-journal-check"></i>

                        Journaux :

                        <strong>
                            ${Number(x.journaux_valides||0)}
                            /
                            ${Number(x.journaux_total||0)}
                        </strong>

                    </div>


                    <div class="monitoring-indicator">

                        <i class="bi bi-clipboard-data"></i>

                        Évaluation :

                        <strong>
                            ${
                                x.evaluation_finale
                                    ?'Disponible'
                                    :'En attente'
                            }
                        </strong>

                    </div>

                </div>

            </td>


            <!-- ETAT -->
            <td>

                ${etatBadge(x.etat)}

                ${
                    Number(x.alertes||0)>0

                    ?`
                    <div class="monitoring-alert text-warning mt-2">

                        <i class="bi bi-exclamation-triangle-fill"></i>

                        ${Number(x.alertes)}
                        alerte(s)

                    </div>
                    `

                    :''
                }

            </td>


            <!-- ACTION -->
            <td class="text-center">

                <a href="${BASE_URL}/views/stages/suivi-show.php?id=${Number(x.assignment_id)}"
                   class="btn btn-sm btn-outline-primary"
                   title="Voir le suivi détaillé">

                    <i class="bi bi-eye"></i>

                </a>

            </td>

        </tr>

    `).join('');
}


/* =========================================================
   ETAT
========================================================= */
function etatBadge(value){

    const labels={

        PLANIFIE:
            '<span class="badge bg-secondary">PLANIFIÉ</span>',

        EN_COURS:
            '<span class="badge bg-success">EN COURS</span>',

        A_CLOTURER:
            '<span class="badge bg-primary">À CLÔTURER</span>',

        CLOTURE:
            '<span class="badge bg-dark">CLÔTURÉ</span>',

        ALERTE:
            '<span class="badge bg-warning text-dark">À SURVEILLER</span>',

        SUSPENDU:
            '<span class="badge bg-secondary">SUSPENDU</span>',

        ANNULE:
            '<span class="badge bg-secondary">ANNULÉ</span>'
    };

    return labels[value]
        ||`<span class="badge bg-secondary">
            ${STAGIA.escape(value||'-')}
          </span>`;
}


/* =========================================================
   PROGRESSION
========================================================= */
function progress(v){

    v=Number(v||0);

    if(v<0) return 0;
    if(v>100) return 100;

    return v;
}


/* =========================================================
   DATE
========================================================= */
function dateFr(v){

    if(!v)
        return '-';

    const p=String(v)
        .substring(0,10)
        .split('-');

    return p.length===3
        ?`${p[2]}/${p[1]}/${p[0]}`
        :v;
}


/* =========================================================
   PAGINATION
========================================================= */
function pagination(p){

    const el=$('stagePagination');

    const current=Number(
        p.page||1
    );

    const pages=Number(
        p.pages||1
    );


    if(pages<=1){

        el.innerHTML='';
        return;
    }


    let html=`

        <li class="page-item ${current<=1?'disabled':''}">

            <button class="page-link"
                    data-page="${current-1}">
                ‹
            </button>

        </li>
    `;


    for(
        let i=Math.max(1,current-2);
        i<=Math.min(pages,current+2);
        i++
    ){

        html+=`

            <li class="page-item ${i===current?'active':''}">

                <button class="page-link"
                        data-page="${i}">
                    ${i}
                </button>

            </li>
        `;
    }


    html+=`

        <li class="page-item ${current>=pages?'disabled':''}">

            <button class="page-link"
                    data-page="${current+1}">
                ›
            </button>

        </li>
    `;


    el.innerHTML=html;


    el.querySelectorAll(
        '[data-page]'
    ).forEach(btn=>{

        btn.onclick=()=>{

            if(btn.closest('.disabled'))
                return;

            charger(
                Number(btn.dataset.page)
            );
        };
    });
}


/* =========================================================
   RECHERCHE
========================================================= */
$('stageSearch').oninput=e=>{

    clearTimeout(timer);

    timer=setTimeout(()=>{

        search=e.target.value.trim();

        charger(1);

    },300);
};


/* =========================================================
   NOMBRE PAR PAGE
========================================================= */
$('perPage').onchange=e=>{

    limit=Number(
        e.target.value
    );

    charger(1);
};


/* =========================================================
   ONGLETS
========================================================= */
document.querySelectorAll(
    '.stagia-tab[data-status]'
).forEach(tab=>{

    tab.onclick=()=>{

        document.querySelectorAll(
            '.stagia-tab[data-status]'
        ).forEach(x=>
            x.classList.remove('active')
        );

        tab.classList.add('active');

        status=tab.dataset.status;

        charger(1);
    };
});


/* =========================================================
   ACTUALISER
========================================================= */
$('refreshBtn').onclick=async function(){

    STAGIA.loading(
        this,
        true
    );

    try{

        await charger(page);

    }finally{

        STAGIA.loading(
            this,
            false
        );
    }
};


/* =========================================================
   INITIALISATION
========================================================= */
charger(1);

});
</script>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
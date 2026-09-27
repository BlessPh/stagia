<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

$pageTitle='Mon parcours';
$activePage='student-academic';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.path-current{
    border:1px solid #dfe7ee;
    border-radius:13px;
    background:#fff;
    padding:20px;
    margin-bottom:18px
}
.path-grid{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:12px;
    margin-top:18px
}
.path-box{
    border:1px solid #e4e9ef;
    border-radius:10px;
    padding:13px
}
.path-box small{
    display:block;
    color:#64748b;
    margin-bottom:5px
}
.path-box strong{
    font-size:14px;
    color:#24384a
}
@media(max-width:900px){
    .path-grid{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:560px){
    .path-grid{grid-template-columns:1fr}
}
</style>


<main class="dashboard-content">


<div class="stagia-page-head">

    <div>

        <h1>Mon parcours</h1>

        <p>
            Consultez votre inscription actuelle
            et l'historique de votre parcours académique.
        </p>

    </div>

</div>


<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>PARCOURS</span>
            <strong id="statTotal">0</strong>
            <small>Inscriptions académiques</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-mortarboard"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>EN COURS</span>
            <strong id="statCurrent">0</strong>
            <small>Inscription actuelle</small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>TERMINÉS</span>
            <strong id="statFinished">0</strong>
            <small>Parcours clôturés</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-award"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>CODE STAGIA</span>
            <strong id="statCode" style="font-size:15px">-</strong>
            <small>Identifiant national</small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-person-vcard"></i>
        </div>
    </div>

</div>


<!-- PARCOURS ACTUEL -->
<div id="currentContainer">

    <div class="stagia-list-card p-5 text-center">
        <div class="spinner-border spinner-border-sm me-2"></div>
        Chargement...
    </div>

</div>


<!-- HISTORIQUE -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>
            <h5 class="mb-1">
                Historique académique
            </h5>

            <small class="text-muted">
                Ensemble de vos inscriptions enregistrées.
            </small>
        </div>

    </div>


    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">

            <thead>
            <tr>
                <th>ANNÉE ACADÉMIQUE</th>
                <th>ÉTABLISSEMENT</th>
                <th>FILIÈRE</th>
                <th>PROMOTION</th>
                <th>NIVEAU</th>
                <th>STATUT</th>
            </tr>
            </thead>

            <tbody id="pathBody">

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
            '/actions/etudiants/student-parcours-list.php'
        );

        const student=r.data.student||{};
        const current=r.data.current||null;
        const stats=r.data.stats||{};

        items=r.data.items||[];


        $('statTotal').textContent=
            Number(stats.total||0);

        $('statCurrent').textContent=
            Number(stats.en_cours||0);

        $('statFinished').textContent=
            Number(stats.termines||0);

        $('statCode').textContent=
            student.stagia_code||'-';


        afficherActuel(current);

        afficherHistorique();


    }catch(e){

        $('currentContainer').innerHTML=`

            <div class="alert alert-danger">
                ${STAGIA.escape(e.message)}
            </div>
        `;

        $('pathBody').innerHTML=`

            <tr>
                <td colspan="6"
                    class="text-center py-5 text-danger">

                    ${STAGIA.escape(e.message)}

                </td>
            </tr>
        `;
    }
}


/* =========================================================
   PARCOURS ACTUEL
========================================================= */

function afficherActuel(x){

    if(!x){

        $('currentContainer').innerHTML=`

            <div class="stagia-list-card p-5 text-center text-muted">

                <i class="bi bi-mortarboard fs-1 d-block mb-3"></i>

                <h5>
                    Aucun parcours académique
                </h5>

                <p class="mb-0">
                    Aucune inscription académique
                    n'est actuellement associée à votre compte.
                </p>

            </div>
        `;

        return;
    }


    $('currentContainer').innerHTML=`

        <div class="path-current">

            <div class="d-flex
                        justify-content-between
                        align-items-start
                        flex-wrap gap-3">

                <div>

                    <small class="text-muted">
                        Parcours actuel
                    </small>

                    <h4 class="mb-1">

                        ${STAGIA.escape(
                            x.promotion||'-'
                        )}

                    </h4>

                    <div class="text-muted">

                        ${STAGIA.escape(
                            x.filiere||'-'
                        )}

                    </div>

                </div>


                ${statusBadge(x.statut)}

            </div>


            <div class="path-grid">


                <div class="path-box">

                    <small>
                        Établissement
                    </small>

                    <strong>
                        ${STAGIA.escape(
                            x.etablissement||'-'
                        )}
                    </strong>

                </div>


                <div class="path-box">

                    <small>
                        Année académique
                    </small>

                    <strong>
                        ${STAGIA.escape(
                            x.annee_academique||'-'
                        )}
                    </strong>

                </div>


                <div class="path-box">

                    <small>
                        Filière
                    </small>

                    <strong>
                        ${STAGIA.escape(
                            x.filiere||'-'
                        )}
                    </strong>

                </div>


                <div class="path-box">

                    <small>
                        Promotion / niveau
                    </small>

                    <strong>

                        ${STAGIA.escape(
                            x.promotion||'-'
                        )}

                        ${
                            x.niveau
                            ?' • '+STAGIA.escape(x.niveau)
                            :''
                        }

                    </strong>

                </div>


                <div class="path-box">

                    <small>
                        Faculté
                    </small>

                    <strong>
                        ${STAGIA.escape(
                            x.faculte||'-'
                        )}
                    </strong>

                </div>


                <div class="path-box">

                    <small>
                        Département
                    </small>

                    <strong>
                        ${STAGIA.escape(
                            x.departement||'-'
                        )}
                    </strong>

                </div>


                <div class="path-box">

                    <small>
                        Matricule
                    </small>

                    <strong>
                        ${STAGIA.escape(
                            x.matricule||'-'
                        )}
                    </strong>

                </div>


                <div class="path-box">

                    <small>
                        Période académique
                    </small>

                    <strong>

                        ${
                            x.date_debut
                            ?date(x.date_debut)
                            :''
                        }

                        ${
                            x.date_debut && x.date_fin
                            ?' → '
                            :''
                        }

                        ${
                            x.date_fin
                            ?date(x.date_fin)
                            :'-'
                        }

                    </strong>

                </div>


            </div>

        </div>
    `;
}


/* =========================================================
   HISTORIQUE
========================================================= */

function afficherHistorique(){

    if(!items.length){

        $('pathBody').innerHTML=`

            <tr>

                <td colspan="6"
                    class="text-center py-5 text-muted">

                    <i class="bi bi-inbox fs-3 d-block mb-2"></i>

                    Aucun parcours enregistré.

                </td>

            </tr>
        `;

        return;
    }


    $('pathBody').innerHTML=

        items.map(x=>`

            <tr>

                <td>
                    <strong>
                        ${STAGIA.escape(
                            x.annee_academique||'-'
                        )}
                    </strong>
                </td>


                <td>

                    ${STAGIA.escape(
                        x.etablissement||'-'
                    )}

                    <div class="small text-muted">

                        ${STAGIA.escape(
                            x.matricule||''
                        )}

                    </div>

                </td>


                <td>
                    ${STAGIA.escape(
                        x.filiere||'-'
                    )}
                </td>


                <td>
                    ${STAGIA.escape(
                        x.promotion||'-'
                    )}
                </td>


                <td>
                    ${STAGIA.escape(
                        x.niveau||'-'
                    )}
                </td>


                <td>
                    ${statusBadge(x.statut)}
                </td>

            </tr>

        `).join('');
}


/* =========================================================
   HELPERS
========================================================= */

function statusBadge(s){

    return {

        EN_COURS:
            '<span class="badge bg-success">EN COURS</span>',

        TERMINE:
            '<span class="badge bg-primary">TERMINÉ</span>',

        TERMINEE:
            '<span class="badge bg-primary">TERMINÉ</span>',

        VALIDE:
            '<span class="badge bg-primary">VALIDÉ</span>',

        SUSPENDU:
            '<span class="badge bg-warning text-dark">SUSPENDU</span>',

        ANNULE:
            '<span class="badge bg-secondary">ANNULÉ</span>'

    }[s]||
      `<span class="badge bg-secondary">${
          STAGIA.escape(s||'-')
      }</span>`;
}


function date(v){

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


charger();

});
</script>


<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
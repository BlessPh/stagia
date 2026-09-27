<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

$pageTitle='Mes notes';
$activePage='student-notes';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- ENTÊTE -->
<div class="stagia-page-head">
    <div>
        <h1>Mes notes</h1>
        <p>
            Consultez vos résultats académiques
            enregistrés dans votre parcours actuel.
        </p>
    </div>
</div>


<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>NOTES</span>
            <strong id="statTotal">0</strong>
            <small>Résultats enregistrés</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-journal-check"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>MATIÈRES</span>
            <strong id="statMatieres">0</strong>
            <small>Matières évaluées</small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-book"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>ÉVALUATIONS</span>
            <strong id="statTypes">0</strong>
            <small>Types d'évaluation</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-clipboard-data"></i>
        </div>
    </div>


    <div class="stagia-kpi-card">
        <div>
            <span>DERNIÈRE NOTE</span>
            <strong id="statLast">-</strong>
            <small>Dernier résultat enregistré</small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-award"></i>
        </div>
    </div>

</div>


<!-- PARCOURS -->
<div class="stagia-list-card mb-4">

    <div class="p-4">

        <div class="d-flex
                    justify-content-between
                    align-items-center
                    flex-wrap gap-3">

            <div>

                <small class="text-muted">
                    Parcours académique actuel
                </small>

                <h5 class="mb-1"
                    id="currentPromotion">
                    Chargement...
                </h5>

                <div class="text-muted small"
                     id="currentDetails">
                    -
                </div>

            </div>


            <span class="badge bg-success"
                  id="currentStatus">
                -
            </span>

        </div>

    </div>

</div>


<!-- LISTE -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div>
            <h5 class="mb-1">
                Résultats académiques
            </h5>

            <small class="text-muted">
                Notes enregistrées pour votre inscription actuelle.
            </small>
        </div>


        <div style="max-width:320px;width:100%">

            <div class="input-group">

                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search"></i>
                </span>

                <input type="search"
                       id="noteSearch"
                       class="form-control border-start-0"
                       placeholder="Rechercher une matière...">

            </div>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">

            <thead>
            <tr>
                <th>MATIÈRE</th>
                <th>TYPE D'ÉVALUATION</th>
                <th>NOTE</th>
                <th>RÉSULTAT</th>
                <th>DATE</th>
            </tr>
            </thead>

            <tbody id="notesBody">

            <tr>
                <td colspan="5"
                    class="text-center py-5">

                    <div class="spinner-border
                                spinner-border-sm me-2"></div>

                    Chargement...

                </td>
            </tr>

            </tbody>

        </table>

    </div>


    <div class="stagia-list-footer">

        <span id="notesInfo">
            0 résultat
        </span>

    </div>

</div>

</main>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id);

let notes=[];


/* =========================================================
   CHARGER LE PARCOURS ACTUEL
========================================================= */
async function charger(){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-parcours-list.php'
        );

        const current=r.data.current||null;


        if(!current){

            $('currentPromotion').textContent=
                'Aucun parcours académique';

            $('currentDetails').textContent=
                'Aucune inscription active.';

            $('currentStatus').textContent='-';

            afficherVide(
                'Aucune inscription académique active.'
            );

            return;
        }


        $('currentPromotion').textContent=
            current.promotion||'-';


        $('currentDetails').textContent=
            [
                current.annee_academique,
                current.filiere,
                current.niveau
            ]
            .filter(Boolean)
            .join(' • ');


        $('currentStatus').textContent=
            labelStatut(current.statut);


        await chargerNotes(current.id);


    }catch(e){

        afficherErreur(e.message);
    }
}


/* =========================================================
   CHARGER NOTES
========================================================= */
async function chargerNotes(academicId){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-note-list.php'+
            '?academic_enrollment_id='+
            academicId
        );


        notes=
            r.data.items||
            r.data.notes||
            [];


        calculerStats();

        afficherNotes(notes);


    }catch(e){

        afficherErreur(e.message);
    }
}


/* =========================================================
   STATISTIQUES
========================================================= */
function calculerStats(){

    $('statTotal').textContent=
        notes.length;


    const matieres=
        new Set(
            notes
            .map(x=>
                x.matiere||
                x.matiere_nom
            )
            .filter(Boolean)
        );


    const types=
        new Set(
            notes
            .map(x=>
                x.type_evaluation||
                x.type
            )
            .filter(Boolean)
        );


    $('statMatieres').textContent=
        matieres.size;


    $('statTypes').textContent=
        types.size;


    const last=
        notes.length
        ?notes[0]
        :null;


    $('statLast').textContent=
        last
        ?`${last.note??'-'} / ${last.note_sur??20}`
        :'-';
}


/* =========================================================
   AFFICHAGE
========================================================= */
function afficherNotes(items){

    const body=$('notesBody');


    if(!items.length){

        afficherVide(
            'Aucune note enregistrée pour ce parcours.'
        );

        return;
    }


    body.innerHTML=

        items.map(x=>{

            const note=
                Number(x.note??0);

            const max=
                Number(x.note_sur??20);

            const percent=
                max>0
                ?(note/max)*100
                :0;


            return `

            <tr>

                <td>

                    <strong class="table-main-text">
                        ${STAGIA.escape(
                            x.matiere||
                            x.matiere_nom||
                            '-'
                        )}
                    </strong>

                </td>


                <td>

                    ${STAGIA.escape(
                        labelType(
                            x.type_evaluation||
                            x.type||
                            '-'
                        )
                    )}

                </td>


                <td>

                    <strong>
                        ${STAGIA.escape(
                            x.note??'-'
                        )}
                        /
                        ${STAGIA.escape(
                            x.note_sur??20
                        )}
                    </strong>

                </td>


                <td>

                    ${badgeResult(percent)}

                </td>


                <td>

                    ${formatDate(
                        x.date_evaluation||
                        x.date_note||
                        x.created_at
                    )}

                </td>

            </tr>

            `;

        }).join('');


    $('notesInfo').textContent=
        items.length+
        (items.length>1
            ?' résultats'
            :' résultat'
        );
}


/* =========================================================
   RECHERCHE
========================================================= */
$('noteSearch').addEventListener(
    'input',
    e=>{

        const q=
            e.target.value
            .trim()
            .toLowerCase();


        const filtered=
            !q
            ?notes
            :notes.filter(x=>{

                const value=[
                    x.matiere,
                    x.matiere_nom,
                    x.type_evaluation,
                    x.type
                ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();


                return value.includes(q);
            });


        afficherNotes(filtered);
    }
);


/* =========================================================
   HELPERS
========================================================= */
function badgeResult(percent){

    if(percent>=70)
        return `
            <span class="badge bg-success">
                Très bien
            </span>
        `;


    if(percent>=50)
        return `
            <span class="badge bg-primary">
                Réussi
            </span>
        `;


    return `
        <span class="badge bg-danger">
            Insuffisant
        </span>
    `;
}


function labelType(v){

    return {

        INTERROGATION:'Interrogation',

        TP:'Travaux pratiques',

        EXAMEN:'Examen',

        EXAMEN_FINAL:'Examen final',

        CONTROLE_CONTINU:'Contrôle continu',

        DEVOIR:'Devoir'

    }[v]||v;
}


function labelStatut(v){

    return {

        EN_COURS:'EN COURS',

        TERMINE:'TERMINÉ',

        TERMINEE:'TERMINÉ',

        VALIDE:'VALIDÉ'

    }[v]||v||'-';
}


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


function afficherVide(message){

    $('notesBody').innerHTML=`

        <tr>

            <td colspan="5"
                class="text-center py-5 text-muted">

                <i class="bi bi-journal-x
                          fs-2
                          d-block
                          mb-2"></i>

                ${STAGIA.escape(message)}

            </td>

        </tr>
    `;


    $('notesInfo').textContent=
        '0 résultat';


    $('statTotal').textContent=0;
    $('statMatieres').textContent=0;
    $('statTypes').textContent=0;
    $('statLast').textContent='-';
}


function afficherErreur(message){

    $('notesBody').innerHTML=`

        <tr>

            <td colspan="5"
                class="text-center
                       py-5
                       text-danger">

                <i class="bi bi-exclamation-circle me-2"></i>

                ${STAGIA.escape(message)}

            </td>

        </tr>
    `;
}


charger();

});
</script>

<?php
require_once __DIR__.'/../../includes/app-footer.php';
?>
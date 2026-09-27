<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

$pageTitle='Mes évaluations';
$activePage='student-evaluations';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.eval-card{border:1px solid #e1e7ee;border-radius:13px;margin:16px;background:#fff;overflow:hidden}
.eval-head{padding:17px 18px;border-bottom:1px solid #edf0f3;display:flex;justify-content:space-between;gap:15px}
.eval-body{padding:18px}.score-row{display:grid;grid-template-columns:1fr 90px;gap:15px;padding:10px 0;border-bottom:1px solid #edf0f3}
.score-row:last-child{border:0}.score-value{font-weight:700;text-align:right;color:#102a43}
.eval-text{background:#f8fafc;border-radius:9px;padding:12px;min-height:48px}
</style>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Mes évaluations</h1>
        <p>Consultez vos évaluations finalisées.</p>
    </div>
</div>

<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>ÉVALUATIONS</span>
            <strong id="statTotal">0</strong>
            <small>Évaluations finalisées</small>
        </div>
        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-clipboard-check"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>MOYENNE</span>
            <strong id="statAverage">0%</strong>
            <small>Note moyenne</small>
        </div>
        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-graph-up"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>ROTATIONS</span>
            <strong id="statRotations">0</strong>
            <small>Rotations évaluées</small>
        </div>
        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-arrow-repeat"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>FINALES</span>
            <strong id="statFinal">0</strong>
            <small>Évaluations de fin</small>
        </div>
        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-patch-check"></i>
        </div>
    </div>

</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div>
            <h5 class="mb-1">Résultats des évaluations</h5>
            <small class="text-muted">Notes, appréciations et compétences évaluées.</small>
        </div>

        <div style="max-width:320px;width:100%">
            <input type="search" id="search" class="form-control" placeholder="Rechercher...">
        </div>
    </div>

    <div id="evaluationContainer">
        <div class="text-center py-5">Chargement...</div>
    </div>
</div>

</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id);

let items=[];

async function charger(){
    try{
        const r=await STAGIA.request(
            BASE_URL+'/actions/etudiants/student-evaluation-list.php'
        );

        items=r.data.items||[];
        const s=r.data.stats||{};

        $('statTotal').textContent=s.total||0;
        $('statAverage').textContent=Number(s.moyenne||0).toLocaleString('fr-FR')+'%';
        $('statRotations').textContent=s.rotations||0;
        $('statFinal').textContent=s.finales||0;

        afficher();

    }catch(e){
        $('evaluationContainer').innerHTML=
            `<div class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</div>`;
    }
}

function afficher(){
    const q=$('search').value.trim().toLowerCase();

    render(items.filter(x=>{
        if(!q)return true;

        return [
            x.unit_name,x.host_name,x.type_evaluation,
            x.appreciation,x.evaluator_nom,x.evaluator_postnom,
            x.evaluator_prenom
        ].filter(Boolean).join(' ').toLowerCase().includes(q);
    }));
}

function render(list){
    if(!list.length){
        $('evaluationContainer').innerHTML=`
            <div class="text-center py-5 text-muted">
                <i class="bi bi-clipboard-check fs-2 d-block mb-2"></i>
                Aucune évaluation finalisée.
            </div>`;
        return;
    }

    $('evaluationContainer').innerHTML=list.map(x=>`
        <div class="eval-card">
            <div class="eval-head">
                <div>
                    <h5 class="mb-1">${STAGIA.escape(x.unit_name||'-')}</h5>

                    <div class="small text-muted">
                        Rotation ${Number(x.sequence_no)}
                        • ${STAGIA.escape(x.host_name||'-')}
                        • ${dateFr(x.date_debut)} au ${dateFr(x.date_fin)}
                    </div>

                    <div class="small text-muted mt-1">
                        ${typeLabel(x.type_evaluation)}
                        • Évaluateur :
                        ${STAGIA.escape(
                            [x.evaluator_nom,x.evaluator_postnom,x.evaluator_prenom]
                            .filter(Boolean).join(' ')
                        )}
                    </div>

                    ${x.finalized_at?`
                    <div class="small text-muted mt-1">
                        Finalisée le ${dateTimeFr(x.finalized_at)}
                    </div>`:''}
                </div>

                <div class="text-end">
                    <div class="small text-muted">NOTE</div>
                    <div class="fs-3 fw-bold">${Number(x.note_finale).toLocaleString('fr-FR')}%</div>
                    <span class="badge bg-success">FINALISÉE</span>
                </div>
            </div>

            <div class="eval-body">
                <h6 class="mb-2">Compétences évaluées</h6>

                <div class="mb-4">
                    ${(x.scores||[]).map(s=>`
                        <div class="score-row">
                            <div>
                                <strong>${STAGIA.escape(s.nom||'-')}</strong>
                                <div class="small text-muted">
                                    ${STAGIA.escape(s.categorie||'')}
                                    ${s.commentaire?' • '+STAGIA.escape(s.commentaire):''}
                                </div>
                            </div>

                            <div class="score-value">
                                ${Number(s.note).toLocaleString('fr-FR')}
                                / ${Number(s.note_max).toLocaleString('fr-FR')}
                            </div>
                        </div>
                    `).join('')}
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="small text-muted">Appréciation générale</label>
                        <div class="eval-text">${STAGIA.escape(x.appreciation||'-')}</div>
                    </div>

                    <div class="col-md-6">
                        <label class="small text-muted">Points forts</label>
                        <div class="eval-text">${STAGIA.escape(x.points_forts||'-')}</div>
                    </div>

                    <div class="col-md-6">
                        <label class="small text-muted">Axes d'amélioration</label>
                        <div class="eval-text">${STAGIA.escape(x.axes_amelioration||'-')}</div>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

function typeLabel(v){
    return {
        CONTINUE:'Évaluation continue',
        MI_ROTATION:'Évaluation mi-rotation',
        FIN_ROTATION:'Évaluation de fin de rotation'
    }[v]||v||'-';
}

function dateFr(v){
    if(!v)return '-';
    const p=String(v).substring(0,10).split('-');
    return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;
}

function dateTimeFr(v){
    if(!v)return '-';
    const d=new Date(String(v).replace(' ','T'));
    return isNaN(d)?v:d.toLocaleString('fr-FR');
}

$('search').addEventListener('input',afficher);
charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

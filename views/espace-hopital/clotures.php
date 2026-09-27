<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL']);
if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Clôture des stages';
$activePage='hospital-completions';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.completion-card{border:1px solid #e1e7ee;border-radius:13px;margin:14px;background:#fff;overflow:hidden}
.completion-head{padding:17px 18px;border-bottom:1px solid #edf0f3;display:flex;justify-content:space-between;gap:15px}
.completion-body{padding:18px}
.completion-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.completion-box{border:1px solid #e4e9ef;border-radius:10px;padding:13px}
.completion-box small{display:block;color:#64748b;margin-bottom:5px}
.completion-box strong{font-size:17px}
.blocker{background:#fff8e8;border:1px solid #f6d48a;border-radius:9px;padding:9px 11px;margin-top:7px;font-size:13px}
@media(max-width:900px){.completion-grid{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.completion-grid{grid-template-columns:1fr}}
</style>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Clôture des stages</h1>
        <p>Contrôlez les conditions requises avant la validation définitive d'un stage.</p>
    </div>
</div>

<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>STAGES</span>
            <strong id="statTotal">0</strong>
            <small>Stages suivis</small>
        </div>
        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-briefcase"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>EN PRÉPARATION</span>
            <strong id="statPreparing">0</strong>
            <small>Conditions incomplètes</small>
        </div>
        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>PRÊTS</span>
            <strong id="statReady">0</strong>
            <small>Prêts à être validés</small>
        </div>
        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-check2-circle"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>VALIDÉS</span>
            <strong id="statValidated">0</strong>
            <small>Stages clôturés</small>
        </div>
        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-patch-check"></i>
        </div>
    </div>

</div>

<!-- LISTE -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">
        <div>
            <h5 class="mb-1">Situation des stages</h5>
            <small class="text-muted">
                Les contrôles sont recalculés automatiquement.
            </small>
        </div>

        <div style="max-width:320px;width:100%">
            <input type="search"
                   id="search"
                   class="form-control"
                   placeholder="Rechercher un stagiaire...">
        </div>
    </div>

    <div id="completionContainer">
        <div class="text-center py-5">Chargement...</div>
    </div>

</div>

</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      CSRF='<?= $_SESSION['csrf'] ?>',
      $=id=>document.getElementById(id);

let items=[];

/* =========================================================
   CHARGEMENT
========================================================= */
async function charger(){
    try{
        const r=await STAGIA.request(
            BASE_URL+'/actions/stages/host-completion-list.php'
        );

        items=r.data.items||[];

        let preparing=0,ready=0,validated=0;

        items.forEach(x=>{
            const s=x.completion?.statut;

            if(s==='EN_PREPARATION') preparing++;
            if(s==='PRET') ready++;
            if(s==='VALIDE') validated++;
        });

        $('statTotal').textContent=items.length;
        $('statPreparing').textContent=preparing;
        $('statReady').textContent=ready;
        $('statValidated').textContent=validated;

        afficher();

    }catch(e){
        $('completionContainer').innerHTML=`
            <div class="text-center py-5 text-danger">
                ${STAGIA.escape(e.message)}
            </div>`;
    }
}

/* =========================================================
   RECHERCHE
========================================================= */
function afficher(){
    const q=$('search').value.trim().toLowerCase();

    render(items.filter(x=>{
        if(!q) return true;

        return [
            x.nom,x.postnom,x.prenom,x.stagia_code,
            x.campaign_code,x.campaign_title,x.unit_name,
            x.completion?.statut
        ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(q);
    }));
}

/* =========================================================
   AFFICHAGE
========================================================= */
function render(list){

    if(!list.length){
        $('completionContainer').innerHTML=`
            <div class="text-center py-5 text-muted">
                <i class="bi bi-briefcase fs-2 d-block mb-2"></i>
                Aucun stage à afficher.
            </div>`;
        return;
    }

    $('completionContainer').innerHTML=list.map(x=>{

        const c=x.completion||{};
        const blockers=c.blockers||[];

        return `
        <div class="completion-card">

            <div class="completion-head">

                <div>
                    <h5 class="mb-1">
                        ${STAGIA.escape(
                            [x.nom,x.postnom,x.prenom]
                            .filter(Boolean).join(' ')
                        )}
                    </h5>

                    <div class="small text-muted">
                        ${STAGIA.escape(x.stagia_code||'-')}
                        • ${STAGIA.escape(x.campaign_title||'-')}
                        • ${STAGIA.escape(x.campaign_code||'-')}
                    </div>

                    <div class="small mt-2">
                        <i class="bi bi-geo-alt me-1"></i>
                        Affectation :
                        <strong>${STAGIA.escape(x.unit_name||'-')}</strong>
                    </div>
                </div>

                 <div class="text-end">

    ${statusBadge(c.statut)}

    ${
        c.statut==='PRET'
        ?`
            <div class="mt-2">

                <button type="button"
                        class="btn btn-success btn-sm btn-validate-stage"
                        data-id="${Number(x.assignment_id)}">

                    <i class="bi bi-check-lg me-1"></i>
                    Valider le stage

                </button>

            </div>
        `
        :''
    }

            ${
                c.statut==='VALIDE' && x.certificate
                ?`
                    <div class="mt-2 d-flex gap-1 justify-content-end">

                        <a href="${BASE_URL}/actions/stages/certificate-pdf.php?token=${
                            encodeURIComponent(x.certificate.uuid)
                        }"
                        target="_blank"
                        class="btn btn-sm btn-outline-primary">

                            <i class="bi bi-file-earmark-pdf me-1"></i>
                            Attestation

                        </a>

                        <a href="${BASE_URL}/actions/stages/certificate-pdf.php?token=${
                            encodeURIComponent(x.certificate.uuid)
                        }&download=1"
                        class="btn btn-sm btn-outline-secondary"
                        title="Télécharger">

                            <i class="bi bi-download"></i>

                        </a>

                    </div>

                    <div class="small text-muted mt-2">
                        ${STAGIA.escape(x.certificate.reference||'')}
                    </div>
                `
                :''
            }

        </div>

            </div>

            <div class="completion-body">

                <div class="completion-grid">

                    <div class="completion-box">
                        <small>Rotations terminées</small>
                        <strong>
                            ${Number(c.rotations_terminees||0)}
                            /
                            ${Number(c.total_rotations||0)}
                        </strong>
                        ${check(c.total_rotations>0 &&
                               c.rotations_terminees===c.total_rotations)}
                    </div>

                    <div class="completion-box">
                        <small>Présences</small>
                        <strong>${Number(c.total_presences||0)}</strong>
                        <div class="small text-muted">
                            ${Number(c.total_retards||0)} retard(s)
                            • ${Number(c.total_absences||0)} absence(s)
                        </div>
                    </div>

                    <div class="completion-box">
                        <small>Journaux validés</small>
                        <strong>
                            ${Number(c.journaux_valides||0)}
                            /
                            ${Number(c.total_journaux||0)}
                        </strong>
                        ${check(
                            c.total_journaux>0 &&
                            c.journaux_valides===c.total_journaux
                        )}
                    </div>

                    <div class="completion-box">
                        <small>Évaluation finale</small>
                        <strong>
                            ${
                                c.note_finale!==null &&
                                c.note_finale!==undefined
                                ?Number(c.note_finale).toLocaleString('fr-FR',{
                                    minimumFractionDigits:2,
                                    maximumFractionDigits:2
                                })+' / 20'
                               :'Non disponible'
                            }
                        </strong>
                        ${check(!!c.final_evaluation_id)}
                    </div>

                </div>

                ${
                    c.taux_presence!==null &&
                    c.taux_presence!==undefined
                    ?`
                    <div class="small text-muted mt-3">
                        Taux de présence enregistré :
                        <strong>
                            ${Number(c.taux_presence).toLocaleString('fr-FR')} %
                        </strong>
                    </div>`
                    :''
                }

                ${
                    blockers.length
                    ?`
                    <div class="mt-3">
                        <strong class="small">
                            <i class="bi bi-exclamation-triangle me-1 text-warning"></i>
                            Conditions restantes
                        </strong>

                        ${blockers.map(b=>`
                            <div class="blocker">
                                <i class="bi bi-clock-history me-1"></i>
                                ${STAGIA.escape(b)}
                            </div>
                        `).join('')}
                    </div>`
                    :`
                    <div class="alert alert-success mt-3 mb-0">
                        <i class="bi bi-check-circle me-1"></i>
                        Toutes les conditions de clôture sont satisfaites.
                    </div>`
                }

            </div>

        </div>`;
    }).join('');
}

/* =========================================================
   HELPERS
========================================================= */
function statusBadge(s){
    return {
        EN_PREPARATION:
            '<span class="badge bg-warning text-dark">EN PRÉPARATION</span>',

        PRET:
            '<span class="badge bg-primary">PRÊT</span>',

        VALIDE:
            '<span class="badge bg-success">VALIDÉ</span>',

        REFUSE:
            '<span class="badge bg-danger">REFUSÉ</span>',

        ANNULE:
            '<span class="badge bg-secondary">ANNULÉ</span>'
    }[s]||'<span class="badge bg-secondary">-</span>';
}

function check(ok){
    return ok
        ?'<div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i>Conforme</div>'
        :'<div class="small text-muted mt-1"><i class="bi bi-hourglass me-1"></i>En attente</div>';
}

/* =========================================================
   VALIDATION DÉFINITIVE
========================================================= */
$('completionContainer').addEventListener('click',async e=>{

    const btn=e.target.closest('.btn-validate-stage');

    if(!btn) return;

    if(!confirm(
        "Valider définitivement ce stage ?\n\n"+
        "Cette opération clôturera le stage et créera son attestation."
    )) return;

    const f=new FormData();

    f.append('csrf',CSRF);
    f.append('assignment_id',btn.dataset.id);

    STAGIA.loading(btn,true);

    try{
        const r=await STAGIA.post(
            BASE_URL+
            '/actions/stages/host-completion-validate.php',
            f
        );

        const ref=r.data?.certificate?.reference||'';

        STAGIA.toast(
            ref
                ?r.message+' Référence : '+ref
                :r.message
        );

        await charger();

    }catch(e){
        STAGIA.toast(e.message,'danger');

    }finally{
        STAGIA.loading(btn,false);
    }
});

$('search').addEventListener('input',afficher);

charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
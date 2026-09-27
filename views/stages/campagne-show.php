<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
$campaignId=(int)($_GET['id']??0);

if(!$etablissementId) exit('Aucun établissement associé.');
if(!$campaignId) exit('Campagne invalide.');

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

/* Hôpitaux pouvant être sollicités */
$stmt=$pdo->prepare("
    SELECT id,nom,ville,province
    FROM etablissements
    WHERE type_etablissement='HOPITAL'
      AND statut IN('VALIDE','ACTIF')
      AND id<>?
    ORDER BY nom
");
$stmt->execute([$etablissementId]);
$hopitaux=$stmt->fetchAll();

$pageTitle='Gestion campagne';
$activePage='stages-campagnes';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- Entête -->
<div class="stagia-page-head">

    <div>
        <a href="<?= BASE_URL ?>/views/stages/campagnes.php"
           class="detail-back">
            <i class="bi bi-arrow-left"></i>
            Campagnes
        </a>

        <h1 id="campaignTitle">
            Campagne de stage
        </h1>

        <p class="mb-0">
            <span id="campaignCode">-</span>
            •
            <span id="campaignStatus">
                -
            </span>
        </p>
    </div>

    <!-- ACTIONS STATUT -->
    <div id="campaignStatusActions"
         class="d-flex gap-2 flex-wrap">
    </div>
</div>


<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>HÔPITAUX SOLLICITÉS</span>
            <strong id="statTotal">0</strong>
            <small>Partenaires contactés</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-hospital"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>EN ATTENTE</span>
            <strong id="statSollicitees">0</strong>
            <small>Réponses attendues</small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>ACCEPTÉS</span>
            <strong id="statAcceptees">0</strong>
            <small>Hôpitaux disponibles</small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>CAPACITÉ ACCORDÉE</span>
            <strong id="statCapacite">0</strong>
            <small>Places accordées</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-people"></i>
        </div>
    </div>

</div>


<!-- Carte -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div class="stagia-tabs">

            <button class="stagia-tab active"
                    data-tab="overview">
                Vue générale
            </button>

            <button class="stagia-tab"
                    data-tab="hospitals">
                Hôpitaux partenaires
            </button>

            <button class="stagia-tab"
                    data-tab="students">
                Étudiants
            </button>

            <button class="stagia-tab"
                    data-tab="settings">
                Paramètres
            </button>

        </div>

    </div>


    <!-- VUE GENERALE -->
    <div id="tab-overview"
         class="campaign-tab">

        <div class="p-4">

            <div class="row g-4">

                <div class="col-lg-8">

                    <h5 class="mb-3">
                        Informations de la campagne
                    </h5>

                    <div class="row g-3">

                        <div class="col-md-4">
                            <small class="text-muted">
                                Type de stage
                            </small>

                            <div id="campaignType"
                                 class="fw-semibold">
                                -
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted">
                                Année académique
                            </small>

                            <div id="campaignYear">
                                -
                            </div>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted">
                                Période
                            </small>

                            <div id="campaignPeriod">
                                -
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted">
                                Ouverture candidatures
                            </small>

                            <div id="campaignOpen">
                                -
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted">
                                Clôture candidatures
                            </small>

                            <div id="campaignClose">
                                -
                            </div>
                        </div>

                    </div>

                    <hr>

                    <small class="text-muted">
                        Description
                    </small>

                    <p id="campaignDescription"
                       class="mb-0 mt-1">
                        -
                    </p>

                </div>


                <div class="col-lg-4">

                    <h5 class="mb-3">
                        Promotions éligibles
                    </h5>

                    <div id="campaignPromotions">
                        Chargement...
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- HOPITAUX -->
    <div id="tab-hospitals"
         class="campaign-tab d-none">

        <div class="p-3 border-bottom d-flex
                    justify-content-between align-items-center">

            <div>
                <strong>
                    Hôpitaux partenaires
                </strong>

                <div class="small text-muted">
                    Sollicitations et capacités accordées.
                </div>
            </div>

            <button id="btnSolicitHospital"
                    class="btn btn-primary-stagia btn-sm">

                <i class="bi bi-send me-1"></i>
                Solliciter un hôpital

            </button>

        </div>

        <div id="d4Notice"
             class="alert alert-light border m-3 d-none">
        </div>

        <div class="table-responsive">

            <table class="table stagia-modern-table
                          align-middle mb-0">

                <thead>
                    <tr>
                        <th>HÔPITAL</th>
                        <th>LOCALISATION</th>
                        <th>STATUT</th>
                        <th>CAPACITÉ</th>
                        <th>CAMPAGNE D'ACCUEIL</th>
                        <th>RÉPONSE</th>
                        <th class="text-center">
                            ACTION
                        </th>
                    </tr>
                </thead>

                <tbody id="hospitalBody">

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


    <!-- ETUDIANTS -->
    <div id="tab-students"
         class="campaign-tab d-none p-5
                text-center text-muted">

        <i class="bi bi-people fs-1 d-block mb-2"></i>

        <h6>Étudiants éligibles et candidatures</h6>

        <p class="mb-0">
            Cette partie sera activée après
            la configuration des établissements d'accueil.
        </p>

    </div>


    <!-- PARAMETRES -->
    <div id="tab-settings"
         class="campaign-tab d-none p-5
                text-center text-muted">

        <i class="bi bi-sliders fs-1 d-block mb-2"></i>

        <h6>Politiques de la campagne</h6>

        <p class="mb-0">
            Rotations, présence, paiements et autres règles
            seront configurés dans cette section.
        </p>

    </div>

</div>

</main>


<!-- Modal sollicitation -->
<div class="modal fade"
     id="hospitalModal"
     tabindex="-1">

<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">

<form id="hospitalForm">

    <div class="modal-header">

        <div>
            <h5 class="modal-title">
                Solliciter un hôpital
            </h5>

            <small class="text-muted">
                L'hôpital devra accepter ou refuser
                depuis son espace STAGIA.
            </small>
        </div>

        <button type="button"
                class="btn-close"
                data-bs-dismiss="modal">
        </button>

    </div>

    <div class="modal-body">

        <input type="hidden"
               name="csrf"
               value="<?= $_SESSION['csrf'] ?>">

        <input type="hidden"
               name="campaign_id"
               value="<?= $campaignId ?>">

        <div class="mb-3">

            <label class="form-label">
                Hôpital *
            </label>

            <select name="host_etablissement_id"
                    id="hospitalSelect"
                    class="form-select"
                    required>

                <option value="">
                    Sélectionner...
                </option>

                <?php foreach($hopitaux as $h): ?>

                    <option value="<?= $h['id'] ?>">

                        <?= htmlspecialchars(
                            $h['nom'].
                            ($h['ville']
                                ?' — '.$h['ville']
                                :'')
                        ) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div>

            <label class="form-label">
                Conditions / observations
            </label>

            <textarea name="conditions"
                      class="form-control"
                      rows="3"
                      maxlength="1000"
                      placeholder="Informations utiles adressées à l'hôpital..."></textarea>

        </div>

    </div>

    <div class="modal-footer">

        <button type="button"
                class="btn btn-light border"
                data-bs-dismiss="modal">
            Annuler
        </button>

        <button id="hospitalSaveBtn"
                class="btn btn-primary-stagia">

            <i class="bi bi-send me-1"></i>
            Envoyer la sollicitation

        </button>

    </div>

</form>

</div>
</div>
</div>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id),
      campaignId=<?= $campaignId ?>,
      csrf='<?= $_SESSION['csrf'] ?>',
      modal=new bootstrap.Modal($('hospitalModal')),
      form=$('hospitalForm');

let campaign=null;
let participations=[];


/* Charger campagne */
async function charger(){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/stages/campaign-detail.php?id='+
            campaignId
        );

        campaign=r.data.campaign;
        participations=r.data.participations||[];

        const promotions=r.data.promotions||[];
        const s=r.data.stats||{};

        $('campaignTitle').textContent=
            campaign.titre;

        $('campaignCode').textContent=
            campaign.code||'-';

        $('campaignStatus').textContent=
            labelStatut(campaign.statut);
         afficherActionsStatut();

        $('campaignType').textContent=
            campaign.stage_type;

        $('campaignYear').textContent=
            campaign.annee_academique||'-';

        $('campaignPeriod').textContent=
            formatDate(campaign.date_debut)+
            ' au '+
            formatDate(campaign.date_fin);

        $('campaignOpen').textContent=
            formatDateTime(
                campaign.ouverture_candidatures
            );

        $('campaignClose').textContent=
            formatDateTime(
                campaign.cloture_candidatures
            );

        $('campaignDescription').textContent=
            campaign.description||'Aucune description.';

        $('campaignPromotions').innerHTML=
            promotions.length
            ?promotions.map(p=>`

                <div class="border rounded-3 p-2 mb-2">

                    <strong>
                        ${STAGIA.escape(p.promotion)}
                    </strong>

                    <div class="small text-muted">
                        ${STAGIA.escape(p.filiere||'-')}
                    </div>

                </div>

            `).join('')
            :'<span class="text-muted">Aucune promotion.</span>';

        $('statTotal').textContent=s.total||0;
        $('statSollicitees').textContent=s.sollicitees||0;
        $('statAcceptees').textContent=s.acceptees||0;
        $('statCapacite').textContent=s.capacite||0;

        gererTypeCampagne();
        afficherHopitaux();

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}


/* D4 / Standard */
function gererTypeCampagne(){

    const isD4=
        campaign.stage_type_code==='MEDICAL_D4';

    $('btnSolicitHospital').classList.toggle(
        'd-none',
        !isD4 ||
        !['BROUILLON','EN_PREPARATION']
            .includes(campaign.statut)
    );

    if(!isD4){

        $('d4Notice').classList.remove('d-none');

        $('d4Notice').innerHTML=`
            <i class="bi bi-info-circle me-1"></i>
            La sollicitation obligatoire des hôpitaux
            concerne actuellement le workflow
            <strong>D4</strong>.
        `;

    }else{

        $('d4Notice').classList.toggle(
            'd-none',
            Number(
                $('statAcceptees').textContent
            )>0
        );

        if(Number(
            $('statAcceptees').textContent
        )===0){

            $('d4Notice').innerHTML=`
                <i class="bi bi-exclamation-circle me-1"></i>
                Cette campagne D4 ne pourra pas être ouverte
                aux étudiants tant qu'aucun hôpital
                n'aura accepté la sollicitation.
            `;
        }
    }
}


/* Tableau hôpitaux */
function afficherHopitaux(){

    $('hospitalBody').innerHTML=
        participations.length

        ?participations.map(x=>`

        <tr>

            <td>

                <strong class="table-main-text">
                    ${STAGIA.escape(x.hopital)}
                </strong>

            </td>

            <td>
                ${STAGIA.escape(
                    [x.ville,x.province]
                    .filter(Boolean)
                    .join(', ')||'-'
                )}
            </td>

            <td>

                <span class="badge ${badgeParticipation(x.statut)}">

                    ${labelParticipation(x.statut)}

                </span>

            </td>

            <td>

                ${
                    x.statut==='ACCEPTEE'
                        ?Number(x.capacite_allouee||0)
                        :'-'
                }

            </td>

            <td>

                ${
                    x.host_campaign_code
                        ?`
                        <strong>
                            ${STAGIA.escape(x.host_campaign_code)}
                        </strong>
                        <br>
                        <small class="text-muted">
                            ${STAGIA.escape(x.host_campaign_titre||'')}
                        </small>
                        `
                        :'-'
                }

            </td>

            <td>

                ${
                    x.responded_at
                        ?formatDateTime(x.responded_at)
                        :'<span class="text-muted">En attente</span>'
                }

                ${
                    x.motif_refus
                        ?`
                        <div class="small text-danger">
                            ${STAGIA.escape(x.motif_refus)}
                        </div>
                        `
                        :''
                }

            </td>

            <td class="text-center">

                ${
                    x.statut==='SOLLICITEE'
                    ?`
                    <button type="button"
                            class="btn btn-sm btn-outline-danger btn-cancel"
                            data-id="${x.id}"
                            title="Annuler">

                        <i class="bi bi-x-lg"></i>

                    </button>
                    `
                    :'-'
                }

            </td>

        </tr>

        `).join('')

        :`

        <tr>

            <td colspan="7"
                class="text-center py-5 text-muted">

                <i class="bi bi-hospital fs-2 d-block mb-2"></i>

                Aucun hôpital sollicité.

            </td>

        </tr>
        `;

    document.querySelectorAll('.btn-cancel')
        .forEach(btn=>
            btn.onclick=()=>annuler(
                btn.dataset.id
            )
        );
}


/* Ouvrir modal */
$('btnSolicitHospital').onclick=()=>{

    form.reset();
    modal.show();
};


/* Envoyer sollicitation */
form.onsubmit=async e=>{

    e.preventDefault();

    STAGIA.loading(
        $('hospitalSaveBtn'),
        true
    );

    try{

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/stages/participation-store.php',
            form
        );

        modal.hide();

        STAGIA.toast(r.message);

        await charger();

    }catch(e){

        STAGIA.toast(e.message,'danger');

    }finally{

        STAGIA.loading(
            $('hospitalSaveBtn'),
            false
        );
    }
};


/* Annuler */
async function annuler(id){

    if(!STAGIA.confirm(
        'Annuler cette sollicitation ?'
    )) return;

    const data=new FormData();

    data.append('csrf',csrf);
    data.append('id',id);

    try{

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/stages/participation-cancel.php',
            data
        );

        STAGIA.toast(r.message);

        await charger();

    }catch(e){

        STAGIA.toast(e.message,'danger');
    }
}


/* Onglets */
document.querySelectorAll(
    '.stagia-tab[data-tab]'
).forEach(tab=>{

    tab.onclick=()=>{

        document.querySelectorAll(
            '.stagia-tab[data-tab]'
        ).forEach(x=>
            x.classList.remove('active')
        );

        document.querySelectorAll(
            '.campaign-tab'
        ).forEach(x=>
            x.classList.add('d-none')
        );

        tab.classList.add('active');

        $('tab-'+tab.dataset.tab)
            .classList.remove('d-none');
    };
});

/* =====================================================
   AFFICHER LES ACTIONS DE STATUT
===================================================== */
function afficherActionsStatut(){

    const box=$('campaignStatusActions');

    if(!campaign){
        box.innerHTML='';
        return;
    }

    if(campaign.statut==='BROUILLON'){

        box.innerHTML=`
            <button type="button"
                    class="btn btn-primary-stagia btn-campaign-status"
                    data-target="EN_PREPARATION">

                <i class="bi bi-gear me-1"></i>
                Passer en préparation

            </button>
        `;

    }else if(campaign.statut==='EN_PREPARATION'){

        box.innerHTML=`

            <button type="button"
                    class="btn btn-light border btn-campaign-status"
                    data-target="BROUILLON">

                <i class="bi bi-arrow-left me-1"></i>
                Revenir en brouillon

            </button>


            <button type="button"
                    class="btn btn-primary-stagia btn-campaign-status"
                    data-target="OUVERTE">

                <i class="bi bi-play-circle me-1"></i>
                Ouvrir la campagne

            </button>
        `;

    }else if(campaign.statut==='OUVERTE'){

        box.innerHTML=`
            <button type="button"
                    class="btn btn-outline-danger btn-campaign-status"
                    data-target="CLOTUREE">

                <i class="bi bi-stop-circle me-1"></i>
                Clôturer la campagne

            </button>
        `;

    }else if(campaign.statut==='CLOTUREE'){

        box.innerHTML=`
            <button type="button"
                    class="btn btn-primary-stagia btn-campaign-status"
                    data-target="TERMINEE">

                <i class="bi bi-check-circle me-1"></i>
                Terminer la campagne

            </button>
        `;

    }else{

        box.innerHTML='';
    }


    /*
     * Brancher les boutons APRÈS leur création.
     */
    box.querySelectorAll('.btn-campaign-status')
        .forEach(btn=>{

            btn.onclick=()=>changerStatutCampagne(
                btn.dataset.target,
                btn
            );

        });
}


/* =====================================================
   CHANGER LE STATUT
===================================================== */
async function changerStatutCampagne(target,btn){

    const messages={

        EN_PREPARATION:
            'Passer cette campagne en préparation ?',

        BROUILLON:
            'Revenir au statut brouillon ?',

        OUVERTE:
            'Ouvrir cette campagne aux étudiants ?',

        CLOTUREE:
            'Clôturer cette campagne ?',

        TERMINEE:
            'Marquer cette campagne comme terminée ?'
    };


    if(!STAGIA.confirm(
        messages[target]||
        'Modifier le statut de cette campagne ?'
    )){
        return;
    }


    const data=new FormData();

    data.append('csrf',csrf);
    data.append('id',campaignId);
    data.append('statut',target);


    STAGIA.loading(btn,true);


    try{

        const r=await STAGIA.post(

            BASE_URL+
            '/actions/stages/campaign-status.php',

            data
        );


        STAGIA.toast(
            r.message
        );


        /*
         * Recharger les données de la campagne.
         * AUCUNE REDIRECTION.
         */
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
}

/* Helpers */
function labelStatut(s){

    return {
        BROUILLON:'Brouillon',
        EN_PREPARATION:'En préparation',
        OUVERTE:'Ouverte',
        CLOTUREE:'Clôturée',
        TERMINEE:'Terminée',
        ANNULEE:'Annulée'
    }[s]||s;
}

function labelParticipation(s){

    return {
        SOLLICITEE:'En attente',
        ACCEPTEE:'Acceptée',
        REFUSEE:'Refusée',
        ANNULEE:'Annulée'
    }[s]||s;
}

function badgeParticipation(s){

    return {
        SOLLICITEE:'bg-warning text-dark',
        ACCEPTEE:'bg-success',
        REFUSEE:'bg-danger',
        ANNULEE:'bg-secondary'
    }[s]||'bg-secondary';
}

function formatDate(v){

    if(!v) return '-';

    const p=v.substring(0,10).split('-');

    return p.length===3
        ?`${p[2]}/${p[1]}/${p[0]}`
        :v;
}

function formatDateTime(v){

    if(!v) return '-';

    return formatDate(v)+
        (v.length>=16
            ?' '+v.substring(11,16)
            :'');
}


/* Initialisation */
charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']);

$etablissementId=currentEtablissementId($pdo);

if(!$etablissementId)
    exit('Aucun établissement associé.');

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

/* Vérifier hôpital */
$stmt=$pdo->prepare("
    SELECT nom,type_etablissement
    FROM etablissements
    WHERE id=?
");
$stmt->execute([$etablissementId]);
$etablissement=$stmt->fetch();

if(!$etablissement || $etablissement['type_etablissement']!=='HOPITAL')
    exit('Cette page est réservée aux établissements de santé.');

$types=$pdo->query("
    SELECT id,code,libelle
    FROM stage_types
    WHERE actif=1
    ORDER BY libelle
")->fetchAll();

$pageTitle='Demandes reçues';
$activePage='stages-demandes';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">

    <div>
        <h1>Demandes de stage reçues</h1>
        <p>Sollicitations adressées à votre établissement.</p>
    </div>

    <button id="btnNewHostCampaign"
            class="btn btn-primary-stagia px-4">

        <i class="bi bi-plus-lg me-1"></i>
        Nouvelle campagne d'accueil

    </button>

</div>

<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>TOTAL DEMANDES</span>
            <strong id="statTotal">0</strong>
            <small>Sollicitations reçues</small>
        </div>

        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-inbox"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>EN ATTENTE</span>
            <strong id="statAttente">0</strong>
            <small>À traiter</small>
        </div>

        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>ACCEPTÉES</span>
            <strong id="statAcceptees">0</strong>
            <small>Demandes approuvées</small>
        </div>

        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>REFUSÉES</span>
            <strong id="statRefusees">0</strong>
            <small>Demandes refusées</small>
        </div>

        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-x-circle"></i>
        </div>
    </div>

</div>


<!-- DEMANDES -->
<div class="stagia-list-card">

<div class="stagia-list-toolbar">

    <div>
        <strong>Sollicitations universitaires</strong>
        <div class="small text-muted">
            Répondez aux demandes adressées à votre hôpital.
        </div>
    </div>

</div>

<div class="table-responsive">

<table class="table stagia-modern-table align-middle mb-0">

<thead>
<tr>
    <th>UNIVERSITÉ</th>
    <th>CAMPAGNE</th>
    <th>TYPE</th>
    <th>PÉRIODE</th>
    <th>STATUT</th>
    <th>CAPACITÉ</th>
    <th class="text-center">ACTION</th>
</tr>
</thead>

<tbody id="requestsBody">
<tr>
    <td colspan="7" class="text-center py-5">
        Chargement...
    </td>
</tr>
</tbody>

</table>

</div>
</div>

</main>


<!-- ======================================================
     MODAL CAMPAGNE ACCUEIL
======================================================= -->
<div class="modal fade" id="hostCampaignModal" tabindex="-1">

<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">

<form id="hostCampaignForm">

<div class="modal-header">

    <div>
        <h5 class="modal-title">
            Nouvelle campagne d'accueil
        </h5>

        <small class="text-muted">
            Organisation de l'accueil des stagiaires.
        </small>
    </div>

    <button type="button"
            class="btn-close"
            data-bs-dismiss="modal"></button>

</div>

<div class="modal-body">

    <input type="hidden"
           name="csrf"
           value="<?= $_SESSION['csrf'] ?>">

    <div class="mb-3">

        <label class="form-label">
            Type de stage *
        </label>

        <select name="stage_type_id"
                class="form-select"
                required>

            <option value="">
                Sélectionner...
            </option>

            <?php foreach($types as $t): ?>

                <option value="<?= $t['id'] ?>">
                    <?= htmlspecialchars($t['libelle']) ?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <div class="mb-3">

        <label class="form-label">
            Titre *
        </label>

        <input name="titre"
               class="form-control"
               placeholder="Ex. Accueil D4 2026-2027"
               required>

    </div>

    <div class="row g-3">

        <div class="col-md-6">
            <label class="form-label">Début *</label>
            <input type="date"
                   name="date_debut"
                   class="form-control"
                   required>
        </div>

        <div class="col-md-6">
            <label class="form-label">Fin *</label>
            <input type="date"
                   name="date_fin"
                   class="form-control"
                   required>
        </div>

        <div class="col-md-6">
            <label class="form-label">
                Capacité totale *
            </label>

            <input type="number"
                   min="1"
                   name="capacite_totale"
                   class="form-control"
                   required>
        </div>

        <div class="col-md-6">
            <label class="form-label">
                Réserve hospitalière
            </label>

            <input type="number"
                   min="0"
                   name="reserve_hospitaliere"
                   class="form-control"
                   value="0">
        </div>

    </div>

</div>

<div class="modal-footer">

    <button type="button"
            class="btn btn-light border"
            data-bs-dismiss="modal">
        Annuler
    </button>

    <button id="hostCampaignSaveBtn"
            class="btn btn-primary-stagia">

        <i class="bi bi-check-lg me-1"></i>
        Créer

    </button>

</div>

</form>
</div>
</div>
</div>


<!-- ======================================================
     MODAL REPONSE
======================================================= -->
<div class="modal fade" id="responseModal" tabindex="-1">

<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">

<form id="responseForm">

<div class="modal-header">

    <div>
        <h5 class="modal-title">
            Répondre à la sollicitation
        </h5>

        <small id="responseUniversity"
               class="text-muted"></small>
    </div>

    <button type="button"
            class="btn-close"
            data-bs-dismiss="modal"></button>

</div>

<div class="modal-body">

    <input type="hidden"
           name="csrf"
           value="<?= $_SESSION['csrf'] ?>">

    <input type="hidden"
           name="id"
           id="responseId">

    <div class="mb-3">

        <label class="form-label">
            Décision *
        </label>

        <select name="decision"
                id="responseDecision"
                class="form-select"
                required>

            <option value="ACCEPTEE">
                Accepter
            </option>

            <option value="REFUSEE">
                Refuser
            </option>

        </select>

    </div>


    <!-- ACCEPTATION -->
    <div id="acceptSection">

        <div class="mb-3">

            <label class="form-label">
                Campagne d'accueil *
            </label>

            <select name="host_campaign_id"
                    id="responseCampaign"
                    class="form-select">
            </select>

        </div>

        <div class="mb-3">

            <label class="form-label">
                Capacité accordée *
            </label>

            <input type="number"
                   min="1"
                   name="capacite_allouee"
                   class="form-control">
        </div>

        <div class="mb-3">

            <label class="form-label">
                Conditions
            </label>

            <textarea name="conditions"
                      class="form-control"
                      rows="2"></textarea>

        </div>

        <div class="form-check mb-3">

            <input type="checkbox"
                   name="frais_requis"
                   value="1"
                   id="feesRequired"
                   class="form-check-input">

            <label for="feesRequired"
                   class="form-check-label">
                Frais requis
            </label>

        </div>

        <div id="feesSection"
             class="row g-3 d-none">

            <div class="col-md-8">

                <label class="form-label">
                    Montant
                </label>

                <input type="number"
                       step="0.01"
                       min="0"
                       name="montant_frais"
                       class="form-control">

            </div>

            <div class="col-md-4">

                <label class="form-label">
                    Devise
                </label>

                <select name="devise"
                        class="form-select">

                    <option value="USD">USD</option>
                    <option value="CDF">CDF</option>

                </select>

            </div>

        </div>

    </div>


    <!-- REFUS -->
    <div id="refuseSection"
         class="d-none">

        <label class="form-label">
            Motif du refus *
        </label>

        <textarea name="motif_refus"
                  class="form-control"
                  rows="3"></textarea>

    </div>

</div>

<div class="modal-footer">

    <button type="button"
            class="btn btn-light border"
            data-bs-dismiss="modal">
        Annuler
    </button>

    <button id="responseSaveBtn"
            class="btn btn-primary-stagia">
        Confirmer
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
      campaignModal=new bootstrap.Modal($('hostCampaignModal')),
      responseModal=new bootstrap.Modal($('responseModal')),
      campaignForm=$('hostCampaignForm'),
      responseForm=$('responseForm');

let requests=[],hostCampaigns=[];


/* Charger */
async function charger(){

    try{

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/stages/host-request-list.php'
        );

        requests=r.data.requests||[];
        hostCampaigns=r.data.host_campaigns||[];

        const s=r.data.stats||{};

        $('statTotal').textContent=s.total||0;
        $('statAttente').textContent=s.attente||0;
        $('statAcceptees').textContent=s.acceptees||0;
        $('statRefusees').textContent=s.refusees||0;

        afficher();

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}


/* Tableau */
function afficher(){

    $('requestsBody').innerHTML=requests.length

        ?requests.map(x=>`

        <tr>

            <td>
                <strong>
                    ${STAGIA.escape(x.universite)}
                </strong>

                <br>

                <small class="text-muted">
                    ${STAGIA.escape(x.ville||'')}
                </small>
            </td>

            <td>
                <strong>
                    ${STAGIA.escape(x.campaign_code)}
                </strong>

                <br>

                <small class="text-muted">
                    ${STAGIA.escape(x.campaign_title)}
                </small>
            </td>

            <td>
                ${STAGIA.escape(x.stage_type)}
            </td>

            <td>
                ${date(x.date_debut)}
                <br>
                <small class="text-muted">
                    au ${date(x.date_fin)}
                </small>
            </td>

            <td>
                <span class="badge ${badge(x.statut)}">
                    ${label(x.statut)}
                </span>
            </td>

            <td>
                ${
                    x.statut==='ACCEPTEE'
                        ?Number(x.capacite_allouee||0)
                        :'-'
                }
            </td>

            <td class="text-center">

                ${
                    x.statut==='SOLLICITEE'
                        ?`
                        <button type="button"
                                class="btn btn-sm btn-outline-primary btn-response"
                                data-id="${x.id}">

                            <i class="bi bi-reply"></i>

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

                <i class="bi bi-inbox fs-2 d-block mb-2"></i>

                Aucune sollicitation reçue.

            </td>
        </tr>
        `;

    document.querySelectorAll('.btn-response')
        .forEach(b=>
            b.onclick=()=>ouvrirReponse(
                b.dataset.id
            )
        );
}


/* Nouvelle campagne accueil */
$('btnNewHostCampaign').onclick=()=>{

    campaignForm.reset();

    campaignModal.show();
};

campaignForm.onsubmit=async e=>{

    e.preventDefault();

    STAGIA.loading(
        $('hostCampaignSaveBtn'),
        true
    );

    try{

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/stages/host-campaign-store.php',
            campaignForm
        );

        campaignModal.hide();

        STAGIA.toast(r.message);

        await charger();

    }catch(e){

        STAGIA.toast(e.message,'danger');

    }finally{

        STAGIA.loading(
            $('hostCampaignSaveBtn'),
            false
        );
    }
};


/* Ouvrir réponse */
function ouvrirReponse(id){

    const x=requests.find(
        r=>Number(r.id)===Number(id)
    );

    if(!x) return;

    responseForm.reset();

    $('responseId').value=x.id;

    $('responseUniversity').textContent=
        x.universite+' — '+x.campaign_title;

    remplirCampagnes(x);

    changerDecision();

    responseModal.show();
}


/* Campagnes compatibles */
function remplirCampagnes(request){

    const compatibles=hostCampaigns.filter(
        c=>Number(c.stage_type_id)===
           Number(
               requests.find(
                   r=>Number(r.id)===
                      Number(request.id)
               )?.stage_type_id
           )
    );

    const source=compatibles.length
        ?compatibles
        :hostCampaigns;

    $('responseCampaign').innerHTML=
        '<option value="">Sélectionner...</option>'+
        source.map(c=>`

            <option value="${c.id}">

                ${STAGIA.escape(c.code)}
                —
                ${STAGIA.escape(c.titre)}
                — Disponible :
                ${Number(c.capacite_disponible)}

            </option>

        `).join('');
}


/* Décision */
$('responseDecision').onchange=changerDecision;

function changerDecision(){

    const accept=
        $('responseDecision').value==='ACCEPTEE';

    $('acceptSection')
        .classList.toggle('d-none',!accept);

    $('refuseSection')
        .classList.toggle('d-none',accept);
}


/* Frais */
$('feesRequired').onchange=e=>{

    $('feesSection')
        .classList.toggle(
            'd-none',
            !e.target.checked
        );
};


/* Répondre */
responseForm.onsubmit=async e=>{

    e.preventDefault();

    STAGIA.loading(
        $('responseSaveBtn'),
        true
    );

    try{

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/stages/host-participation-response.php',
            responseForm
        );

        responseModal.hide();

        STAGIA.toast(r.message);

        await charger();

    }catch(e){

        STAGIA.toast(e.message,'danger');

    }finally{

        STAGIA.loading(
            $('responseSaveBtn'),
            false
        );
    }
};


/* Helpers */
function label(s){

    return {
        SOLLICITEE:'EN ATTENTE',
        ACCEPTEE:'ACCEPTÉE',
        REFUSEE:'REFUSÉE',
        ANNULEE:'ANNULÉE'
    }[s]||s;
}

function badge(s){

    return {
        SOLLICITEE:'bg-warning text-dark',
        ACCEPTEE:'bg-success',
        REFUSEE:'bg-danger',
        ANNULEE:'bg-secondary'
    }[s]||'bg-secondary';
}

function date(v){

    if(!v) return '-';

    const p=v.substring(0,10).split('-');

    return p.length===3
        ?`${p[2]}/${p[1]}/${p[0]}`
        :v;
}


charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
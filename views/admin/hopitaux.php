<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Hôpitaux';
$activePage='admin-hopitaux';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- ENTÊTE -->
<div class="stagia-page-head">
    <div>
        <h1>Gestion des hôpitaux</h1>
        <p>Référentiel national des établissements de santé de STAGIA-RDC.</p>
    </div>

    <button id="btnNewHospital" class="btn btn-primary-stagia px-4">
        <i class="bi bi-plus-lg me-1"></i> Nouvel hôpital
    </button>
</div>

<!-- KPI -->
<div class="stagia-kpi-grid">

    <div class="stagia-kpi-card">
        <div>
            <span>TOTAL HÔPITAUX</span>
            <strong id="statTotal">0</strong>
            <small>Structures référencées</small>
        </div>
        <div class="stagia-kpi-icon kpi-blue">
            <i class="bi bi-hospital"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>EXTERNES</span>
            <strong id="statExternes">0</strong>
            <small>Sans espace actif</small>
        </div>
        <div class="stagia-kpi-icon kpi-orange">
            <i class="bi bi-building"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>EN ATTENTE</span>
            <strong id="statAttente">0</strong>
            <small>Activation en cours</small>
        </div>
        <div class="stagia-kpi-icon kpi-purple">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>

    <div class="stagia-kpi-card">
        <div>
            <span>ACTIFS</span>
            <strong id="statActifs">0</strong>
            <small>Espaces hospitaliers actifs</small>
        </div>
        <div class="stagia-kpi-icon kpi-green">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>

</div>

<!-- LISTE -->
<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div class="stagia-tabs">
            <button class="stagia-tab active" data-status="">Tous</button>
            <button class="stagia-tab" data-status="EXTERNE">Externes</button>
            <button class="stagia-tab" data-status="EN_ATTENTE">En attente</button>
            <button class="stagia-tab" data-status="VALIDE">Validés</button>
            <button class="stagia-tab" data-status="ACTIF">Actifs</button>
            <button class="stagia-tab" data-status="SUSPENDU">Suspendus</button>
        </div>

        <div class="stagia-list-filters">

            <div class="input-group stagia-table-search">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search"></i>
                </span>

                <input id="hospitalSearch"
                       class="form-control border-start-0"
                       placeholder="Nom, code, agrément, ville...">
            </div>

            <select id="perPage" class="form-select stagia-per-page">
                <option>10</option>
                <option>25</option>
                <option>50</option>
            </select>

        </div>
    </div>

    <div class="table-responsive">

        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
                <tr>
                    <th>CODE</th>
                    <th>HÔPITAL</th>
                    <th>AGRÉMENT</th>
                    <th>LOCALISATION</th>
                    <th>CONTACT</th>
                    <th>STATUT</th>
                    <th class="text-center">ACTIONS</th>
                </tr>
            </thead>

            <tbody id="hospitalBody">
                <tr>
                    <td colspan="7" class="text-center py-5">Chargement...</td>
                </tr>
            </tbody>
        </table>

    </div>

    <div class="stagia-list-footer">
        <span id="hospitalInfo">Affichage 0 sur 0</span>

        <nav>
            <ul id="hospitalPagination"
                class="pagination pagination-sm mb-0"></ul>
        </nav>
    </div>

</div>

</main>


<!-- =====================================================
     MODAL HÔPITAL
====================================================== -->
<div class="modal fade" id="hospitalModal" tabindex="-1">

<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content border-0 shadow">

<form id="hospitalForm">

    <div class="modal-header">
        <div>
            <h5 id="hospitalModalTitle" class="modal-title">
                Nouvel hôpital
            </h5>
            <small class="text-muted">
                Le code HOP-XXXXXX est généré automatiquement.
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

        <input type="hidden"
               name="id"
               id="hospitalId">

        <div class="row g-3">

            <div class="col-md-8">
                <label class="form-label">Nom officiel *</label>

                <input type="text"
                       name="nom"
                       id="hospitalNom"
                       class="form-control"
                       maxlength="200"
                       required>
            </div>

            <div class="col-md-4">
                <label class="form-label">N° d'agrément</label>

                <input type="text"
                       name="numero_agrement"
                       id="hospitalAgrement"
                       class="form-control"
                       maxlength="100">
            </div>

            <div class="col-md-6">
                <label class="form-label">E-mail</label>

                <input type="email"
                       name="email"
                       id="hospitalEmail"
                       class="form-control"
                       maxlength="150">
            </div>

            <div class="col-md-6">
                <label class="form-label">Téléphone</label>

                <input type="text"
                       name="telephone"
                       id="hospitalTelephone"
                       class="form-control"
                       maxlength="30">
            </div>

            <div class="col-md-4">
                <label class="form-label">Province</label>

                <input type="text"
                       name="province"
                       id="hospitalProvince"
                       class="form-control"
                       maxlength="100">
            </div>

            <div class="col-md-4">
                <label class="form-label">Ville</label>

                <input type="text"
                       name="ville"
                       id="hospitalVille"
                       class="form-control"
                       maxlength="100">
            </div>

            <div class="col-md-4" id="initialStatusBox">
                <label class="form-label">Statut initial</label>

                <select name="statut"
                        id="hospitalStatut"
                        class="form-select">

                    <option value="EXTERNE">Externe</option>
                    <option value="EN_ATTENTE">En attente d'activation</option>

                </select>
            </div>

            <div class="col-12">
                <label class="form-label">Adresse</label>

                <textarea name="adresse"
                          id="hospitalAdresse"
                          class="form-control"
                          rows="2"
                          maxlength="255"></textarea>
            </div>

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
            <i class="bi bi-check-lg me-1"></i> Enregistrer
        </button>
    </div>

</form>

</div>
</div>
</div>


<!-- =====================================================
     MODAL ACTIVATION ESPACE HÔPITAL
====================================================== -->
<div class="modal fade" id="activationModal" tabindex="-1">

<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">

<form id="activationForm">

    <div class="modal-header">

        <div>
            <h5 class="modal-title">
                Activer l'espace Hôpital
            </h5>

            <small id="activationHospital"
                   class="text-muted"></small>
        </div>

        <button type="button"
                class="btn-close"
                data-bs-dismiss="modal"></button>

    </div>

    <div class="modal-body">

        <div class="alert alert-light border small">
            <i class="bi bi-info-circle me-1"></i>
            Un compte administrateur principal sera créé pour cet hôpital.
        </div>

        <input type="hidden"
               name="csrf"
               value="<?= $_SESSION['csrf'] ?>">

        <input type="hidden"
               name="hopital_id"
               id="activationHospitalId">

        <div class="row g-3">

            <div class="col-md-6">
                <label class="form-label">Nom *</label>

                <input type="text"
                       name="nom"
                       class="form-control"
                       maxlength="100"
                       required>
            </div>

            <div class="col-md-6">
                <label class="form-label">Postnom</label>

                <input type="text"
                       name="postnom"
                       class="form-control"
                       maxlength="100">
            </div>

            <div class="col-md-6">
                <label class="form-label">Prénom</label>

                <input type="text"
                       name="prenom"
                       class="form-control"
                       maxlength="100">
            </div>

            <div class="col-md-6">
                <label class="form-label">Téléphone</label>

                <input type="text"
                       name="telephone"
                       class="form-control"
                       maxlength="30">
            </div>

            <div class="col-12">
                <label class="form-label">
                    E-mail de l'administrateur *
                </label>

                <input type="email"
                       name="email"
                       class="form-control"
                       maxlength="150"
                       required>
            </div>

        </div>

    </div>

    <div class="modal-footer">

        <button type="button"
                class="btn btn-light border"
                data-bs-dismiss="modal">
            Annuler
        </button>

        <button id="activationSaveBtn"
                class="btn btn-primary-stagia">

            <i class="bi bi-person-check me-1"></i>
            Créer l'administrateur

        </button>

    </div>

</form>

</div>
</div>
</div>


<!-- =====================================================
     MODAL LIEN D'ACTIVATION
====================================================== -->
<div class="modal fade" id="activationLinkModal" tabindex="-1">

<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">

    <div class="modal-header">
        <div>
            <h5 class="modal-title">Compte créé</h5>
            <small class="text-muted">
                Informations d'activation.
            </small>
        </div>

        <button type="button"
                class="btn-close"
                data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body">

        <div class="alert alert-success">
            <i class="bi bi-check-circle me-1"></i>
            L'administrateur hospitalier a été créé.
        </div>

        <div class="mb-3">
            <label class="form-label">Identifiant</label>

            <input id="createdIdentifier"
                   class="form-control"
                   readonly>
        </div>

        <div>
            <label class="form-label">Lien d'activation</label>

            <div class="input-group">

                <input id="createdActivationLink"
                       class="form-control"
                       readonly>

                <button type="button"
                        id="btnCopyActivation"
                        class="btn btn-outline-secondary">

                    <i class="bi bi-copy"></i>
                </button>

            </div>

            <div class="form-text">
                En développement, copiez ce lien et ouvrez-le dans un autre onglet.
            </div>
        </div>

    </div>

</div>
</div>
</div>


<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',
      $=id=>document.getElementById(id),

      hospitalModal=
        new bootstrap.Modal($('hospitalModal')),

      activationModal=
        new bootstrap.Modal($('activationModal')),

      activationLinkModal=
        new bootstrap.Modal($('activationLinkModal')),

      hospitalForm=$('hospitalForm'),
      activationForm=$('activationForm');

let page=1,
    limit=10,
    search='',
    statut='',
    timer,
    items=[];


/* =====================================================
   CHARGER
===================================================== */
async function charger(p=1){

    $('hospitalBody').innerHTML=`
        <tr>
            <td colspan="7"
                class="text-center py-5">
                <span class="spinner-border spinner-border-sm me-2"></span>
                Chargement...
            </td>
        </tr>
    `;

    try{

        const q=new URLSearchParams({
            page:p,
            per_page:limit,
            search,
            statut
        });

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/admin/hopital-list.php?'+q
        );

        items=r.data.items||[];

        const pg=r.data.pagination||{};
        const s=r.data.stats||{};

        page=Number(pg.page||1);

        $('statTotal').textContent=s.total||0;
        $('statExternes').textContent=s.externes||0;
        $('statAttente').textContent=s.attente||0;
        $('statActifs').textContent=s.actifs||0;

        $('hospitalInfo').textContent=
            pg.total
            ?`Affichage ${pg.from}–${pg.to} sur ${pg.total}`
            :'Aucun résultat';

        afficher();
        pagination(pg);

    }catch(e){

        $('hospitalBody').innerHTML=`
            <tr>
                <td colspan="7"
                    class="text-center py-5 text-danger">
                    Impossible de charger les hôpitaux.
                </td>
            </tr>
        `;

        STAGIA.toast(e.message,'danger');
    }
}


/* =====================================================
   AFFICHAGE
===================================================== */
function afficher(){

    $('hospitalBody').innerHTML=items.length

    ?items.map(x=>`

        <tr>

            <td>
                <strong>
                    ${STAGIA.escape(x.code||'-')}
                </strong>
            </td>

            <td>
                <strong class="table-main-text">
                    ${STAGIA.escape(x.nom)}
                </strong>

                ${
                    x.adresse
                    ?`<div class="small text-muted">
                        ${STAGIA.escape(x.adresse)}
                      </div>`
                    :''
                }
            </td>

            <td>
                ${STAGIA.escape(x.numero_agrement||'-')}
            </td>

            <td>
                ${STAGIA.escape(
                    [x.ville,x.province]
                    .filter(Boolean)
                    .join(', ')||'-'
                )}
            </td>

            <td>

                ${x.telephone
                    ?STAGIA.escape(x.telephone)
                    :''}

                ${x.email
                    ?`<div class="small text-muted">
                        ${STAGIA.escape(x.email)}
                      </div>`
                    :''}

                ${!x.telephone&&!x.email?'-':''}

            </td>

            <td>
                <span class="badge ${badge(x.statut)}">
                    ${label(x.statut)}
                </span>
            </td>

            <td class="text-center">

                <button type="button"
                        class="btn btn-sm btn-outline-primary btn-edit"
                        data-id="${x.id}"
                        title="Modifier">

                    <i class="bi bi-pencil"></i>
                </button>

                ${
                    ['EXTERNE','EN_ATTENTE','VALIDE','ACTIF']
                    .includes(x.statut)

                    ?`
                    <button type="button"
                            class="btn btn-sm btn-outline-success ms-1 btn-activate"
                            data-id="${x.id}"
                            title="Créer / activer l'administrateur">

                        <i class="bi bi-person-plus"></i>
                    </button>
                    `
                    :''
                }

            </td>

        </tr>

    `).join('')

    :`
        <tr>
            <td colspan="7"
                class="text-center py-5 text-muted">

                <i class="bi bi-hospital fs-2 d-block mb-2"></i>
                Aucun hôpital enregistré.

            </td>
        </tr>
    `;

    document.querySelectorAll('.btn-edit')
        .forEach(btn=>
            btn.onclick=()=>modifier(btn.dataset.id)
        );

    document.querySelectorAll('.btn-activate')
        .forEach(btn=>
            btn.onclick=()=>ouvrirActivation(btn.dataset.id)
        );
}


/* =====================================================
   NOUVEAU
===================================================== */
$('btnNewHospital').onclick=()=>{

    hospitalForm.reset();

    $('hospitalId').value='';
    $('hospitalStatut').value='EXTERNE';

    $('initialStatusBox').classList.remove('d-none');

    $('hospitalModalTitle').textContent=
        'Nouvel hôpital';

    hospitalModal.show();
};


/* =====================================================
   MODIFIER
===================================================== */
function modifier(id){

    const x=items.find(
        i=>Number(i.id)===Number(id)
    );

    if(!x) return;

    hospitalForm.reset();

    $('hospitalId').value=x.id;
    $('hospitalNom').value=x.nom||'';
    $('hospitalAgrement').value=x.numero_agrement||'';
    $('hospitalEmail').value=x.email||'';
    $('hospitalTelephone').value=x.telephone||'';
    $('hospitalProvince').value=x.province||'';
    $('hospitalVille').value=x.ville||'';
    $('hospitalAdresse').value=x.adresse||'';

    $('initialStatusBox').classList.add('d-none');

    $('hospitalModalTitle').textContent=
        'Modifier l’hôpital';

    hospitalModal.show();
}


/* =====================================================
   ENREGISTRER HÔPITAL
===================================================== */
hospitalForm.onsubmit=async e=>{

    e.preventDefault();

    STAGIA.loading(
        $('hospitalSaveBtn'),
        true
    );

    try{

        const url=$('hospitalId').value
            ?BASE_URL+
             '/actions/admin/hopital-update.php'
            :BASE_URL+
             '/actions/admin/hopital-store.php';

        const r=await STAGIA.post(
            url,
            hospitalForm
        );

        hospitalModal.hide();

        STAGIA.toast(r.message);

        await charger(page);

    }catch(e){

        STAGIA.toast(e.message,'danger');

    }finally{

        STAGIA.loading(
            $('hospitalSaveBtn'),
            false
        );
    }
};


/* =====================================================
   ACTIVATION
===================================================== */
function ouvrirActivation(id){

    const x=items.find(
        i=>Number(i.id)===Number(id)
    );

    if(!x) return;

    activationForm.reset();

    $('activationHospitalId').value=x.id;

    $('activationHospital').textContent=
        (x.code||'')+' — '+x.nom;

    activationModal.show();
}


activationForm.onsubmit=async e=>{

    e.preventDefault();

    STAGIA.loading(
        $('activationSaveBtn'),
        true
    );

    try{

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/admin/hopital-activate.php',
            activationForm
        );

        activationModal.hide();

        const link=
            window.location.origin+
            r.data.activation_url;

        $('createdIdentifier').value=
            r.data.identifiant||'';

        $('createdActivationLink').value=
            link;

        activationLinkModal.show();

        STAGIA.toast(r.message);

        await charger(page);

    }catch(e){

        STAGIA.toast(e.message,'danger');

    }finally{

        STAGIA.loading(
            $('activationSaveBtn'),
            false
        );
    }
};


/* Copier lien */
$('btnCopyActivation').onclick=async()=>{

    const input=$('createdActivationLink');

    try{

        await navigator.clipboard.writeText(
            input.value
        );

        STAGIA.toast(
            'Lien d’activation copié.'
        );

    }catch(e){

        input.select();
        document.execCommand('copy');

        STAGIA.toast(
            'Lien d’activation copié.'
        );
    }
};


/* =====================================================
   PAGINATION
===================================================== */
function pagination(p){

    const el=$('hospitalPagination'),
          current=Number(p.page||1),
          pages=Number(p.pages||1);

    if(pages<=1){

        el.innerHTML='';
        return;
    }

    let h=`
        <li class="page-item ${current<=1?'disabled':''}">
            <button class="page-link"
                    data-p="${current-1}">
                ‹
            </button>
        </li>
    `;

    for(
        let i=Math.max(1,current-2);
        i<=Math.min(pages,current+2);
        i++
    ){

        h+=`
            <li class="page-item ${i===current?'active':''}">
                <button class="page-link"
                        data-p="${i}">
                    ${i}
                </button>
            </li>
        `;
    }

    h+=`
        <li class="page-item ${current>=pages?'disabled':''}">
            <button class="page-link"
                    data-p="${current+1}">
                ›
            </button>
        </li>
    `;

    el.innerHTML=h;

    el.querySelectorAll('[data-p]')
        .forEach(btn=>
            btn.onclick=()=>
                charger(Number(btn.dataset.p))
        );
}


/* =====================================================
   FILTRES
===================================================== */
$('hospitalSearch').oninput=e=>{

    clearTimeout(timer);

    timer=setTimeout(()=>{

        search=e.target.value.trim();
        charger(1);

    },300);
};


$('perPage').onchange=e=>{

    limit=Number(e.target.value);
    charger(1);
};


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

        statut=tab.dataset.status;

        charger(1);
    };
});


/* =====================================================
   HELPERS
===================================================== */
function label(s){

    return {
        EXTERNE:'EXTERNE',
        EN_ATTENTE:'EN ATTENTE',
        VALIDE:'VALIDÉ',
        ACTIF:'ACTIF',
        SUSPENDU:'SUSPENDU',
        REJETE:'REJETÉ'
    }[s]||s;
}


function badge(s){

    return {
        EXTERNE:'bg-secondary',
        EN_ATTENTE:'bg-warning text-dark',
        VALIDE:'bg-primary',
        ACTIF:'bg-success',
        SUSPENDU:'bg-danger',
        REJETE:'bg-dark'
    }[s]||'bg-secondary';
}


/* INITIALISATION */
charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
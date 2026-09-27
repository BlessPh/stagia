<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) exit('Aucun établissement associé.');

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

/* Référentiels */
$stmt=$pdo->prepare("SELECT id,nom FROM facultes WHERE etablissement_id=? AND actif=1 ORDER BY nom");
$stmt->execute([$etablissementId]); $facultes=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT id,faculte_id,nom FROM departements WHERE etablissement_id=? AND actif=1 ORDER BY nom");
$stmt->execute([$etablissementId]); $departements=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT id,departement_id,nom FROM filieres WHERE etablissement_id=? AND actif=1 ORDER BY nom");
$stmt->execute([$etablissementId]); $filieres=$stmt->fetchAll();

$stmt=$pdo->prepare("
    SELECT p.id,p.filiere_id,p.nom,f.nom filiere
    FROM promotions p
    JOIN filieres f ON f.id=p.filiere_id
    WHERE p.etablissement_id=? AND p.actif=1
    ORDER BY f.nom,p.nom
");
$stmt->execute([$etablissementId]); $promotions=$stmt->fetchAll();

$pageTitle='Matières';
$activePage='matieres';

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Matières</h1>
        <p>Gestion des matières et unités d’enseignement.</p>
    </div>

    <button id="btnNew" class="btn btn-primary-stagia px-4">
        <i class="bi bi-plus-lg me-1"></i>
        Nouvelle matière
    </button>
</div>

<!-- KPI -->
<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card">
        <div><span>TOTAL MATIÈRES</span><strong id="statTotal">0</strong><small>Matières enregistrées</small></div>
        <div class="stagia-kpi-icon kpi-blue"><i class="bi bi-journal-bookmark"></i></div>
    </div>

    <div class="stagia-kpi-card">
        <div><span>ACTIVES</span><strong id="statActifs">0</strong><small>Matières disponibles</small></div>
        <div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div>
    </div>

    <div class="stagia-kpi-card">
        <div><span>INACTIVES</span><strong id="statInactifs">0</strong><small>Matières désactivées</small></div>
        <div class="stagia-kpi-icon kpi-orange"><i class="bi bi-pause-circle"></i></div>
    </div>

    <div class="stagia-kpi-card">
        <div><span>PROMOTIONS</span><strong id="statPromotions">0</strong><small>Avec matières</small></div>
        <div class="stagia-kpi-icon kpi-purple"><i class="bi bi-mortarboard"></i></div>
    </div>
</div>

<div class="stagia-list-card">

    <div class="stagia-list-toolbar">

        <div class="stagia-tabs">
            <button class="stagia-tab active" data-status="">Toutes</button>
            <button class="stagia-tab" data-status="1">Actives</button>
            <button class="stagia-tab" data-status="0">Inactives</button>
        </div>

        <div class="stagia-list-filters">
            <div class="input-group stagia-table-search">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                <input id="search" class="form-control border-start-0" placeholder="Code ou matière...">
            </div>

            <select id="perPage" class="form-select stagia-per-page">
                <option>10</option>
                <option>25</option>
                <option>50</option>
            </select>
        </div>

    </div>

    <!-- Filtres -->
    <div class="p-3 border-bottom">
        <div class="row g-2">

            <div class="col-md-3">
                <select id="faculteFilter" class="form-select">
                    <option value="">Toutes les facultés</option>
                    <?php foreach($facultes as $x): ?>
                        <option value="<?= $x['id'] ?>"><?= htmlspecialchars($x['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <select id="departementFilter" class="form-select">
                    <option value="">Tous les départements</option>
                    <?php foreach($departements as $x): ?>
                        <option value="<?= $x['id'] ?>" data-parent="<?= $x['faculte_id'] ?>">
                            <?= htmlspecialchars($x['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <select id="filiereFilter" class="form-select">
                    <option value="">Toutes les filières</option>
                    <?php foreach($filieres as $x): ?>
                        <option value="<?= $x['id'] ?>" data-parent="<?= $x['departement_id'] ?>">
                            <?= htmlspecialchars($x['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <select id="promotionFilter" class="form-select">
                    <option value="">Toutes les promotions</option>
                    <?php foreach($promotions as $x): ?>
                        <option value="<?= $x['id'] ?>" data-parent="<?= $x['filiere_id'] ?>">
                            <?= htmlspecialchars($x['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>
    </div>

    <!-- Tableau -->
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
                <tr>
                    <th>CODE</th>
                    <th>MATIÈRE</th>
                    <th>FILIÈRE / PROMOTION</th>
                    <th>CRÉDITS</th>
                    <th>COEFF.</th>
                    <th>NOTE /</th>
                    <th>STATUT</th>
                    <th class="text-center">ACTIONS</th>
                </tr>
            </thead>
            <tbody id="matiereBody"></tbody>
        </table>
    </div>

    <div class="stagia-list-footer">
        <span id="info">Affichage 0 sur 0</span>
        <nav><ul id="pagination" class="pagination pagination-sm mb-0"></ul></nav>
    </div>

</div>
</main>

<!-- Modal -->
<div class="modal fade" id="matiereModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content border-0 shadow">

<form id="matiereForm">

    <div class="modal-header">
        <div>
            <h5 class="modal-title" id="modalTitle">Nouvelle matière</h5>
            <small class="text-muted">Le code MAT-XXXX est généré automatiquement.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body">

        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
        <input type="hidden" name="id" id="matiereId">

        <div class="mb-3">
            <label class="form-label">Promotion *</label>
            <select name="promotion_id" id="matierePromotion" class="form-select" required>
                <option value="">Sélectionner...</option>

                <?php foreach($promotions as $p): ?>
                    <option value="<?= $p['id'] ?>">
                        <?= htmlspecialchars($p['filiere'].' — '.$p['nom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Nom de la matière *</label>
            <input name="nom" id="matiereNom" class="form-control" maxlength="150" required>
        </div>

        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label">Crédits</label>
                <input type="number" step="0.01" min="0"
                       name="credits" id="matiereCredits"
                       class="form-control">
            </div>

            <div class="col-md-4">
                <label class="form-label">Coefficient</label>
                <input type="number" step="0.01" min="0.01"
                       name="coefficient" id="matiereCoefficient"
                       class="form-control" value="1" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Note sur</label>
                <input type="number" step="0.01" min="1"
                       name="note_max" id="matiereNoteMax"
                       class="form-control" value="20" required>
            </div>

        </div>

    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>

        <button id="saveBtn" class="btn btn-primary-stagia">
            <i class="bi bi-check-lg me-1"></i>
            Enregistrer
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
      csrf='<?= $_SESSION['csrf'] ?>',
      modal=new bootstrap.Modal($('matiereModal')),
      form=$('matiereForm');

let page=1,limit=10,search='',statut='',
    faculte='',departement='',filiere='',promotion='',timer,
    items=[];

/* Liste */
async function charger(p=1){

    $('matiereBody').innerHTML=
        '<tr><td colspan="8" class="text-center py-5">Chargement...</td></tr>';

    try{
        const q=new URLSearchParams({
            page:p,per_page:limit,search,statut,
            faculte_id:faculte,
            departement_id:departement,
            filiere_id:filiere,
            promotion_id:promotion
        });

        const r=await STAGIA.request(
            BASE_URL+'/actions/academique/matiere-list.php?'+q
        );

        items=r.data.items||[];
        const pg=r.data.pagination||{},s=r.data.stats||{};

        page=Number(pg.page||1);

        $('statTotal').textContent=s.total||0;
        $('statActifs').textContent=s.actifs||0;
        $('statInactifs').textContent=s.inactifs||0;
        $('statPromotions').textContent=s.promotions||0;

        $('info').textContent=pg.total
            ?`Affichage ${pg.from}–${pg.to} sur ${pg.total}`
            :'Aucun résultat';

        $('matiereBody').innerHTML=items.length
            ?items.map(x=>`<tr>

                <td><strong>${STAGIA.escape(x.code||'-')}</strong></td>

                <td>
                    <strong class="table-main-text">
                        ${STAGIA.escape(x.nom)}
                    </strong>
                </td>

                <td>
                    ${STAGIA.escape(x.filiere||'-')}
                    <br>
                    <small class="text-muted">
                        ${STAGIA.escape(x.promotion||'-')}
                    </small>
                </td>

                <td>${x.credits??'-'}</td>
                <td>${x.coefficient??1}</td>
                <td>${x.note_max??20}</td>

                <td>
                    <span class="badge ${Number(x.actif)?'bg-success':'bg-secondary'}">
                        ${Number(x.actif)?'ACTIVE':'INACTIVE'}
                    </span>
                </td>

                <td class="text-center">

                    <button class="btn btn-sm btn-outline-primary btn-edit"
                            data-id="${x.id}" title="Modifier">
                        <i class="bi bi-pencil"></i>
                    </button>

                    <button class="btn btn-sm ${Number(x.actif)?'btn-outline-warning':'btn-outline-success'} btn-status"
                            data-id="${x.id}" data-actif="${Number(x.actif)?0:1}"
                            title="${Number(x.actif)?'Désactiver':'Activer'}">

                        <i class="bi ${Number(x.actif)?'bi-pause':'bi-play'}"></i>

                    </button>

                </td>

            </tr>`).join('')

            :`<tr>
                <td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-journal-bookmark fs-2 d-block mb-2"></i>
                    Aucune matière trouvée.
                </td>
            </tr>`;

        document.querySelectorAll('.btn-edit')
            .forEach(b=>b.onclick=()=>modifier(b.dataset.id));

        document.querySelectorAll('.btn-status')
            .forEach(b=>b.onclick=()=>changerStatut(
                b.dataset.id,
                b.dataset.actif
            ));

        pagination(pg);

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

/* Nouvelle matière */
$('btnNew').onclick=()=>{
    form.reset();
    $('matiereId').value='';
    $('matiereCoefficient').value=1;
    $('matiereNoteMax').value=20;
    $('modalTitle').textContent='Nouvelle matière';
    modal.show();
};

/* Modifier */
function modifier(id){

    const x=items.find(i=>Number(i.id)===Number(id));
    if(!x) return;

    $('matiereId').value=x.id;
    $('matierePromotion').value=x.promotion_id;
    $('matiereNom').value=x.nom;
    $('matiereCredits').value=x.credits??'';
    $('matiereCoefficient').value=x.coefficient??1;
    $('matiereNoteMax').value=x.note_max??20;

    $('modalTitle').textContent='Modifier la matière';

    modal.show();
}

/* Enregistrement */
form.onsubmit=async e=>{

    e.preventDefault();

    STAGIA.loading($('saveBtn'),true);

    try{
        const url=$('matiereId').value
            ?BASE_URL+'/actions/academique/matiere-update.php'
            :BASE_URL+'/actions/academique/matiere-store.php';

        const r=await STAGIA.post(url,form);

        modal.hide();
        STAGIA.toast(r.message);

        await charger(page);

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('saveBtn'),false);
    }
};

/* Activer / désactiver */
async function changerStatut(id,actif){

    if(!STAGIA.confirm(
        Number(actif)
            ?'Activer cette matière ?'
            :'Désactiver cette matière ?'
    )) return;

    const data=new FormData();

    data.append('csrf',csrf);
    data.append('id',id);
    data.append('actif',actif);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/academique/matiere-status.php',
            data
        );

        STAGIA.toast(r.message);
        await charger(page);

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

/* Pagination */
function pagination(p){

    const el=$('pagination'),
          current=Number(p.page||1),
          pages=Number(p.pages||1);

    if(pages<=1){
        el.innerHTML='';
        return;
    }

    let h=`<li class="page-item ${current<=1?'disabled':''}">
        <button class="page-link" data-p="${current-1}">‹</button>
    </li>`;

    for(let i=Math.max(1,current-2);i<=Math.min(pages,current+2);i++)
        h+=`<li class="page-item ${i===current?'active':''}">
            <button class="page-link" data-p="${i}">${i}</button>
        </li>`;

    h+=`<li class="page-item ${current>=pages?'disabled':''}">
        <button class="page-link" data-p="${current+1}">›</button>
    </li>`;

    el.innerHTML=h;

    el.querySelectorAll('[data-p]')
        .forEach(b=>b.onclick=()=>charger(Number(b.dataset.p)));
}

/* Filtres dépendants */
function limiter(select,parent){

    [...select.options].forEach(o=>{
        if(o.value)
            o.hidden=!!parent && o.dataset.parent!==parent;
    });

    select.value='';
}

$('faculteFilter').onchange=e=>{
    faculte=e.target.value;
    departement=filiere=promotion='';

    limiter($('departementFilter'),faculte);
    limiter($('filiereFilter'),'');
    limiter($('promotionFilter'),'');

    charger(1);
};

$('departementFilter').onchange=e=>{
    departement=e.target.value;
    filiere=promotion='';

    limiter($('filiereFilter'),departement);
    limiter($('promotionFilter'),'');

    charger(1);
};

$('filiereFilter').onchange=e=>{
    filiere=e.target.value;
    promotion='';

    limiter($('promotionFilter'),filiere);

    charger(1);
};

$('promotionFilter').onchange=e=>{
    promotion=e.target.value;
    charger(1);
};

$('search').oninput=e=>{
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

document.querySelectorAll('.stagia-tab[data-status]')
    .forEach(t=>t.onclick=()=>{

        document.querySelectorAll('.stagia-tab[data-status]')
            .forEach(x=>x.classList.remove('active'));

        t.classList.add('active');
        statut=t.dataset.status;

        charger(1);
    });

charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
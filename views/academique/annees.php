<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) exit('Aucun établissement associé.');
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Années académiques'; $activePage='annees';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<!-- Entête -->
<div class="stagia-page-head">
    <div>
        <a href="index.php" class="detail-back"><i class="bi bi-arrow-left"></i> Organisation académique</a>
        <h1>Années académiques</h1>
        <p>Gestion des périodes académiques de l’établissement.</p>
    </div>
    <button class="btn btn-primary-stagia px-4" onclick="nouvelleAnnee()">
        <i class="bi bi-plus-lg me-1"></i> Nouvelle année
    </button>
</div>

<!-- KPI -->
<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>TOTAL ANNÉES</span><strong id="statTotal">0</strong><small>Années enregistrées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-calendar3"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ACTIVES</span><strong id="statActifs">0</strong><small>Années actives</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span>INACTIVES</span><strong id="statInactifs">0</strong><small>Années clôturées</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-archive"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ANNÉE EN COURS</span><strong id="statCourante">-</strong><small>Période académique active</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-calendar-check"></i></div></div>
</div>

<!-- Liste -->
<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div class="stagia-tabs">
            <button class="stagia-tab active" data-status="">Toutes <span id="countTous">0</span></button>
            <button class="stagia-tab" data-status="1">Actives <span id="countActifs">0</span></button>
            <button class="stagia-tab" data-status="0">Inactives <span id="countInactifs">0</span></button>
        </div>

        <div class="stagia-list-filters">
            <div class="input-group stagia-table-search">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                <input id="anneeSearch" type="search" class="form-control border-start-0" placeholder="Rechercher une année...">
            </div>
            <select id="anneePerPage" class="form-select stagia-per-page">
                <option value="10">10</option><option value="25">25</option><option value="50">50</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr>
                <th>ANNÉE</th><th>DÉBUT</th><th>FIN</th><th>STATUT</th><th>CRÉÉ LE</th><th class="text-center">ACTIONS</th>
            </tr></thead>
            <tbody id="anneesBody">
                <tr><td colspan="6" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="stagia-list-footer">
        <span id="anneeInfo">Affichage 0 sur 0</span>
        <nav><ul id="anneePagination" class="pagination pagination-sm mb-0"></ul></nav>
    </div>
</div>
</main>

<!-- Modal -->
<div class="modal fade" id="anneeModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="anneeForm">
    <div class="modal-header">
        <div>
            <h5 id="anneeModalTitle" class="modal-title">Nouvelle année académique</h5>
            <small class="text-muted">Vous pouvez préparer l’année académique de cette année civile avant son démarrage.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
        <input type="hidden" name="id" id="anneeId">

        <div class="alert alert-light border small mb-3">
            <i class="bi bi-info-circle text-primary me-1"></i>
            Vous pouvez enregistrer l’année <?= date('Y') ?>-<?= date('Y')+1 ?> avant sa date de début.
            Une année déjà passée ou commençant l’année prochaine ne peut pas être créée.
        </div>

        <div class="mb-3">
            <label class="form-label">Année académique *</label>
            <input name="libelle" id="anneeLibelle" class="form-control" readonly required>
            <small class="text-muted">Le libellé est généré automatiquement à partir des dates.</small>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Date début *</label>
                <input type="date" name="date_debut" id="anneeDebut" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Date fin *</label>
                <input type="date" name="date_fin" id="anneeFin" class="form-control" required>
            </div>
        </div>

        <div id="anneePeriodInfo" class="small text-muted mt-3"></div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
        <button type="submit" class="btn btn-primary-stagia" id="anneeSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
    </div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),
      TODAY='<?= date('Y-m-d') ?>',
      CURRENT_YEAR=<?= (int)date('Y') ?>,
      form=$('anneeForm'),modal=new bootstrap.Modal($('anneeModal'));
let pageCourante=1,parPage=10,recherche='',statut='',timer=null;

/* Liste AJAX */
async function charger(page=1){
    const body=$('anneesBody');
    try{
        const q=new URLSearchParams({page,per_page:parPage,search:recherche,statut});
        const r=await STAGIA.request(BASE_URL+'/actions/academique/annee-list.php?'+q);
        const items=r.data.items||[],p=r.data.pagination||{},s=r.data.stats||{};
        pageCourante=Number(p.page||1);

        $('statTotal').textContent=s.total||0; $('statActifs').textContent=s.actifs||0;
        $('statInactifs').textContent=s.inactifs||0; $('statCourante').textContent=s.courante||'-';
        $('countTous').textContent=s.total||0; $('countActifs').textContent=s.actifs||0; $('countInactifs').textContent=s.inactifs||0;
        $('anneeInfo').textContent=p.total?`Affichage ${p.from}–${p.to} sur ${p.total}`:'Aucun résultat';

        if(!items.length){
            body.innerHTML='<tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-calendar-x fs-2 d-block mb-2"></i>Aucune année académique.</td></tr>';
            pagination(p); return;
        }

        body.innerHTML=items.map(a=>{
            const debut=String(a.date_debut||''),fin=String(a.date_fin||''),
                  startYear=Number(debut.slice(0,4)),
                  editable=startYear===CURRENT_YEAR,
                  phase=fin&&fin<TODAY?'HISTORIQUE':debut&&debut>TODAY?'PREPAREE':'COURANTE',
                  phaseBadge=phase==='COURANTE'
                    ?'<span class="badge bg-primary ms-1">En cours</span>'
                    :phase==='PREPAREE'
                        ?'<span class="badge bg-info text-dark ms-1">Préparée</span>'
                        :'<span class="badge bg-light text-dark border ms-1">Historique</span>',
                  actions=editable
                    ?`<button class="btn btn-sm btn-outline-primary btn-action btn-edit" data-id="${a.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
                       <button class="btn btn-sm btn-outline-primary btn-action btn-status" data-id="${a.id}" data-actif="${a.actif}" title="${Number(a.actif)===1?'Désactiver':'Activer'}"><i class="bi bi-${Number(a.actif)===1?'pause-circle':'play-circle'}"></i></button>`
                    :'<span class="text-muted small"><i class="bi bi-lock me-1"></i>Lecture seule</span>';

            return `<tr>
                <td><strong class="table-main-text">${STAGIA.escape(a.libelle)}</strong>${phaseBadge}</td>
                <td>${STAGIA.escape(debut||'-')}</td>
                <td>${STAGIA.escape(fin||'-')}</td>
                <td><span class="badge bg-${Number(a.actif)===1?'success':'secondary'}">${Number(a.actif)===1?'Active':'Inactive'}</span></td>
                <td>${STAGIA.escape(a.date_creation||'-')}</td>
                <td class="text-center">${actions}</td>
            </tr>`;
        }).join('');

        body.querySelectorAll('.btn-edit').forEach(b=>b.onclick=()=>modifier(items.find(x=>Number(x.id)===Number(b.dataset.id))));
        body.querySelectorAll('.btn-status').forEach(b=>b.onclick=()=>changerStatut(Number(b.dataset.id),Number(b.dataset.actif)));
        pagination(p);
    }catch(e){STAGIA.toast(e.message,'danger');}
}

/* Pagination */
function pagination(p){
    const el=$('anneePagination'),page=Number(p.page||1),pages=Number(p.pages||1);
    if(pages<=1){el.innerHTML='';return;}
    let h=`<li class="page-item ${page<=1?'disabled':''}"><button class="page-link" data-page="${page-1}">‹</button></li>`;
    for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++) h+=`<li class="page-item ${i===page?'active':''}"><button class="page-link" data-page="${i}">${i}</button></li>`;
    h+=`<li class="page-item ${page>=pages?'disabled':''}"><button class="page-link" data-page="${page+1}">›</button></li>`;
    el.innerHTML=h;
    el.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{const n=Number(b.dataset.page);if(n>=1&&n<=pages)charger(n);});
}

/* Année de cette année civile, même si elle n'a pas encore commencé */
function refreshPeriod(){
    const debut=$('anneeDebut').value,fin=$('anneeFin').value,info=$('anneePeriodInfo');
    $('anneeLibelle').value='';

    if(!debut||!fin){
        info.textContent='Renseignez la date de début et la date de fin.';
        info.className='small text-muted mt-3';
        return false;
    }

    const startYear=Number(debut.slice(0,4)),endYear=Number(fin.slice(0,4));
    $('anneeLibelle').value=`${startYear}-${endYear}`;

    let message='',ok=true;

    if(fin<=debut){
        message='La date de fin doit être postérieure à la date de début.';
        ok=false;
    }else if(startYear!==CURRENT_YEAR){
        message=`L’année académique doit commencer en ${CURRENT_YEAR}.`;
        ok=false;
    }else if(endYear!==CURRENT_YEAR+1){
        message=`La date de fin doit être en ${CURRENT_YEAR+1}.`;
        ok=false;
    }else if(debut>TODAY){
        message=`Année ${CURRENT_YEAR}-${CURRENT_YEAR+1} préparée à l’avance : elle démarrera le ${debut}.`;
    }else if(fin>=TODAY){
        message=`Année ${CURRENT_YEAR}-${CURRENT_YEAR+1} actuellement en cours.`;
    }else{
        message='Cette période est déjà terminée.';
        ok=false;
    }

    info.textContent=message;
    info.className='small mt-3 '+(ok?'text-success':'text-danger');
    return ok;
}

$('anneeDebut').addEventListener('change',refreshPeriod);
$('anneeFin').addEventListener('change',refreshPeriod);

/* Nouveau / modifier */
window.nouvelleAnnee=()=>{
    form.reset();
    $('anneeId').value='';
    $('anneeLibelle').value='';
    $('anneePeriodInfo').textContent='';
    $('anneeModalTitle').textContent='Nouvelle année académique';
    modal.show();
};

function modifier(a){
    if(!a)return;

    const debut=String(a.date_debut||''),
          startYear=Number(debut.slice(0,4));

    if(startYear!==CURRENT_YEAR){
        STAGIA.toast('Les anciennes années sont disponibles uniquement en consultation.','warning');
        return;
    }

    form.reset();
    $('anneeId').value=a.id;
    $('anneeDebut').value=a.date_debut||'';
    $('anneeFin').value=a.date_fin||'';
    refreshPeriod();
    $('anneeModalTitle').textContent='Modifier l’année académique';
    modal.show();
}

/* Sauvegarde */
form.onsubmit=async e=>{
    e.preventDefault();

    if(!refreshPeriod()){
        STAGIA.toast(`Seule l’année académique ${CURRENT_YEAR}-${CURRENT_YEAR+1} peut être enregistrée.`, 'warning');
        return;
    }

    STAGIA.loading($('anneeSaveBtn'),true);

    try{
        const url=$('anneeId').value
            ?BASE_URL+'/actions/academique/annee-update.php'
            :BASE_URL+'/actions/academique/annee-store.php';

        const r=await STAGIA.post(url,form);
        modal.hide();
        form.reset();
        STAGIA.toast(r.message);
        await charger(pageCourante);
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('anneeSaveBtn'),false);
    }
};

/* Statut */
async function changerStatut(id,actif){
    if(!STAGIA.confirm(actif===1?'Désactiver cette année académique ?':'Activer cette année académique ?'))return;
    const data=new FormData(); data.append('csrf','<?= $_SESSION['csrf'] ?>'); data.append('id',id);
    try{const r=await STAGIA.post(BASE_URL+'/actions/academique/annee-status.php',data);STAGIA.toast(r.message);await charger(pageCourante);}
    catch(e){STAGIA.toast(e.message,'danger');}
}

/* Filtres */
$('anneeSearch').oninput=e=>{clearTimeout(timer);timer=setTimeout(()=>{recherche=e.target.value.trim();charger(1);},300);};
$('anneePerPage').onchange=e=>{parPage=Number(e.target.value);charger(1);};
document.querySelectorAll('.stagia-tab').forEach(t=>t.onclick=()=>{
    document.querySelectorAll('.stagia-tab').forEach(x=>x.classList.remove('active'));
    t.classList.add('active'); statut=t.dataset.status; charger(1);
});

charger();
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
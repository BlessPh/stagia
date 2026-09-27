<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle="Services / Unités par type";
$activePage='config-host-services';

require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Services / Unités d'accueil</h1>
        <p>Référentiel structurel appliqué aux établissements d'accueil selon leur type.</p>
    </div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-info-circle me-1 text-primary"></i>
    Le Super Admin configure uniquement les structures communes. Les établissements peuvent ensuite
    proposer un élément manquant. <strong>La capacité d'accueil n'est jamais définie par le Super Admin :</strong>
    elle appartient exclusivement à chaque établissement d'accueil.
</div>

<div class="stagia-list-card mb-4">
<div class="p-4">
<div class="row g-3 align-items-end">
    <div class="col-lg-8">
        <label class="form-label">Type d'établissement d'accueil *</label>
        <select id="hostType" class="form-select">
            <option value="">Chargement...</option>
        </select>
    </div>
    <div class="col-lg-4">
        <button id="addHostBtn" class="btn btn-primary-stagia w-100" disabled>
            <i class="bi bi-plus-lg me-1"></i> Ajouter au référentiel national
        </button>
    </div>
</div>
</div>
</div>

<div class="stagia-list-card">
<div class="p-4 border-bottom">
    <h5 class="mb-1">Référentiel national</h5>
    <p class="text-muted mb-0">Éléments automatiquement disponibles dans les établissements du type sélectionné.</p>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>CODE</th>
    <th>TYPE</th>
    <th>NOM</th>
    <th>PARENT</th>
    <th class="text-center">ACTION</th>
</tr>
</thead>
<tbody id="hostBody">
<tr><td colspan="5" class="text-center py-5 text-muted">Sélectionnez un type d'établissement.</td></tr>
</tbody>
</table>
</div>
</div>

<div class="stagia-list-card mt-4">
<div class="p-4 border-bottom d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <div>
        <h5 class="mb-1"><i class="bi bi-building-add me-2"></i>Ajouts locaux des établissements</h5>
        <p class="text-muted mb-0">Structures proposées lorsqu'un service / une unité manque au référentiel STAGIA.</p>
    </div>
    <select id="localStatus" class="form-select" style="max-width:220px">
        <option value="">Tous les statuts</option>
        <option value="EN_ATTENTE">En attente</option>
        <option value="VALIDE_LOCAL">Validés localement</option>
        <option value="INTEGRE_REFERENTIEL">Intégrés au national</option>
        <option value="REFUSE">Refusés</option>
    </select>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>STRUCTURE</th>
    <th>ÉTABLISSEMENT</th>
    <th>PARENT</th>
    <th>CAPACITÉ</th>
    <th>STATUT</th>
    <th>DEMANDE</th>
    <th class="text-center">DÉCISION</th>
</tr>
</thead>
<tbody id="localBody">
<tr><td colspan="7" class="text-center py-5 text-muted">Sélectionnez un type d'établissement.</td></tr>
</tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="hostModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content border-0 shadow">
<form id="hostForm">
<div class="modal-header">
    <div>
        <h5 id="hostTitle" class="modal-title">Ajouter au référentiel national</h5>
        <small class="text-muted">Le code est généré automatiquement.</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="establishment_type_code" id="hostEstablishmentType">
<input type="hidden" name="id" id="hostId">

<div class="row g-3">
    <div class="col-md-5">
        <label class="form-label">Type de structure *</label>
        <select name="type" id="hostUnitType" class="form-select" required>
            <option value="DEPARTEMENT">Département</option>
            <option value="SERVICE">Service</option>
            <option value="UNITE">Unité</option>
            <option value="LABORATOIRE">Laboratoire</option>
            <option value="PROJET">Projet</option>
            <option value="CHANTIER">Chantier</option>
            <option value="ATELIER">Atelier</option>
            <option value="PARCELLE">Parcelle</option>
            <option value="EXPLOITATION">Exploitation</option>
            <option value="AUTRE">Autre</option>
        </select>
    </div>

    <div class="col-md-7">
        <label class="form-label">Nom *</label>
        <input name="nom" id="hostNom" class="form-control" required>
    </div>

    <div class="col-md-8">
        <label class="form-label">Parent</label>
        <select name="parent_id" id="hostParent" class="form-select">
            <option value="">Aucun</option>
        </select>
    </div>
<div class="col-md-4">
        <label class="form-label">Ordre</label>
        <input type="number" name="ordre" id="hostOrdre" class="form-control" value="0">
    </div>

    <div class="col-md-8">
        <label class="form-label">Description</label>
        <input name="description" id="hostDescription" class="form-control">
    </div>
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button id="hostSaveBtn" class="btn btn-primary-stagia">
        <i class="bi bi-check-lg me-1"></i> Enregistrer
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
      esc=STAGIA.escape,
      modal=new bootstrap.Modal($('hostModal'));

let TYPE='',items=[];

async function loadTypes(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/configuration/host-template-list.php');

        $('hostType').innerHTML=
            '<option value="">Sélectionner...</option>'+
            (r.data.types||[])
                .map(t=>`<option value="${t.code}">${esc(t.libelle)}</option>`)
                .join('');
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

$('hostType').onchange=async e=>{
    TYPE=e.target.value;
    $('addHostBtn').disabled=!TYPE;

    if(TYPE){
        await load();
        await loadLocal();
    }else{
        $('hostBody').innerHTML=
            '<tr><td colspan="5" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr>';
        $('localBody').innerHTML=
            '<tr><td colspan="7" class="text-center py-5 text-muted">Sélectionnez un type d’établissement.</td></tr>';
    }
};

async function load(){
    try{
        const r=await STAGIA.request(
            `${BASE_URL}/actions/configuration/host-template-list.php?type_code=${encodeURIComponent(TYPE)}`
        );

        items=r.data.items||[];

        $('hostBody').innerHTML=items.length?items.map(x=>`<tr>
            <td><strong>${esc(x.code)}</strong></td>
            <td><span class="badge bg-light text-dark border">${esc(x.type)}</span></td>
            <td><strong>${esc(x.nom)}</strong>${x.description?`<small class="d-block text-muted">${esc(x.description)}</small>`:''}</td>
            <td>${esc(x.parent_nom||'—')}</td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-primary edit-host" data-id="${x.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-danger delete-host" data-id="${x.id}" title="Retirer du référentiel"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`).join('')
        :'<tr><td colspan="5" class="text-center py-5 text-muted">Aucun élément configuré.</td></tr>';

        document.querySelectorAll('.edit-host')
            .forEach(b=>b.onclick=()=>open(items.find(x=>Number(x.id)===Number(b.dataset.id))));

        document.querySelectorAll('.delete-host')
            .forEach(b=>b.onclick=()=>remove(Number(b.dataset.id)));

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

async function loadLocal(){
    if(!TYPE)return;

    const q=new URLSearchParams({type_code:TYPE});

    if($('localStatus').value)
        q.set('status',$('localStatus').value);

    try{
        const r=await STAGIA.request(
            `${BASE_URL}/actions/configuration/host-local-list.php?${q}`
        );

        const list=r.data.items||[];

        $('localBody').innerHTML=list.length?list.map(x=>{
            const status={
                EN_ATTENTE:'<span class="badge bg-warning-subtle text-warning">En attente</span>',
                VALIDE_LOCAL:'<span class="badge bg-success-subtle text-success">Validé local</span>',
                INTEGRE_REFERENTIEL:'<span class="badge bg-info-subtle text-info">Intégré national</span>',
                REFUSE:'<span class="badge bg-danger-subtle text-danger">Refusé</span>'
            }[x.validation_statut]||esc(x.validation_statut||'—');

            const decisions=x.validation_statut==='EN_ATTENTE'
                ?`<div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-success review-local" data-id="${x.id}" data-action="APPROVE_LOCAL" title="Valider localement"><i class="bi bi-check2"></i></button>
                    <button class="btn btn-outline-primary review-local" data-id="${x.id}" data-action="INTEGRATE_NATIONAL" title="Intégrer au national"><i class="bi bi-globe2"></i></button>
                    <button class="btn btn-outline-danger review-local" data-id="${x.id}" data-action="REFUSE" title="Refuser"><i class="bi bi-x-lg"></i></button>
                  </div>`
                :'<span class="text-muted">Traité</span>';

            return `<tr>
                <td>
                    <strong>${esc(x.nom)}</strong>
                    <small class="d-block text-muted">${esc(x.code||'')} · ${esc(x.type)}</small>
                </td>
                <td><strong>${esc(x.etablissement_nom)}</strong></td>
                <td>${esc(x.parent_nom||'—')}</td>
                    <td>${status}${x.review_comment?`<small class="d-block text-muted">${esc(x.review_comment)}</small>`:''}</td>
                <td><small>${esc(x.motif_ajout||'—')}</small></td>
                <td class="text-center">${decisions}</td>
            </tr>`;
        }).join('')
        :'<tr><td colspan="7" class="text-center py-5 text-muted">Aucun ajout local.</td></tr>';

        document.querySelectorAll('.review-local')
            .forEach(b=>b.onclick=()=>reviewLocal(Number(b.dataset.id),b.dataset.action));

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

function fillParents(selected='',current=0){
    $('hostParent').innerHTML=
        '<option value="">Aucun</option>'+
        items.filter(x=>Number(x.id)!==Number(current))
             .map(x=>`<option value="${x.id}">${esc(x.nom)} · ${esc(x.type)}</option>`)
             .join('');

    if(selected)$('hostParent').value=String(selected);
}

$('addHostBtn').onclick=()=>open(null);

function open(x){
    $('hostForm').reset();
    $('hostEstablishmentType').value=TYPE;
    $('hostId').value=x?.id||'';
    $('hostTitle').textContent=x?'Modifier l’élément national':'Ajouter au référentiel national';

    $('hostUnitType').value=x?.type||'SERVICE';
    $('hostNom').value=x?.nom||'';
    $('hostOrdre').value=x?.ordre||0;
    $('hostDescription').value=x?.description||'';

    fillParents(x?.parent_id||'',x?.id||0);
    modal.show();
}

$('hostForm').onsubmit=async e=>{
    e.preventDefault();
    STAGIA.loading($('hostSaveBtn'),true);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/configuration/host-template-save.php',
            new FormData(e.currentTarget)
        );

        modal.hide();
        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('hostSaveBtn'),false);
    }
};

async function remove(id){
    if(!STAGIA.confirm('Retirer cet élément du référentiel actif ?'))return;

    const d=new FormData();
    d.append('csrf','<?= $_SESSION['csrf'] ?>');
    d.append('id',id);
    d.append('type_code',TYPE);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/configuration/host-template-delete.php',
            d
        );

        STAGIA.toast(r.message);
        await load();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

async function reviewLocal(id,action){
    let comment='';

    if(action==='REFUSE'){
        comment=prompt('Motif du refus :')||'';
        if(!comment.trim())return;
    }else if(action==='INTEGRATE_NATIONAL'){
        if(!STAGIA.confirm("Intégrer cette structure au référentiel national et la propager aux établissements d'accueil du même type ?"))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }else{
        if(!STAGIA.confirm("Valider cette structure uniquement pour l'établissement demandeur ?"))return;
        comment=prompt('Commentaire (facultatif) :')||'';
    }

    const d=new FormData();
    d.append('csrf','<?= $_SESSION['csrf'] ?>');
    d.append('id',id);
    d.append('action',action);
    d.append('comment',comment);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/configuration/host-local-review.php',
            d
        );

        STAGIA.toast(r.message);
        await load();
        await loadLocal();
    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

$('localStatus').onchange=loadLocal;

loadTypes();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

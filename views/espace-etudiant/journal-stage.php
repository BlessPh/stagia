<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Mon journal de stage';
$activePage='student-logbook';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.logbook-date{width:48px;height:48px;border-radius:12px;background:#fff2e8;color:#e96500;display:flex;align-items:center;justify-content:center;font-size:20px}
.activity-card{border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:10px;background:#fafbfc}
.activity-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.logbook-alert{background:#fff8e8;border:1px solid #f7d794;border-radius:9px;padding:10px 12px;font-size:12px}
#entryModal .modal-dialog{max-height:calc(100vh - 30px);margin:15px auto}
#entryModal .modal-content{max-height:calc(100vh - 30px);overflow:hidden}
#entryModal .modal-body{overflow-y:auto;max-height:calc(100vh - 170px)}
#entryModal .modal-header,#entryModal .modal-footer{flex-shrink:0;background:#fff;z-index:2}
</style>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Mon journal de stage</h1>
        <p>Enregistrez vos activités quotidiennes réalisées pendant votre rotation active.</p>
    </div>

    <button type="button"
            class="btn btn-primary-stagia"
            id="newEntryBtn"
            disabled>
        <i class="bi bi-plus-lg me-1"></i>
        Nouvelle entrée
    </button>
</div>

<div id="executionInfo" class="mb-4"></div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>JOURNAUX</span><strong id="statTotal">0</strong><small>Entrées enregistrées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-journal-medical"></i></div></div>
    <div class="stagia-kpi-card"><div><span>BROUILLONS</span><strong id="statDraft">0</strong><small>À compléter</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-pencil-square"></i></div></div>
    <div class="stagia-kpi-card"><div><span>À VALIDER</span><strong id="statSubmitted">0</strong><small>Soumis à l'encadreur</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-hourglass-split"></i></div></div>
    <div class="stagia-kpi-card"><div><span>VALIDÉS</span><strong id="statValidated">0</strong><small>Validés par l'encadreur</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-patch-check"></i></div></div>
</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div>
            <h5 class="mb-1">Journal quotidien</h5>
            <small class="text-muted">Une entrée correspond à une journée dans une rotation.</small>
        </div>
        <div style="max-width:320px;width:100%">
            <input type="search" id="entrySearch" class="form-control" placeholder="Rechercher...">
        </div>
    </div>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
            <tr>
                <th>DATE</th>
                <th>ROTATION / SERVICE</th>
                <th>RÉSUMÉ</th>
                <th>ACTIVITÉS</th>
                <th>STATUT</th>
                <th class="text-center">ACTION</th>
            </tr>
            </thead>
            <tbody id="entryBody">
            <tr><td colspan="6" class="text-center py-5">Chargement...</td></tr>
            </tbody>
        </table>
    </div>
</div>

</main>

<div class="modal fade" id="entryModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">
<form id="entryForm">

<div class="modal-header">
    <div>
        <h5 class="modal-title" id="entryModalTitle">Nouvelle entrée</h5>
        <small class="text-muted">Journal quotidien de la rotation.</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="id" id="entryId">
<input type="hidden" name="assignment_id" id="entryAssignmentId">
<input type="hidden" name="rotation_id" id="entryRotationId">
<input type="hidden" name="activities" id="activitiesJson">

<div class="logbook-alert mb-3">
    <i class="bi bi-shield-check me-1"></i>
    Ne saisissez aucun nom de patient, numéro de dossier ou information permettant d'identifier un patient.
</div>

<div class="row g-3">

<div class="col-md-8">
    <label class="form-label">Rotation active</label>
    <input type="text" id="rotationDisplay" class="form-control" readonly>
</div>

<div class="col-md-4">
    <label class="form-label">Date</label>
    <input type="date" id="entryDate" class="form-control" readonly>
</div>

<div class="col-12">
    <label class="form-label">Résumé des activités *</label>
    <textarea name="resume_activites" id="entrySummary" class="form-control" rows="3" required></textarea>
</div>

<div class="col-md-6">
    <label class="form-label">Apprentissages</label>
    <textarea name="apprentissages" id="entryLearning" class="form-control" rows="3"></textarea>
</div>

<div class="col-md-6">
    <label class="form-label">Difficultés rencontrées</label>
    <textarea name="difficultes" id="entryDifficulties" class="form-control" rows="3"></textarea>
</div>

<div class="col-12">
    <label class="form-label">Observation personnelle</label>
    <textarea name="observation_etudiant" id="entryObservation" class="form-control" rows="2"></textarea>
</div>

</div>

<hr class="my-4">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h6 class="mb-1">Activités réalisées</h6>
        <small class="text-muted">Ajoutez les actes et activités de la journée.</small>
    </div>

    <button type="button" class="btn btn-sm btn-outline-primary" id="addActivityBtn">
        <i class="bi bi-plus-lg me-1"></i>
        Ajouter
    </button>
</div>

<div id="activityContainer"></div>

</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="saveBtn">
        <i class="bi bi-save me-1"></i>
        Enregistrer le brouillon
    </button>
</div>

</form>
</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>';
const CSRF='<?= $_SESSION['csrf'] ?>';
const $=id=>document.getElementById(id);
const URL_PARAMS=new URLSearchParams(window.location.search);
const ASSIGNMENT_ID=URL_PARAMS.get('assignment_id')||'';

const modal=new bootstrap.Modal($('entryModal'));

let rotations=[];
let entries=[];
let activities=[];
let currentRotation=null;
let nextRotation=null;
let today='';

async function charger(){
    try{
        const qs=ASSIGNMENT_ID
            ?'?assignment_id='+encodeURIComponent(ASSIGNMENT_ID)
            :'';

        const r=await STAGIA.request(
            BASE_URL+'/actions/etudiants/student-logbook-list.php'+qs
        );

        rotations=r.data.rotations||[];
        entries=r.data.entries||[];
        currentRotation=r.data.current_rotation||null;
        nextRotation=r.data.next_rotation||null;
        today=r.data.today||'';

        const s=r.data.stats||{};

        $('statTotal').textContent=s.total||0;
        $('statDraft').textContent=s.brouillons||0;
        $('statSubmitted').textContent=s.soumis||0;
        $('statValidated').textContent=s.valides||0;

        $('newEntryBtn').disabled=!r.data.can_create;

        renderExecutionInfo();
        afficher();

    }catch(e){
        $('entryBody').innerHTML=
            `<tr><td colspan="6" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;
    }
}

function renderExecutionInfo(){

    if(currentRotation){
        $('executionInfo').innerHTML=`
            <div class="alert alert-success mb-0">
                <strong>
                    <i class="bi bi-play-circle-fill me-1"></i>
                    Rotation active :
                    ${STAGIA.escape(currentRotation.unit_name||'-')}
                </strong>
                · ${dateFr(currentRotation.date_debut)}
                → ${dateFr(currentRotation.date_fin)}
                <br>
                <small>
                    Toute nouvelle entrée sera automatiquement rattachée
                    à cette rotation et à la date du jour.
                </small>
            </div>`;
        return;
    }

    if(nextRotation){
        $('executionInfo').innerHTML=`
            <div class="alert alert-warning mb-0">
                <strong>
                    <i class="bi bi-lock-fill me-1"></i>
                    Journal verrouillé.
                </strong>
                Prochaine rotation :
                <strong>${STAGIA.escape(nextRotation.unit_name||'-')}</strong>
                le <strong>${dateFr(nextRotation.date_debut)}</strong>.
            </div>`;
        return;
    }

    $('executionInfo').innerHTML=`
        <div class="alert alert-light border mb-0">
            <i class="bi bi-lock-fill me-1"></i>
            Aucune rotation active aujourd’hui.
        </div>`;
}

function afficher(){

    const q=$('entrySearch').value.trim().toLowerCase();

    const list=entries.filter(x=>{
        if(!q)return true;

        return [
            x.date_journal,
            x.unit_name,
            x.unit_code,
            x.resume_activites,
            x.statut
        ].filter(Boolean).join(' ').toLowerCase().includes(q);
    });

    render(list);
}

function render(list){

    if(!list.length){
        $('entryBody').innerHTML=`
            <tr>
                <td colspan="6" class="text-center py-5 text-muted">
                    <i class="bi bi-journal-medical fs-2 d-block mb-2"></i>
                    Aucun journal enregistré.
                </td>
            </tr>`;
        return;
    }

    $('entryBody').innerHTML=list.map(x=>`
        <tr>
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="logbook-date"><i class="bi bi-calendar3"></i></div>
                    <strong>${dateFr(x.date_journal)}</strong>
                </div>
            </td>

            <td>
                <strong>${STAGIA.escape(x.unit_name||'-')}</strong>
                <div class="small text-muted">
                    Rotation ${Number(x.sequence_no||0)}
                    ${x.unit_code?' • '+STAGIA.escape(x.unit_code):''}
                </div>
            </td>

            <td style="max-width:340px">
                <div class="text-truncate" style="max-width:320px">
                    ${STAGIA.escape(x.resume_activites||'-')}
                </div>

                ${x.statut==='REJETE' && x.commentaire_encadreur
                    ?`<div class="small text-danger mt-1">
                        <i class="bi bi-chat-left-text me-1"></i>
                        ${STAGIA.escape(x.commentaire_encadreur)}
                      </div>`
                    :''}
            </td>

            <td>
                <span class="badge bg-light text-dark">
                    ${Number(x.activities_count||0)} activité(s)
                </span>
            </td>

            <td>${statusBadge(x.statut)}</td>
            <td class="text-center">${actions(x)}</td>
        </tr>
    `).join('');
}

function actions(x){

    if(x.can_edit){
        return `
            <div class="d-flex justify-content-center gap-1">
                <button type="button"
                        class="btn btn-sm btn-outline-secondary btn-edit"
                        data-id="${Number(x.id)}"
                        title="Modifier">
                    <i class="bi bi-pencil"></i>
                </button>

                <button type="button"
                        class="btn btn-sm btn-primary-stagia btn-submit-entry"
                        data-id="${Number(x.id)}">
                    <i class="bi bi-send me-1"></i>
                    Soumettre
                </button>
            </div>`;
    }

    return `
        <button type="button"
                class="btn btn-sm btn-outline-secondary btn-view"
                data-id="${Number(x.id)}">
            <i class="bi bi-eye me-1"></i>
            Voir
        </button>`;
}

function statusBadge(s){
    return {
        BROUILLON:'<span class="badge bg-secondary">BROUILLON</span>',
        SOUMIS:'<span class="badge bg-warning text-dark">À VALIDER</span>',
        VALIDE:'<span class="badge bg-success">VALIDÉ</span>',
        REJETE:'<span class="badge bg-danger">À CORRIGER</span>'
    }[s]||`<span class="badge bg-secondary">${STAGIA.escape(s||'-')}</span>`;
}

$('newEntryBtn').addEventListener('click',()=>{

    if(!currentRotation){
        STAGIA.toast(
            'Aucune rotation active aujourd’hui.',
            'danger'
        );
        return;
    }

    $('entryForm').reset();
    $('entryId').value='';
    $('entryModalTitle').textContent='Nouvelle entrée';

    setEntryRotation(currentRotation);
    $('entryDate').value=today;

    activities=[];
    renderActivities();
    setReadOnly(false);

    modal.show();
});

$('entryBody').addEventListener('click',e=>{

    const edit=e.target.closest('.btn-edit');

    if(edit){
        const x=getEntry(edit.dataset.id);
        if(x)ouvrirEntry(x,false);
        return;
    }

    const view=e.target.closest('.btn-view');

    if(view){
        const x=getEntry(view.dataset.id);
        if(x)ouvrirEntry(x,true);
        return;
    }

    const submit=e.target.closest('.btn-submit-entry');

    if(submit){
        soumettre(submit.dataset.id);
    }
});

function ouvrirEntry(x,readOnly=false){

    $('entryForm').reset();

    $('entryId').value=x.id;
    $('entryDate').value=x.date_journal||'';
    $('entrySummary').value=x.resume_activites||'';
    $('entryLearning').value=x.apprentissages||'';
    $('entryDifficulties').value=x.difficultes||'';
    $('entryObservation').value=x.observation_etudiant||'';

    const r=getRotation(x.rotation_id);

    if(r){
        setEntryRotation(r);
    }else{
        $('entryRotationId').value=Number(x.rotation_id||0)||'';
        $('entryAssignmentId').value=Number(x.assignment_id||0)||'';
        $('rotationDisplay').value='Rotation '+Number(x.sequence_no||0)+' - '+(x.unit_name||'-');
    }

    activities=(x.activities||[]).map(a=>({
        type_activite:a.type_activite||'PARTICIPATION',
        intitule:a.intitule||'',
        description:a.description||'',
        niveau_implication:a.niveau_implication||'',
        quantite:Number(a.quantite||1),
        observation:a.observation||''
    }));

    renderActivities();

    $('entryModalTitle').textContent=
        readOnly?'Consulter le journal':'Modifier le journal';

    setReadOnly(readOnly);

    modal.show();
}

function setEntryRotation(r){
    const service=r.parent_name
        ?r.parent_name+' › '+r.unit_name
        :r.unit_name;

    $('entryRotationId').value=Number(r.rotation_id||r.id||0)||'';
    $('entryAssignmentId').value=Number(r.assignment_id||0)||'';

    $('rotationDisplay').value=
        `Rotation ${Number(r.sequence_no||0)} - ${service||'-'}`;
}

function setReadOnly(value){

    [
        'entrySummary',
        'entryLearning',
        'entryDifficulties',
        'entryObservation'
    ].forEach(id=>$(id).disabled=value);

    $('addActivityBtn').classList.toggle('d-none',value);
    $('saveBtn').classList.toggle('d-none',value);

    document.querySelectorAll('.activity-input,.remove-activity')
        .forEach(el=>{
            if(el.classList.contains('remove-activity'))
                el.classList.toggle('d-none',value);
            else
                el.disabled=value;
        });
}

$('addActivityBtn').addEventListener('click',()=>{

    activities.push({
        type_activite:'PARTICIPATION',
        intitule:'',
        description:'',
        niveau_implication:'ASSISTE',
        quantite:1,
        observation:''
    });

    renderActivities();
});

function renderActivities(){

    const root=$('activityContainer');

    if(!activities.length){
        root.innerHTML=`
            <div class="text-center text-muted border rounded-3 py-4">
                <i class="bi bi-clipboard-plus fs-3 d-block mb-2"></i>
                Aucune activité ajoutée.
            </div>`;
        return;
    }

    root.innerHTML=activities.map((a,i)=>`
        <div class="activity-card">

            <div class="activity-head">
                <strong>Activité ${i+1}</strong>

                <button type="button"
                        class="btn btn-sm btn-outline-danger remove-activity"
                        data-index="${i}">
                    <i class="bi bi-trash"></i>
                </button>
            </div>

            <div class="row g-2">

                <div class="col-md-4">
                    <label class="form-label small">Type</label>
                    <select class="form-select activity-input"
                            data-index="${i}"
                            data-field="type_activite">
                        ${option('OBSERVATION','Observation',a.type_activite)}
                        ${option('PARTICIPATION','Participation',a.type_activite)}
                        ${option('REALISATION','Réalisation',a.type_activite)}
                        ${option('GARDE','Garde',a.type_activite)}
                        ${option('CONSULTATION','Consultation',a.type_activite)}
                        ${option('AUTRE','Autre',a.type_activite)}
                    </select>
                </div>

                <div class="col-md-8">
                    <label class="form-label small">Intitulé *</label>
                    <input type="text"
                           class="form-control activity-input"
                           data-index="${i}"
                           data-field="intitule"
                           value="${escapeAttr(a.intitule)}">
                </div>

                <div class="col-md-5">
                    <label class="form-label small">Niveau d'implication</label>
                    <select class="form-select activity-input"
                            data-index="${i}"
                            data-field="niveau_implication">
                        <option value="">Sélectionner...</option>
                        ${option('OBSERVE','Observé',a.niveau_implication)}
                        ${option('ASSISTE','Assisté',a.niveau_implication)}
                        ${option('REALISE_SUPERVISE','Réalisé sous supervision',a.niveau_implication)}
                        ${option('REALISE_AUTONOME','Réalisé en autonomie',a.niveau_implication)}
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small">Quantité</label>
                    <input type="number"
                           min="1"
                           class="form-control activity-input"
                           data-index="${i}"
                           data-field="quantite"
                           value="${Number(a.quantite||1)}">
                </div>

                <div class="col-md-4">
                    <label class="form-label small">Observation</label>
                    <input type="text"
                           class="form-control activity-input"
                           data-index="${i}"
                           data-field="observation"
                           value="${escapeAttr(a.observation)}">
                </div>

                <div class="col-12">
                    <label class="form-label small">Description</label>
                    <textarea class="form-control activity-input"
                              rows="2"
                              data-index="${i}"
                              data-field="description">${STAGIA.escape(a.description||'')}</textarea>
                </div>

            </div>
        </div>
    `).join('');
}

$('activityContainer').addEventListener('input',e=>{

    const input=e.target.closest('.activity-input');

    if(!input)return;

    const index=Number(input.dataset.index);
    const field=input.dataset.field;

    if(!activities[index])return;

    activities[index][field]=
        field==='quantite'
        ?Math.max(1,Number(input.value||1))
        :input.value;
});

$('activityContainer').addEventListener('click',e=>{

    const btn=e.target.closest('.remove-activity');

    if(!btn)return;

    activities.splice(Number(btn.dataset.index),1);
    renderActivities();
});

$('entryForm').addEventListener('submit',async e=>{

    e.preventDefault();

    if(!$('entryId').value && !currentRotation){
        STAGIA.toast(
            'Aucune rotation active aujourd’hui.',
            'danger'
        );
        return;
    }

    const invalid=activities.find(a=>!String(a.intitule||'').trim());

    if(invalid){
        STAGIA.toast(
            'Complétez l’intitulé de chaque activité.',
            'danger'
        );
        return;
    }

    $('activitiesJson').value=JSON.stringify(activities);

    const btn=$('saveBtn');
    STAGIA.loading(btn,true);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/etudiants/student-logbook-save.php',
            new FormData(e.target)
        );

        modal.hide();
        STAGIA.toast(r.message);
        await charger();

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading(btn,false);
    }
});

async function soumettre(id){

    if(!confirm('Soumettre ce journal à votre encadreur ?')){
        return;
    }

    const data=new FormData();
    data.append('csrf',CSRF);
    data.append('id',id);

    try{
        const r=await STAGIA.post(
            BASE_URL+'/actions/etudiants/student-logbook-submit.php',
            data
        );

        STAGIA.toast(r.message);
        await charger();

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

function getEntry(id){
    return entries.find(x=>Number(x.id)===Number(id));
}

function getRotation(id){
    return rotations.find(x=>Number(x.rotation_id)===Number(id));
}

function option(value,label,selected){
    return `<option value="${value}" ${value===selected?'selected':''}>${label}</option>`;
}

function escapeAttr(v){
    return String(v||'')
        .replace(/&/g,'&amp;')
        .replace(/"/g,'&quot;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;');
}

function dateFr(v){
    if(!v)return '-';

    const p=String(v).substring(0,10).split('-');

    return p.length===3
        ?`${p[2]}/${p[1]}/${p[0]}`
        :v;
}

$('entrySearch').addEventListener('input',afficher);

charger();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

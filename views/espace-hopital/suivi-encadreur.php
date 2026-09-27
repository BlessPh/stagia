<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requirePermission($pdo,'supervision.hosting.view');

if(empty($_SESSION['csrf'])){
    $_SESSION['csrf']=bin2hex(random_bytes(32));
}

$requestedTab=($_GET['tab']??'')==='logbook'
    ?'logbook'
    :(($_GET['tab']??'')==='attendance'
        ?'attendance'
        :'overview');

$pageTitle='Suivi encadreur';

$activePage=match($requestedTab){
    'attendance'=>'encadreur-presences',
    'logbook'=>'encadreur-journaux',
    default=>'encadreur-suivi'
};

require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Suivi des stagiaires</h1>
        <p>Présences, journaux soumis et validation par rotation.</p>
    </div>
</div>

<div class="stagia-list-card p-3 mb-4">
<div class="row g-3 align-items-end">

    <div class="col-lg-8">
        <label class="form-label">Rotation *</label>
        <select id="rotationFilter" class="form-select"></select>
    </div>

    <div class="col-lg-4">
        <label class="form-label">Date des présences</label>
        <input type="date"
               id="dateFilter"
               class="form-control"
               max="<?= date('Y-m-d') ?>">
    </div>

</div>
</div>

<div id="rotationInfo" class="mb-4"></div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card">
        <div><span>STAGIAIRES</span><strong id="kStudents">0</strong><small>Rotation sélectionnée</small></div>
        <div class="stagia-kpi-icon kpi-blue"><i class="bi bi-people"></i></div>
    </div>

    <div class="stagia-kpi-card">
        <div><span>POINTÉS</span><strong id="kPointed">0</strong><small>Date sélectionnée</small></div>
        <div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div>
    </div>

    <div class="stagia-kpi-card">
        <div><span>NON POINTÉS</span><strong id="kMissing">0</strong><small>À vérifier</small></div>
        <div class="stagia-kpi-icon kpi-orange"><i class="bi bi-person-x"></i></div>
    </div>

    <div class="stagia-kpi-card">
        <div><span>JOURNAUX À VALIDER</span><strong id="kJournals">0</strong><small>Soumis par les stagiaires</small></div>
        <div class="stagia-kpi-icon kpi-purple"><i class="bi bi-journal-check"></i></div>
    </div>
</div>

<div class="stagia-list-card">

    <div class="p-3 border-bottom">
        <ul class="nav nav-pills gap-2" id="followupTabs">
            <li class="nav-item">
                <button class="nav-link active"
                        type="button"
                        data-tab="attendance">
                    <i class="bi bi-calendar-check me-1"></i>
                    Présences
                </button>
            </li>

            <li class="nav-item">
                <button class="nav-link"
                        type="button"
                        data-tab="logbook">
                    <i class="bi bi-journal-medical me-1"></i>
                    Journaux
                </button>
            </li>
        </ul>
    </div>

    <div id="attendancePanel">
        <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
            <tr>
                <th>STAGIAIRE</th>
                <th>ARRIVÉE</th>
                <th>DÉPART</th>
                <th>SOURCE</th>
                <th>STATUT</th>
                <th class="text-center">ACTION</th>
            </tr>
            </thead>
            <tbody id="attendanceRows"></tbody>
        </table>
        </div>
    </div>

    <div id="logbookPanel" class="d-none">
        <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead>
            <tr>
                <th>STAGIAIRE</th>
                <th>DATE</th>
                <th>RÉSUMÉ</th>
                <th>ACTIVITÉS</th>
                <th>STATUT</th>
                <th class="text-center">ACTION</th>
            </tr>
            </thead>
            <tbody id="logbookRows"></tbody>
        </table>
        </div>
    </div>

</div>

</main>

<!-- MODAL PRESENCE -->
<div class="modal fade" id="attendanceModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered">
<div class="modal-content">

<form id="attendanceForm">

<div class="modal-header">
    <div>
        <h5 class="modal-title">Valider / corriger la présence</h5>
        <small class="text-muted" id="attendanceStudent"></small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="rotation_id" id="attRotationId">
<input type="hidden" name="student_id" id="attStudentId">
<input type="hidden" name="date_presence" id="attDate">

<div class="mb-3">
    <label class="form-label">Statut *</label>
    <select name="statut" id="attStatus" class="form-select" required>
        <option value="PRESENT">Présent</option>
        <option value="RETARD">Retard</option>
        <option value="ABSENT">Absent</option>
        <option value="JUSTIFIE">Justifié</option>
        <option value="GARDE">Garde</option>
    </select>
</div>

<div class="mb-3" id="lateMinutesBox">
    <label class="form-label">Minutes de retard</label>
    <input type="number"
           min="0"
           name="minutes_retard"
           id="attMinutes"
           class="form-control"
           value="0">
</div>

<div>
    <label class="form-label">Observation</label>
    <textarea name="observation"
              class="form-control"
              rows="3"
              placeholder="Motif ou précision interne..."></textarea>
</div>

<div id="attendanceWarning" class="alert alert-warning mt-3 mb-0 d-none"></div>

</div>

<div class="modal-footer">
    <button type="button"
            class="btn btn-light border"
            data-bs-dismiss="modal">
        Annuler
    </button>

    <button class="btn btn-primary-stagia" id="saveAttendanceBtn">
        Enregistrer
    </button>
</div>

</form>
</div>
</div>
</div>

<!-- MODAL JOURNAL -->
<div class="modal fade" id="journalModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content">

<form id="journalForm">

<div class="modal-header">
    <div>
        <h5 class="modal-title">Traiter le journal</h5>
        <small class="text-muted" id="journalStudent"></small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="id" id="journalId">

<div class="border rounded-3 p-3 mb-3">
    <small class="text-muted">Résumé</small>
    <div id="journalSummary" class="mt-1"></div>
</div>

<div class="mb-3">
    <label class="form-label">Décision *</label>
    <select name="decision" id="journalDecision" class="form-select" required>
        <option value="VALIDE">Valider</option>
        <option value="REJETE">Rejeter / demander correction</option>
    </select>
</div>

<div>
    <label class="form-label">Commentaire</label>
    <textarea name="commentaire"
              id="journalComment"
              class="form-control"
              rows="4"
              placeholder="Obligatoire en cas de rejet."></textarea>
</div>

</div>

<div class="modal-footer">
    <button type="button"
            class="btn btn-light border"
            data-bs-dismiss="modal">
        Annuler
    </button>

    <button class="btn btn-primary-stagia" id="saveJournalBtn">
        Enregistrer la décision
    </button>
</div>

</form>
</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>';
const $=id=>document.getElementById(id);
const esc=v=>STAGIA.escape(v??'');

const attendanceModal=new bootstrap.Modal($('attendanceModal'));
const journalModal=new bootstrap.Modal($('journalModal'));

const INITIAL_TAB='<?= htmlspecialchars($requestedTab,ENT_QUOTES,'UTF-8') ?>';

let data={
    rotations:[],
    students:[],
    journals:[],
    stats:{},
    permissions:{},
    selected:null
};

function frDate(v){
    if(!v)return '—';
    const p=String(v).slice(0,10).split('-');
    return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;
}

function time(v){
    return v?String(v).slice(0,5):'—';
}

function attendanceBadge(v){
    const map={
        PRESENT:['PRÉSENT','bg-success'],
        RETARD:['RETARD','bg-warning text-dark'],
        ABSENT:['ABSENT','bg-danger'],
        JUSTIFIE:['JUSTIFIÉ','bg-info text-dark'],
        GARDE:['GARDE','bg-primary']
    };

    if(!v){
        return '<span class="badge bg-light text-dark">NON POINTÉ</span>';
    }

    const x=map[v]||[v,'bg-secondary'];

    return `<span class="badge ${x[1]}">${esc(x[0])}</span>`;
}

function journalBadge(v){
    const map={
        SOUMIS:['À VALIDER','bg-warning text-dark'],
        VALIDE:['VALIDÉ','bg-success'],
        REJETE:['REJETÉ','bg-danger']
    };

    const x=map[v]||[v,'bg-secondary'];

    return `<span class="badge ${x[1]}">${esc(x[0])}</span>`;
}

async function load(){
    try{
        const qs=new URLSearchParams();

        if($('rotationFilter').value){
            qs.set('rotation_id',$('rotationFilter').value);
        }

        if($('dateFilter').value){
            qs.set('date',$('dateFilter').value);
        }

        const r=await STAGIA.request(
            BASE_URL+
            '/actions/espace-hopital/supervisor-followup-list.php?'+
            qs.toString()
        );

        data=r.data;

        renderFilters();
        renderInfo();
        renderKpi();
        renderAttendance();
        renderJournals();

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }
}

function renderFilters(){

    const current=String(data.selected?.rotation_id||'');

    $('rotationFilter').innerHTML=(data.rotations||[]).length
        ?data.rotations.map(r=>`
            <option value="${r.rotation_id}">
                ${esc(r.unit_name)}
                · ${frDate(r.date_debut)} → ${frDate(r.date_fin)}
                · ${esc(r.statut)}
                ${r.group_name?' · '+esc(r.group_name):''}
            </option>
        `).join('')
        :'<option value="">Aucune rotation accessible</option>';

    $('rotationFilter').value=current;

    $('dateFilter').value=data.selected_date||data.today||'';

    if(data.selected){
        $('dateFilter').min=data.selected.date_debut||'';
        $('dateFilter').max=
            (data.selected.date_fin||'')<data.today
            ?data.selected.date_fin
            :data.today;
    }
}

function renderInfo(){

    const r=data.selected;

    if(!r){
        $('rotationInfo').innerHTML=`
            <div class="alert alert-warning mb-0">
                Aucune rotation accessible.
            </div>`;
        return;
    }

    const cls=r.statut==='ACTIVE'
        ?'alert-success'
        :r.statut==='PLANIFIEE'
            ?'alert-warning'
            :'alert-light border';

    $('rotationInfo').innerHTML=`
        <div class="alert ${cls} mb-0">
            <strong>${esc(r.unit_name)}</strong>
            · ${frDate(r.date_debut)} → ${frDate(r.date_fin)}
            · ${esc(r.statut)}
            <br>
            <small>
                ${esc(r.campaign_code||'')}
                ${r.campaign_title?' · '+esc(r.campaign_title):''}
            </small>
        </div>`;
}

function renderKpi(){
    const s=data.stats||{};

    $('kStudents').textContent=s.students||0;
    $('kPointed').textContent=s.pointed||0;
    $('kMissing').textContent=s.not_pointed||0;
    $('kJournals').textContent=s.journals_pending||0;
}

function renderAttendance(){

    const rows=data.students||[];

    $('attendanceRows').innerHTML=rows.length
        ?rows.map(x=>{
            const name=[x.nom,x.postnom,x.prenom]
                .filter(Boolean).join(' ');

            const canAct=
                data.permissions.attendance_review &&
                data.selected_date<=data.today;

            return `<tr>
                <td>
                    <strong>${esc(name)}</strong>
                    <small class="d-block text-muted">${esc(x.stagia_code||'')}</small>
                </td>

                <td>${time(x.heure_arrivee)}</td>
                <td>${time(x.heure_depart)}</td>

                <td>
                    ${x.source
                        ?`<span class="small text-muted">${esc(x.source)}</span>`
                        :'—'}
                </td>

                <td>
                    ${attendanceBadge(x.attendance_status)}
                    ${x.attendance_status==='RETARD'
                        ?`<small class="d-block text-muted">${Number(x.minutes_retard||0)} min</small>`
                        :''}
                </td>

                <td class="text-center">
                    ${canAct
                        ?`<button class="btn btn-sm btn-outline-primary att-review"
                                  data-student-id="${x.student_id}"
                                  data-name="${esc(name)}"
                                  data-status="${esc(x.attendance_status||'')}"
                                  data-minutes="${Number(x.minutes_retard||0)}"
                                  data-has-attendance="${x.attendance_id?1:0}">
                            ${x.attendance_id?'Valider / corriger':'Marquer absence'}
                          </button>`
                        :'—'}
                </td>
            </tr>`;
        }).join('')
        :'<tr><td colspan="6" class="text-center py-5 text-muted">Aucun stagiaire.</td></tr>';

    document.querySelectorAll('.att-review').forEach(btn=>{
        btn.onclick=()=>openAttendance(btn);
    });
}

function openAttendance(btn){

    $('attendanceForm').reset();

    $('attRotationId').value=data.selected.rotation_id;
    $('attStudentId').value=btn.dataset.studentId;
    $('attDate').value=data.selected_date;
    $('attendanceStudent').textContent=
        `${btn.dataset.name} · ${frDate(data.selected_date)}`;

    const hasAttendance=btn.dataset.hasAttendance==='1';

    if(hasAttendance){
        $('attStatus').innerHTML=`
            <option value="PRESENT">Présent</option>
            <option value="RETARD">Retard</option>
            <option value="ABSENT">Absent</option>
            <option value="JUSTIFIE">Justifié</option>
            <option value="GARDE">Garde</option>
        `;
        $('attStatus').value=btn.dataset.status||'PRESENT';
        $('attMinutes').value=btn.dataset.minutes||0;
        $('attendanceWarning').classList.add('d-none');
    }else{
        $('attStatus').innerHTML=`
            <option value="ABSENT">Absent</option>
            <option value="JUSTIFIE">Justifié</option>
        `;
        $('attStatus').value='ABSENT';
        $('attMinutes').value=0;

        $('attendanceWarning').classList.remove('d-none');
        $('attendanceWarning').textContent=
            "Aucun pointage d'arrivée : vous pouvez uniquement constater une absence ou une absence justifiée.";
    }

    toggleLateMinutes();
    attendanceModal.show();
}

function toggleLateMinutes(){
    $('lateMinutesBox').classList.toggle(
        'd-none',
        $('attStatus').value!=='RETARD'
    );
}

$('attStatus').onchange=toggleLateMinutes;

$('attendanceForm').onsubmit=async e=>{
    e.preventDefault();

    try{
        STAGIA.loading($('saveAttendanceBtn'),true);

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/espace-hopital/supervisor-attendance-review.php',
            new FormData(e.currentTarget)
        );

        STAGIA.toast(r.message);
        attendanceModal.hide();
        await load();

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('saveAttendanceBtn'),false);
    }
};

function renderJournals(){

    const rows=data.journals||[];

    $('logbookRows').innerHTML=rows.length
        ?rows.map(x=>{
            const name=[x.nom,x.postnom,x.prenom]
                .filter(Boolean).join(' ');

            return `<tr>
                <td>
                    <strong>${esc(name)}</strong>
                    <small class="d-block text-muted">${esc(x.stagia_code||'')}</small>
                </td>

                <td>${frDate(x.date_journal)}</td>

                <td style="max-width:420px">
                    <div class="text-truncate" style="max-width:400px">
                        ${esc(x.resume_activites||'—')}
                    </div>

                    ${x.commentaire_encadreur
                        ?`<small class="d-block text-muted mt-1">
                            ${esc(x.commentaire_encadreur)}
                          </small>`
                        :''}
                </td>

                <td>
                    <span class="badge bg-light text-dark">
                        ${Number(x.activities_count||0)}
                    </span>
                </td>

                <td>${journalBadge(x.statut)}</td>

                <td class="text-center">
                    ${x.statut==='SOUMIS' && data.permissions.logbook_review
                        ?`<button class="btn btn-sm btn-outline-primary journal-review"
                                  data-id="${x.id}"
                                  data-name="${esc(name)}">
                            Traiter
                          </button>`
                        :'<span class="text-muted">—</span>'}
                </td>
            </tr>`;
        }).join('')
        :'<tr><td colspan="6" class="text-center py-5 text-muted">Aucun journal soumis pour cette rotation.</td></tr>';

    document.querySelectorAll('.journal-review').forEach(btn=>{
        btn.onclick=()=>{
            const x=rows.find(
                j=>Number(j.id)===Number(btn.dataset.id)
            );

            if(!x)return;

            $('journalForm').reset();
            $('journalId').value=x.id;
            $('journalStudent').textContent=
                `${btn.dataset.name} · ${frDate(x.date_journal)}`;
            $('journalSummary').textContent=x.resume_activites||'—';

            journalModal.show();
        };
    });
}

$('journalForm').onsubmit=async e=>{
    e.preventDefault();

    if(
        $('journalDecision').value==='REJETE' &&
        !$('journalComment').value.trim()
    ){
        STAGIA.toast(
            'Ajoutez un commentaire pour demander une correction.',
            'danger'
        );
        return;
    }

    try{
        STAGIA.loading($('saveJournalBtn'),true);

        const r=await STAGIA.post(
            BASE_URL+
            '/actions/espace-hopital/supervisor-logbook-review.php',
            new FormData(e.currentTarget)
        );

        STAGIA.toast(r.message);
        journalModal.hide();
        await load();

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading($('saveJournalBtn'),false);
    }
};

function activateTab(tab){
    const wanted=tab==='logbook'?'logbook':'attendance';

    document.querySelectorAll('#followupTabs .nav-link')
        .forEach(btn=>{
            btn.classList.toggle(
                'active',
                btn.dataset.tab===wanted
            );
        });

    const attendance=wanted==='attendance';

    $('attendancePanel').classList.toggle(
        'd-none',
        !attendance
    );

    $('logbookPanel').classList.toggle(
        'd-none',
        attendance
    );
}

document.querySelectorAll('#followupTabs .nav-link').forEach(btn=>{
    btn.onclick=()=>activateTab(btn.dataset.tab);
});

$('rotationFilter').onchange=load;
$('dateFilter').onchange=load;

activateTab(
    INITIAL_TAB==='logbook'
        ?'logbook'
        :'attendance'
);

load();

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

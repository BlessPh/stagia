<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL','ENCADREUR','POINTEUR']);
if(function_exists('contextPermission')&&!contextPermission(['attendance.hosting.view','attendance.hosting.record','attendance.manage'])){
    http_response_code(403);exit('Accès refusé.');
}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$isPointer=($_SESSION['role_code']??'')==='POINTEUR';
$pageTitle='Présences';$activePage='hopital-presences';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Présences</h1>
        <p><?= $isPointer?'Pointez les stagiaires uniquement dans votre périmètre autorisé.':'Enregistrez, contrôlez et validez les présences des stagiaires en rotation.' ?></p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if(($_SESSION['role_code']??'')==='ADMIN_ACCUEIL' && (!function_exists('contextPermission')||contextPermission('attendance.device.view'))): ?>
        <a href="<?= BASE_URL ?>/views/espace-hopital/appareils-pointage.php" class="btn btn-outline-secondary">
            <i class="bi bi-fingerprint me-1"></i>Appareils
        </a>
        <?php endif; ?>
        <div style="width:190px"><input type="date" id="filterDate" class="form-control" value="<?= date('Y-m-d') ?>"></div>
    </div>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>STAGIAIRES</span><strong id="statTotal">0</strong><small>Dans votre périmètre</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-people"></i></div></div>
    <div class="stagia-kpi-card"><div><span>PRÉSENTS</span><strong id="statPresent">0</strong><small>Présences normales</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span>RETARDS</span><strong id="statLate">0</strong><small>Arrivées tardives</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-clock"></i></div></div>
    <div class="stagia-kpi-card"><div><span>ABSENCES</span><strong id="statAbsent">0</strong><small id="absenceInfo">Dont 0 justifiée</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-person-x"></i></div></div>
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar">
    <div><h5 class="mb-1">Feuille de présence</h5><small class="text-muted" id="dateLabel"></small></div>
    <div style="max-width:320px;width:100%"><input type="search" id="search" class="form-control" placeholder="Rechercher un stagiaire..."></div>
</div>
<div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>STAGIAIRE</th><th>UNIVERSITÉ</th><th>SERVICE / UNITÉ</th><th>ARRIVÉE</th><th>DÉPART</th><th>SOURCE</th><th>STATUT</th><th class="text-center">ACTION</th></tr></thead>
<tbody id="attendanceBody"><tr><td colspan="8" class="text-center py-5">Chargement...</td></tr></tbody>
</table></div>
</div>
</main>

<div class="modal fade" id="attendanceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="attendanceForm">
<div class="modal-header"><div><h5 class="modal-title" id="modalTitle">Enregistrer la présence</h5><small class="text-muted" id="studentLabel"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<input type="hidden" name="rotation_id" id="rotationId"><input type="hidden" name="date_presence" id="attendanceDate">
<div class="mb-3"><label class="form-label">Statut *</label><select name="statut" id="status" class="form-select" required>
<option value="PRESENT">Présent</option><option value="RETARD">Retard</option><option value="ABSENT">Absent</option><option value="JUSTIFIE">Absence justifiée</option><option value="GARDE">Garde</option>
</select></div>
<div class="row g-3" id="hoursBox">
<div class="col-6"><label class="form-label">Heure d'arrivée</label><input type="time" name="heure_arrivee" id="arrival" class="form-control"></div>
<div class="col-6"><label class="form-label">Heure de départ</label><input type="time" name="heure_depart" id="departure" class="form-control"></div>
</div>
<div class="mt-3 d-none" id="lateBox"><label class="form-label">Minutes de retard *</label><input type="number" name="minutes_retard" id="lateMinutes" min="1" class="form-control"></div>
<div class="mt-3 d-none" id="justificationBox"><label class="form-label">Justification *</label><textarea name="justification" id="justification" rows="2" class="form-control"></textarea></div>
<div class="mt-3"><label class="form-label">Observation</label><textarea name="observation" id="observation" rows="2" class="form-control"></textarea></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button><button type="submit" class="btn btn-primary-stagia" id="saveBtn"><i class="bi bi-check-lg me-1"></i>Enregistrer</button></div>
</form></div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),modal=new bootstrap.Modal($('attendanceModal'));
let items=[];

async function charger(){
    const date=$('filterDate').value;
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/host-attendance-list.php?date='+encodeURIComponent(date));
        items=r.data.items||[];const s=r.data.stats||{};
        $('statTotal').textContent=s.total||0;$('statPresent').textContent=s.presents||0;$('statLate').textContent=s.retards||0;
        $('statAbsent').textContent=(s.absents||0)+(s.justifies||0);$('absenceInfo').textContent='Dont '+(s.justifies||0)+' justifiée(s)'+((s.a_valider||0)?' · '+s.a_valider+' à valider':'');
        $('dateLabel').textContent='Présences du '+dateFr(date);afficher();
    }catch(e){$('attendanceBody').innerHTML=`<tr><td colspan="8" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;}
}
function afficher(){
    const q=$('search').value.trim().toLowerCase();
    render(items.filter(x=>!q||[x.nom,x.postnom,x.prenom,x.stagia_code,x.university_name,x.unit_name,x.unit_code,x.statut].filter(Boolean).join(' ').toLowerCase().includes(q)));
}
function render(list){
    if(!list.length){$('attendanceBody').innerHTML='<tr><td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-calendar-check fs-2 d-block mb-2"></i>Aucun stagiaire en rotation pour cette date ou votre périmètre.</td></tr>';return;}
    $('attendanceBody').innerHTML=list.map(x=>{
        let action='<span class="text-muted">—</span>';
        const canReview=Number(x.can_review)===1,canRecord=Number(x.can_record)===1;
        const absence=['ABSENT','JUSTIFIE'].includes(String(x.statut||''));
        const attenteDepart=!!x.attendance_id&&!absence&&!!x.heure_arrivee&&!x.heure_depart;

        /* Arrivée et départ sont deux opérations séparées. */
        if(attenteDepart&&(canRecord||canReview)){
            action=`<button type="button" class="btn btn-sm btn-primary-stagia btn-point" data-id="${Number(x.rotation_id)}" data-mode="departure"><i class="bi bi-box-arrow-right me-1"></i>Enregistrer le départ</button>`;
        }else if(canReview){
            action=`<button type="button" class="btn btn-sm btn-outline-primary btn-point" data-id="${Number(x.rotation_id)}" data-mode="review"><i class="bi bi-shield-check me-1"></i>${x.validated_by?'Modifier':'Valider / corriger'}</button>`;
        }else if(canRecord){
            action=`<button type="button" class="btn btn-sm btn-primary-stagia btn-point" data-id="${Number(x.rotation_id)}" data-mode="record"><i class="bi bi-check2-square me-1"></i>${x.attendance_id?'Modifier':'Pointer l’arrivée'}</button>`;
        }else if(x.attendance_id) action='<span class="badge bg-light text-dark border">Enregistré</span>';
        const validation=x.attendance_id?(x.validated_by?'<div class="small text-success mt-1"><i class="bi bi-patch-check"></i> Validé</div>':'<div class="small text-warning mt-1"><i class="bi bi-hourglass-split"></i> À valider</div>'):'';
        return `<tr>
        <td><strong>${STAGIA.escape([x.nom,x.postnom,x.prenom].filter(Boolean).join(' '))}</strong><div class="small text-muted">${STAGIA.escape(x.stagia_code||'-')}</div></td>
        <td>${STAGIA.escape(x.university_name||'-')}</td>
        <td><strong>${STAGIA.escape(x.unit_name||'-')}</strong><div class="small text-muted">${STAGIA.escape(x.unit_code||'')}</div></td>
        <td>${timeFr(x.heure_arrivee)}</td><td>${timeFr(x.heure_depart)}</td>
        <td>${sourceBadge(x)}</td>
        <td>${x.statut?badge(x.statut):'<span class="badge bg-light text-dark">NON POINTÉ</span>'}${validation}</td>
        <td class="text-center">${action}</td></tr>`;
    }).join('');
}
$('attendanceBody').addEventListener('click',e=>{
    const btn=e.target.closest('.btn-point');if(!btn)return;
    const x=items.find(v=>Number(v.rotation_id)===Number(btn.dataset.id));if(x)ouvrir(x,btn.dataset.mode);
});
function nowTime(){
    const d=new Date();
    return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0');
}
function ouvrir(x,mode){
    $('attendanceForm').reset();$('rotationId').value=x.rotation_id;$('attendanceDate').value=$('filterDate').value;
    $('studentLabel').textContent=[x.nom,x.postnom,x.prenom].filter(Boolean).join(' ');

    const nouveau=!x.attendance_id&&mode==='record',departMode=mode==='departure',now=nowTime();
    $('modalTitle').textContent=departMode?'Enregistrer le départ':
        (mode==='review'?(x.validated_by?'Modifier une présence':'Valider / corriger la présence'):
        (nouveau?'Pointer l’arrivée':'Modifier le pointage'));

    $('status').value=nouveau?'PRESENT':(x.statut||'PRESENT');

    /* 1er pointage = arrivée uniquement.
       Le départ n'est renseigné que lors d'un clic explicite sur « Enregistrer le départ ». */
    $('arrival').value=timeValue(x.heure_arrivee)||(nouveau?now:'');
    $('departure').value=timeValue(x.heure_depart)||(departMode?now:'');

    $('lateMinutes').value=x.minutes_retard||'';$('justification').value=x.justification||'';$('observation').value=x.observation||'';
    $('arrival').readOnly=true;$('departure').readOnly=true;

    $('saveBtn').innerHTML=departMode
        ?'<i class="bi bi-box-arrow-right me-1"></i>Enregistrer le départ'
        :(nouveau?'<i class="bi bi-box-arrow-in-right me-1"></i>Enregistrer l’arrivée':'<i class="bi bi-check-lg me-1"></i>Enregistrer');

    updateFields();modal.show();
}
$('status').addEventListener('change',()=>{
    if(!['ABSENT','JUSTIFIE'].includes($('status').value)&&!$('arrival').value)
        $('arrival').value=nowTime();
    updateFields();
});
function updateFields(){
    const s=$('status').value,absence=['ABSENT','JUSTIFIE'].includes(s);
    $('hoursBox').classList.toggle('d-none',absence);$('lateBox').classList.toggle('d-none',s!=='RETARD');$('justificationBox').classList.toggle('d-none',s!=='JUSTIFIE');
    $('arrival').required=!absence;
    /* Le départ est volontairement facultatif : il sera enregistré plus tard. */
    $('departure').required=false;
    $('lateMinutes').required=s==='RETARD';$('justification').required=s==='JUSTIFIE';
}
$('attendanceForm').addEventListener('submit',async e=>{
    e.preventDefault();const btn=$('saveBtn');STAGIA.loading(btn,true);
    try{const r=await STAGIA.post(BASE_URL+'/actions/stages/host-attendance-save.php',new FormData(e.target));modal.hide();STAGIA.toast(r.message);await charger();}
    catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading(btn,false);}
});
function sourceBadge(x){
 const s=x.source||'—',cls={MANUEL:'secondary',BIOMETRIE:'primary',QR:'info',RFID:'warning',MOBILE:'dark',APPAREIL:'primary'}[s]||'light';
 return `<span class="badge bg-${cls}${cls==='info'||cls==='warning'||cls==='light'?' text-dark':''}">${STAGIA.escape(s)}</span>${x.device_name?`<small class="d-block text-muted mt-1">${STAGIA.escape(x.device_name)}</small>`:''}`;
}
function badge(s){return {PRESENT:'<span class="badge bg-success">PRÉSENT</span>',RETARD:'<span class="badge bg-warning text-dark">RETARD</span>',ABSENT:'<span class="badge bg-danger">ABSENT</span>',JUSTIFIE:'<span class="badge bg-info text-dark">JUSTIFIÉ</span>',GARDE:'<span class="badge bg-primary">GARDE</span>'}[s]||s;}
function timeFr(v){return v?String(v).substring(0,5):'-';}function timeValue(v){return v?String(v).substring(0,5):'';}
function dateFr(v){if(!v)return '-';const p=String(v).substring(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;}
$('filterDate').addEventListener('change',charger);$('search').addEventListener('input',afficher);charger();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

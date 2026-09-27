<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';

requireRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL','MINISTERE','ORDRE_MEDECINS']);
$roleCode=$_SESSION['role_code']??'';
$isSuper=$roleCode==='SUPER_ADMIN';
$isNational=in_array($roleCode,['MINISTERE','ORDRE_MEDECINS'],true);

if(!$isSuper && !$isNational)requirePermission($pdo,'report.view');

$pageTitle='Rapports & statistiques';
$activePage='admin-statistiques';
require_once __DIR__.'/../../../includes/app-header.php';
?>
<style>
.stats-tabs{display:flex;gap:8px;flex-wrap:wrap}.stats-tab{border:1px solid #e2e8f0;background:#fff;border-radius:10px;padding:9px 15px;font-weight:600}.stats-tab.active{background:#111827;color:#fff;border-color:#111827}
.stats-grid,.stage-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.stats-card,.stats-panel{background:#fff;border:1px solid #e7ebf0;border-radius:16px;padding:18px}.stats-card h6{font-size:11px;letter-spacing:.04em;color:#64748b;margin:0 0 8px;text-transform:uppercase}.stats-card strong{font-size:29px;line-height:1;color:#0f172a}.stats-sub{font-size:12px;color:#64748b;margin-top:7px}.stats-panel{height:100%}.stats-panel-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:15px}.stats-panel-title h5{font-size:16px;margin:0}
.bar-row{margin-bottom:13px}.bar-head{display:flex;justify-content:space-between;gap:10px;font-size:13px;margin-bottom:5px}.bar-track{height:8px;border-radius:10px;background:#eef2f7;overflow:hidden}.bar-fill{height:100%;border-radius:10px;background:#f97316}.donut{width:180px;height:180px;border-radius:50%;position:relative;margin:10px auto;background:#eef2f7}.donut:after{content:"";position:absolute;inset:32px;background:#fff;border-radius:50%}.legend-item{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:13px}.legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:7px}.trend{display:flex;align-items:flex-end;gap:4px;height:130px;padding-top:10px;border-bottom:1px solid #e5e7eb}.trend-bar{flex:1;min-width:5px;border-radius:4px 4px 0 0;background:#f97316}.trend-label{font-size:11px;color:#64748b;margin-top:7px;text-align:right}.activity-card{display:flex;align-items:center;gap:12px;border:1px solid #eef2f7;border-radius:12px;padding:12px}.activity-icon{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:#fff3e9;color:#f97316;font-size:18px}.activity-card strong{font-size:20px}.stage-filters{background:#fff;border:1px solid #e7ebf0;border-radius:14px;padding:14px}.metric{font-size:12px;color:#64748b}.metric strong{font-size:16px;color:#0f172a}.mini-progress{height:6px;background:#eef2f7;border-radius:10px;overflow:hidden}.mini-progress>span{display:block;height:100%;background:#f97316}.status-pill{font-size:10px;padding:4px 7px;border-radius:999px;background:#f1f5f9;color:#475569}
@media(max-width:1200px){.stats-grid,.stage-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.stats-grid,.stage-grid{grid-template-columns:1fr}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Rapports & statistiques</h1>
        <p><?= $isNational
            ?($roleCode==='ORDRE_MEDECINS'
                ?'Supervision nationale des stages et indicateurs de suivi médical.'
                :'Pilotage national des stages, établissements et indicateurs de suivi.')
            :'Analyse administrative et suivi opérationnel des stages dans votre périmètre.' ?></p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
        <select id="period" class="form-select" style="min-width:190px">
            <option value="7">7 derniers jours</option>
            <option value="30" selected>30 derniers jours</option>
            <option value="90">90 derniers jours</option>
            <option value="365">12 derniers mois</option>
            <option value="all">Depuis le début</option>
        </select>
        <a id="exportExcel" class="btn btn-outline-success" href="#">
            <i class="bi bi-file-earmark-excel me-1"></i> Excel
        </a>
        <a id="exportPdf" class="btn btn-outline-danger" href="#" target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i> PDF
        </a>
    </div>
</div>

<div class="stats-tabs mb-4">
    <?php if(!$isNational): ?>
    <button type="button" class="stats-tab" data-view="admin"><i class="bi bi-gear me-1"></i> Administration</button>
    <?php endif; ?>
    <button type="button" class="stats-tab active" data-view="stages"><i class="bi bi-briefcase me-1"></i> Stages</button>
</div>

<section id="viewStages">
<div class="stage-filters mb-4">
<div class="row g-2">
    <?php if($isSuper||$isNational): ?>
    <div class="col-lg-2" id="universityFilterWrap"><select id="universityFilter" class="form-select"><option value="">Tous les établissements de formation</option></select></div>
    <?php else: ?>
    <div class="d-none"><select id="universityFilter"><option value=""></option></select></div>
    <?php endif; ?>
    <div class="<?=($isSuper||$isNational)?'col-lg-2':'col-lg-3'?>"><select id="stageTypeFilter" class="form-select"><option value="">Tous les types de stage</option></select></div>
    <div class="<?=($isSuper||$isNational)?'col-lg-2':'col-lg-3'?>"><select id="campaignFilter" class="form-select"><option value="">Toutes les campagnes</option></select></div>
    <div class="<?=($isSuper||$isNational)?'col-lg-2':'col-lg-3'?>" id="hostFilterWrap"><select id="hostFilter" class="form-select"><option value="">Tous les hôpitaux</option></select></div>
    <div class="col-lg-2"><select id="stageStatusFilter" class="form-select"><option value="">Tous les statuts</option><option value="CONFIRME">Confirmé</option><option value="TERMINE">Terminé</option><option value="ANNULE">Annulé</option></select></div>
    <div class="<?=($isSuper||$isNational)?'col-lg-2':'col-lg-1'?>"><button id="refreshStage" class="btn btn-light border w-100" title="Actualiser"><i class="bi bi-arrow-clockwise"></i> <?=($isSuper||$isNational)?'Actualiser':''?></button></div>
</div>
</div>

<div class="stage-grid mb-4">
    <div class="stats-card"><h6>Stagiaires</h6><strong id="sStudents">0</strong><div class="stats-sub"><span id="sActive">0</span> actuellement en stage</div></div>
    <div class="stats-card"><h6>Stages terminés</h6><strong id="sFinished">0</strong><div class="stats-sub"><span id="sPlacements">0</span> placement(s) sur la période</div></div>
    <div class="stats-card"><h6>Présence moyenne</h6><strong><span id="sAttendance">0</span>%</strong><div class="stats-sub"><span id="sAttendanceCount">0</span> pointage(s)</div></div>
    <div class="stats-card"><h6>Progression tâches</h6><strong><span id="sProgress">0</span>%</strong><div class="stats-sub"><span id="sTasksValidated">0</span> tâche(s) validée(s)</div></div>
    <div class="stats-card"><h6>Journaux validés</h6><strong id="sJournals">0</strong><div class="stats-sub">Entrées validées par l'encadrement</div></div>
    <div class="stats-card"><h6>Évaluations finalisées</h6><strong id="sEvaluations">0</strong><div class="stats-sub">Évaluations officiellement finalisées</div></div>
    <div class="stats-card"><h6>Conventions signées</h6><strong id="sConventions">0</strong><div class="stats-sub">Signées ou archivées</div></div>
    <div class="stats-card"><h6>Rotations</h6><strong id="sRotations">0</strong><div class="stats-sub"><span id="sCampaigns">0</span> campagne(s)</div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8"><div class="stats-panel"><div class="stats-panel-title"><h5>Placements créés</h5><span class="text-muted small" id="stagePeriodLabel"></span></div><div id="placementTrend" class="trend"></div><div class="trend-label" id="placementTrendRange"></div></div></div>
    <div class="col-xl-4"><div class="stats-panel"><div class="stats-panel-title"><h5>Répartition par type de stage</h5></div><div id="stageTypeBars"></div></div></div>
</div>

<div class="stats-panel mb-4">
    <div class="stats-panel-title"><h5>Suivi par stagiaire</h5><span class="text-muted small">100 placements maximum</span></div>
    <div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
        <thead><tr><th>STAGIAIRE</th><th>STAGE</th><th>ACCUEIL</th><th>PRÉSENCE</th><th>PROGRESSION</th><th>ÉVALUATION</th><th>CONVENTION</th><th>STATUT</th></tr></thead>
        <tbody id="studentRows"><tr><td colspan="8" class="text-center py-4 text-muted">Chargement...</td></tr></tbody>
    </table></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6"><div class="stats-panel"><div class="stats-panel-title"><h5>Par campagne</h5></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>CAMPAGNE</th><th class="text-center">STAGIAIRES</th><th class="text-center">CONFIRMÉS</th><th class="text-center">TERMINÉS</th></tr></thead><tbody id="campaignRows"></tbody></table></div></div></div>
    <div class="col-xl-6"><div class="stats-panel"><div class="stats-panel-title"><h5>Par établissement d'accueil</h5></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>ÉTABLISSEMENT</th><th class="text-center">STAGIAIRES</th><th class="text-center">CONFIRMÉS</th><th class="text-center">TERMINÉS</th></tr></thead><tbody id="hostRows"></tbody></table></div></div></div>
</div>
</section>

<?php if(!$isNational): ?>
<section id="viewAdmin" class="d-none">
<div class="stats-grid mb-4">
    <div class="stats-card"><h6>Utilisateurs</h6><strong id="kUsers">0</strong><div class="stats-sub"><span id="kUsersActive">0</span> actifs · <span id="kUsersPending">0</span> à activer</div></div>
    <div class="stats-card"><h6><?= $isSuper?'Établissements':'Établissement' ?></h6><strong id="kEstablishments">0</strong><div class="stats-sub"><?= $isSuper?'<span id="kAdhesionsPending">0</span> adhésion(s) en traitement':'Périmètre institutionnel courant' ?></div></div>
    <div class="stats-card"><h6><?= $isSuper?'Rôles / Permissions':'Rôles utilisés / Permissions' ?></h6><strong><span id="kRoles">0</span> / <span id="kPermissions">0</span></strong><div class="stats-sub">Rôles / permissions</div></div>
    <div class="stats-card"><h6>Affectations actives</h6><strong id="kAssignments">0</strong><div class="stats-sub"><span id="kAssignmentsRevoked">0</span> révoquée(s)</div></div>
</div>
<div class="row g-4 mb-4"><div class="col-xl-8"><div class="stats-panel"><div class="stats-panel-title"><h5>Activité de la période</h5><span class="text-muted small" id="periodLabel"></span></div><div class="row g-3">
<div class="<?= $isSuper?'col-md-3':'col-md-6' ?>"><div class="activity-card"><div class="activity-icon"><i class="bi bi-person-plus"></i></div><div><strong id="aUsers">0</strong><div class="text-muted small">Nouveaux utilisateurs</div></div></div></div>
<?php if($isSuper): ?><div class="col-md-3"><div class="activity-card"><div class="activity-icon"><i class="bi bi-building-add"></i></div><div><strong id="aEstablishments">0</strong><div class="text-muted small">Établissements créés</div></div></div></div><?php endif; ?>
<div class="<?= $isSuper?'col-md-3':'col-md-6' ?>"><div class="activity-card"><div class="activity-icon"><i class="bi bi-person-badge"></i></div><div><strong id="aAssignments">0</strong><div class="text-muted small">Affectations créées</div></div></div></div>
<?php if($isSuper): ?><div class="col-md-3"><div class="activity-card"><div class="activity-icon"><i class="bi bi-file-earmark-check"></i></div><div><strong id="aAdhesions">0</strong><div class="text-muted small">Demandes d’adhésion</div></div></div></div><?php endif; ?>
</div><div class="mt-4"><div class="d-flex justify-content-between"><strong class="small">Création d’utilisateurs</strong><span class="text-muted small">par jour</span></div><div id="userTrend" class="trend"></div><div class="trend-label" id="userTrendRange"></div></div></div></div>
<div class="col-xl-4"><div class="stats-panel"><div class="stats-panel-title"><h5>État des comptes</h5></div><div id="accountDonut" class="donut"></div><div id="accountLegend"></div></div></div></div>
<div class="row g-4 mb-4"><div class="col-xl-6"><div class="stats-panel"><div class="stats-panel-title"><h5>Utilisateurs par rôle actif</h5></div><div id="roleBars"></div></div></div><div class="col-xl-6"><div class="stats-panel"><div class="stats-panel-title"><h5>Affectations par périmètre</h5></div><div id="scopeBars"></div></div></div></div>
<?php if($isSuper): ?><div class="row g-4 mb-4"><div class="col-xl-5"><div class="stats-panel"><div class="stats-panel-title"><h5>Établissements par type</h5></div><div id="typeBars"></div></div></div><div class="col-xl-7"><div class="stats-panel"><div class="stats-panel-title"><h5>Top établissements</h5></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>ÉTABLISSEMENT</th><th>TYPE</th><th class="text-center">UTILISATEURS</th><th class="text-center">AFFECTATIONS</th></tr></thead><tbody id="topEstablishments"></tbody></table></div></div></div></div><?php endif; ?>
<div class="row g-4"><div class="col-12"><div class="stats-panel"><div class="stats-panel-title"><h5>Nouvelles affectations</h5></div><div id="assignmentTrend" class="trend"></div><div class="trend-label" id="assignmentTrendRange"></div></div></div></div>
</section>
<?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',SUPER=<?=$isSuper?'true':'false'?>,NATIONAL=<?=$isNational?'true':'false'?>,$=id=>document.getElementById(id),esc=STAGIA.escape;
let filtersReady=false;
function bars(id,items){const max=Math.max(1,...items.map(x=>+x.total||0));$(id).innerHTML=items.length?items.map(x=>`<div class="bar-row"><div class="bar-head"><span>${esc(x.label||x.nom||x.code||'—')}</span><strong>${+x.total||0}</strong></div><div class="bar-track"><div class="bar-fill" style="width:${((+x.total||0)/max)*100}%"></div></div></div>`).join(''):'<div class="text-muted py-3">Aucune donnée.</div>'}
function trend(id,range,items){const max=Math.max(1,...items.map(x=>+x.total||0));$(id).innerHTML=items.length?items.map(x=>`<div class="trend-bar" title="${esc(x.jour)} : ${+x.total||0}" style="height:${Math.max(6,(+x.total||0)/max*100)}%"></div>`).join(''):'<div class="text-muted m-auto">Aucune activité.</div>';$(range).textContent=items.length?`${items[0].jour} → ${items[items.length-1].jour}`:''}
function donut(items){const total=items.reduce((s,x)=>s+(+x.total||0),0);if(!total){$('accountDonut').style.background='#eef2f7';$('accountLegend').innerHTML='<div class="text-muted text-center">Aucune donnée.</div>';return}const colors=['#f97316','#2563eb','#16a34a','#7c3aed'];let a=0,p=[];items.forEach((x,i)=>{const v=(+x.total||0)/total*360;p.push(`${colors[i%colors.length]} ${a}deg ${a+v}deg`);a+=v});$('accountDonut').style.background=`conic-gradient(${p.join(',')})`;$('accountLegend').innerHTML=items.map((x,i)=>`<div class="legend-item"><span><i class="legend-dot" style="background:${colors[i%colors.length]}"></i>${esc(x.label||'—')}</span><strong>${+x.total||0}</strong></div>`).join('')}
function pct(v){v=Number(v||0);return Math.max(0,Math.min(100,v))}
function stageBadge(s){const m={CONFIRME:'Confirmé',TERMINE:'Terminé',ANNULE:'Annulé'};return `<span class="status-pill">${esc(m[s]||s||'—')}</span>`}
function renderStage(d){
 const s=d.stages||{},k=s.kpis||{},a=s.attendance||{};
 $('sStudents').textContent=k.students||0;$('sActive').textContent=k.active_now||0;$('sFinished').textContent=k.finished||0;$('sPlacements').textContent=k.placements||0;
 $('sAttendance').textContent=k.attendance_rate||0;$('sAttendanceCount').textContent=a.total||0;$('sProgress').textContent=k.task_progress||0;$('sTasksValidated').textContent=k.tasks_validated||0;
 $('sJournals').textContent=k.journals_validated||0;$('sEvaluations').textContent=k.evaluations_finalized||0;$('sConventions').textContent=k.conventions_signed||0;$('sRotations').textContent=k.rotations||0;$('sCampaigns').textContent=k.campaigns||0;
 $('stagePeriodLabel').textContent=$('period').options[$('period').selectedIndex].text;trend('placementTrend','placementTrendRange',s.placement_trend||[]);bars('stageTypeBars',s.stage_types||[]);
 const students=s.students||[];$('studentRows').innerHTML=students.length?students.map(x=>`<tr><td><strong>${esc(x.student_name)}</strong><small class="d-block text-muted">${esc(x.stagia_code||'—')}</small></td><td><strong>${esc(x.campaign_title||'—')}</strong><small class="d-block text-muted">${esc(x.stage_type||'')}</small><small class="d-block text-muted">${esc(x.date_debut||'')} → ${esc(x.date_fin||'')}</small></td><td>${esc(x.host_name||'—')}<small class="d-block text-muted">${esc(x.university_name||'')}</small></td><td><div class="metric"><strong>${pct(x.attendance_rate)}%</strong></div><div class="mini-progress"><span style="width:${pct(x.attendance_rate)}%"></span></div></td><td><div class="metric"><strong>${pct(x.task_progress)}%</strong></div><div class="mini-progress"><span style="width:${pct(x.task_progress)}%"></span></div></td><td>${esc(x.evaluation_status||'—')}</td><td>${esc(x.convention_status||'—')}</td><td>${stageBadge(x.statut)}</td></tr>`).join(''):'<tr><td colspan="8" class="text-center py-4 text-muted">Aucun placement sur cette période.</td></tr>';
 $('campaignRows').innerHTML=(s.campaigns||[]).length?(s.campaigns||[]).map(x=>`<tr><td><strong>${esc(x.titre)}</strong><small class="d-block text-muted">${esc(x.code||'')} · ${esc(x.stage_type||'')}</small></td><td class="text-center">${+x.students||0}</td><td class="text-center">${+x.confirmed||0}</td><td class="text-center">${+x.finished||0}</td></tr>`).join(''):'<tr><td colspan="4" class="text-center text-muted py-3">Aucune donnée.</td></tr>';
 $('hostRows').innerHTML=(s.hosts||[]).length?(s.hosts||[]).map(x=>`<tr><td><strong>${esc(x.nom)}</strong><small class="d-block text-muted">${esc(x.code||'')}</small></td><td class="text-center">${+x.students||0}</td><td class="text-center">${+x.confirmed||0}</td><td class="text-center">${+x.finished||0}</td></tr>`).join(''):'<tr><td colspan="4" class="text-center text-muted py-3">Aucune donnée.</td></tr>';
 if(!filtersReady){const f=s.filters||{};
 if($('universityFilter'))$('universityFilter').innerHTML='<option value="">Tous les établissements de formation</option>'+(f.universities||[]).map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');
 $('stageTypeFilter').innerHTML='<option value="">Tous les types de stage</option>'+(f.stage_types||[]).map(x=>`<option value="${x.id}">${esc(x.libelle)}</option>`).join('');$('campaignFilter').innerHTML='<option value="">Toutes les campagnes</option>'+(f.campaigns||[]).map(x=>`<option value="${x.id}">${esc(x.titre)}${x.code?' — '+esc(x.code):''}</option>`).join('');if((f.hosts||[]).length)$('hostFilter').innerHTML='<option value="">Tous les hôpitaux</option>'+(f.hosts||[]).map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');else $('hostFilterWrap').classList.add('d-none');filtersReady=true}
}
function renderAdmin(d){const k=d.kpis||{},a=d.activity||{};$('kUsers').textContent=k.users||0;$('kUsersActive').textContent=k.users_active||0;$('kUsersPending').textContent=k.users_pending||0;$('kEstablishments').textContent=k.establishments||0;if(SUPER)$('kAdhesionsPending').textContent=k.adhesion_pending||0;$('kRoles').textContent=k.roles||0;$('kPermissions').textContent=k.permissions||0;$('kAssignments').textContent=k.assignments_active||0;$('kAssignmentsRevoked').textContent=k.assignments_revoked||0;$('aUsers').textContent=a.new_users||0;$('aAssignments').textContent=a.new_assignments||0;if(SUPER){$('aEstablishments').textContent=a.new_establishments||0;$('aAdhesions').textContent=a.new_adhesions||0}$('periodLabel').textContent=$('period').options[$('period').selectedIndex].text;donut(d.accounts||[]);bars('roleBars',d.roles||[]);bars('scopeBars',d.scopes||[]);trend('userTrend','userTrendRange',d.user_trend||[]);trend('assignmentTrend','assignmentTrendRange',d.assignment_trend||[]);if(SUPER){bars('typeBars',d.establishment_types||[]);$('topEstablishments').innerHTML=(d.top_establishments||[]).map(e=>`<tr><td><strong>${esc(e.nom)}</strong><small class="d-block text-muted">${esc(e.code||'')}</small></td><td>${esc(e.type_etablissement||'—')}</td><td class="text-center">${+e.users_count||0}</td><td class="text-center">${+e.assignments_count||0}</td></tr>`).join('')}}
function updateExportLinks(){
 const q=new URLSearchParams({period:$('period').value});
 if($('universityFilter')&&$('universityFilter').value)q.set('university_id',$('universityFilter').value);
 if($('campaignFilter').value)q.set('campaign_id',$('campaignFilter').value);
 if($('stageTypeFilter').value)q.set('stage_type_id',$('stageTypeFilter').value);
 if($('hostFilter').value)q.set('host_id',$('hostFilter').value);
 if($('stageStatusFilter').value)q.set('stage_status',$('stageStatusFilter').value);

 $('exportExcel').href=BASE+'/actions/admin/statistiques/export-excel.php?'+q.toString();
 $('exportPdf').href=BASE+'/actions/admin/statistiques/export-pdf.php?'+q.toString()+'&download=0';
}

async function load(){updateExportLinks();const q=new URLSearchParams({period:$('period').value});if($('universityFilter')&&$('universityFilter').value)q.set('university_id',$('universityFilter').value);if($('campaignFilter').value)q.set('campaign_id',$('campaignFilter').value);if($('stageTypeFilter').value)q.set('stage_type_id',$('stageTypeFilter').value);if($('hostFilter').value)q.set('host_id',$('hostFilter').value);if($('stageStatusFilter').value)q.set('stage_status',$('stageStatusFilter').value);try{const r=await STAGIA.request(BASE+'/actions/admin/statistiques/data.php?'+q);renderStage(r.data);if(!NATIONAL)renderAdmin(r.data)}catch(e){STAGIA.toast(e.message,'danger')}}
document.querySelectorAll('.stats-tab').forEach(b=>b.onclick=()=>{
 document.querySelectorAll('.stats-tab').forEach(x=>x.classList.remove('active'));
 b.classList.add('active');
 const stage=b.dataset.view==='stages';
 $('viewStages').classList.toggle('d-none',!stage);
 if($('viewAdmin'))$('viewAdmin').classList.toggle('d-none',stage);
});
$('period').onchange=load;['universityFilter','stageTypeFilter','campaignFilter','hostFilter','stageStatusFilter'].forEach(id=>{if($(id))$(id).onchange=load});$('refreshStage').onclick=load;load();
});
</script>
<?php require_once __DIR__.'/../../../includes/app-footer.php'; ?>

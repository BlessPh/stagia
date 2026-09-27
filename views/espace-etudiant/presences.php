<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
requireRole(['STAGIAIRE']);
$pageTitle='Mes présences';$activePage='student-presences';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.pres-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px}.pres-kpi,.pres-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px}.pres-kpi{padding:16px}.pres-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.pres-kpi strong{font-size:26px}@media(max-width:1000px){.pres-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.pres-kpis{grid-template-columns:1fr}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Mes présences</h1><p>Consultez votre historique de présence en stage.</p></div><button id="refreshBtn" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Actualiser</button></div>
<div class="pres-kpis mb-4"><div class="pres-kpi"><small>Total</small><strong id="kTotal">0</strong></div><div class="pres-kpi"><small>Présents</small><strong id="kPresent">0</strong></div><div class="pres-kpi"><small>Retards</small><strong id="kLate">0</strong></div><div class="pres-kpi"><small>Absences</small><strong id="kAbsent">0</strong></div><div class="pres-kpi"><small>Gardes</small><strong id="kGarde">0</strong></div></div>
<div class="pres-card p-3 mb-3"><div class="row g-2"><div class="col-md-4"><input type="date" id="from" class="form-control"></div><div class="col-md-4"><input type="date" id="to" class="form-control"></div><div class="col-md-4"><select id="status" class="form-select"><option value="">Tous les statuts</option><option value="PRESENT">Présent</option><option value="RETARD">Retard</option><option value="ABSENT">Absent</option><option value="JUSTIFIE">Justifié</option><option value="GARDE">Garde</option></select></div></div></div>
<div class="pres-card"><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>Date</th><th>Hôpital</th><th>Service</th><th>Arrivée</th><th>Départ</th><th>Statut</th><th>Observation</th></tr></thead><tbody id="body"><tr><td colspan="7" class="text-center py-5">Chargement...</td></tr></tbody></table></div></div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
function d(v){if(!v)return '-';const p=String(v).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v}function t(v){return v?String(v).slice(0,5):'-'}
function badge(s){return {PRESENT:'<span class="badge bg-success">PRÉSENT</span>',RETARD:'<span class="badge bg-warning text-dark">RETARD</span>',ABSENT:'<span class="badge bg-danger">ABSENT</span>',JUSTIFIE:'<span class="badge bg-info text-dark">JUSTIFIÉ</span>',GARDE:'<span class="badge bg-primary">GARDE</span>'}[s]||`<span class="badge bg-light text-dark">${esc(s||'-')}</span>`;}
function qs(){const q=new URLSearchParams();if($('from').value)q.set('from',$('from').value);if($('to').value)q.set('to',$('to').value);if($('status').value)q.set('status',$('status').value);return q.toString();}
async function load(){try{const r=await STAGIA.request(BASE+'/actions/espace-etudiant/attendance-list.php?'+qs()),s=r.data.stats||{},items=r.data.items||[];$('kTotal').textContent=s.total||0;$('kPresent').textContent=s.presents||0;$('kLate').textContent=s.retards||0;$('kAbsent').textContent=(s.absents||0)+(s.justifies||0);$('kGarde').textContent=s.gardes||0;$('body').innerHTML=items.length?items.map(x=>`<tr><td>${d(x.date_presence)}</td><td>${esc(x.host_name||'-')}</td><td><strong>${esc(x.unit_name||'-')}</strong><div class="small text-muted">Rotation ${Number(x.sequence_no)||''}</div></td><td>${t(x.heure_arrivee)}</td><td>${t(x.heure_depart)}</td><td>${badge(x.statut)}</td><td>${esc(x.observation||x.justification||'-')}</td></tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune présence enregistrée.</td></tr>';}catch(e){$('body').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;}}
['from','to','status'].forEach(id=>$(id).onchange=load);$('refreshBtn').onclick=load;load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

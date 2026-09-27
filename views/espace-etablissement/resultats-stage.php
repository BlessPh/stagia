<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Résultats reçus';
$activePage='university-results';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.result-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.result-kpi,.result-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px}.result-kpi{padding:16px}.result-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.result-kpi strong{font-size:26px}.result-card{overflow:hidden}.mini{font-size:12px;color:#64748b}.student-result{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px;margin:8px 0}@media(max-width:900px){.result-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.result-kpis{grid-template-columns:1fr}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Résultats reçus</h1><p>Consultez les résultats transmis par les hôpitaux et validez-les dans le dossier académique.</p></div><button id="refreshBtn" class="btn btn-light border"><i class="bi bi-arrow-clockwise me-1"></i>Actualiser</button></div>

<div class="result-kpis mb-4">
    <div class="result-kpi"><small>Total</small><strong id="kTotal">0</strong></div>
    <div class="result-kpi"><small>Envoyés</small><strong id="kSent">0</strong></div>
    <div class="result-kpi"><small>Reçus</small><strong id="kReceived">0</strong></div>
    <div class="result-kpi"><small>Validés</small><strong id="kValid">0</strong></div>
</div>

<div class="result-card">
    <div class="p-3 border-bottom"><input id="search" class="form-control" placeholder="Rechercher une session, hôpital ou étudiant..." style="max-width:460px"></div>
    <div id="list"><div class="text-center py-5">Chargement...</div></div>
</div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',CSRF='<?=htmlspecialchars($_SESSION['csrf'])?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
let items=[],timer;
function dateFr(v){if(!v)return '-';const p=String(v).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;}
function b(s){return {ENVOYE:'<span class="badge bg-primary">ENVOYÉ</span>',RECU:'<span class="badge bg-info text-dark">REÇU</span>',VALIDE:'<span class="badge bg-success">VALIDÉ</span>',ARCHIVE:'<span class="badge bg-dark">ARCHIVÉ</span>'}[s]||s;}
async function load(){
    const q=$('search').value.trim()?('?q='+encodeURIComponent($('search').value.trim())):'';
    try{
        const r=await STAGIA.request(BASE+'/actions/stages/university-results-list.php'+q),s=r.data.stats||{};
        items=r.data.items||[];$('kTotal').textContent=s.total||0;$('kSent').textContent=s.envoyes||0;$('kReceived').textContent=s.recus||0;$('kValid').textContent=s.valides||0;render();
    }catch(e){$('list').innerHTML=`<div class="text-center py-5 text-danger">${esc(e.message)}</div>`;}
}
function render(){
    $('list').innerHTML=items.length?items.map(x=>`
        <div class="p-3 border-bottom">
            <div class="d-flex justify-content-between gap-3 flex-wrap">
                <div>
                    <h5 class="mb-1">${esc(x.campaign_title||'-')}</h5>
                    <div class="mini">${esc(x.campaign_code||'')} · ${esc(x.host_name||'-')} · transmis le ${dateFr(x.transmitted_at)}</div>
                    ${x.observation?`<div class="mini mt-1"><strong>Observation :</strong> ${esc(x.observation)}</div>`:''}
                </div>
                <div class="text-end">${b(x.statut)}<div class="mt-2">${actions(x)}</div></div>
            </div>
            <div class="mt-3">
                ${(x.items||[]).map(it=>`<div class="student-result">
                    <div class="d-flex justify-content-between gap-2 flex-wrap">
                        <div><strong>${esc(it.student_name)}</strong><div class="mini">${esc(it.stagia_code||'')} · ${Number(it.rotation_count||0)} rotation(s)</div></div>
                        <div class="text-end"><strong>${Number(it.final_score||0).toLocaleString('fr-FR')}%</strong><div class="mini">${esc(it.decision)}</div></div>
                    </div>
                    <div class="mini mt-1">Présence ${Number(it.presence_rate||0).toLocaleString('fr-FR')}% · Journaux validés ${Number(it.validated_journal_count||0)}/${Number(it.journal_count||0)} · Évaluations ${Number(it.evaluation_count||0)}</div>
                </div>`).join('')}
            </div>
        </div>
    `).join(''):'<div class="text-center py-5 text-muted">Aucun résultat transmis.</div>';
    document.querySelectorAll('.act').forEach(b=>b.onclick=()=>review(Number(b.dataset.id),b.dataset.action));
}
function actions(x){
    const a=[];
    if(x.statut==='ENVOYE')a.push(`<button class="btn btn-sm btn-outline-primary act" data-id="${x.id}" data-action="RECEIVE">Marquer reçu</button>`);
    if(['ENVOYE','RECU'].includes(x.statut))a.push(`<button class="btn btn-sm btn-success act" data-id="${x.id}" data-action="VALIDATE">Valider</button>`);
    if(['RECU','VALIDE'].includes(x.statut))a.push(`<button class="btn btn-sm btn-outline-secondary act" data-id="${x.id}" data-action="ARCHIVE">Archiver</button>`);
    return a.join(' ');
}
async function review(id,action){
    if(!STAGIA.confirm('Confirmer cette action ?'))return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('id',id);fd.append('action',action);
    try{const r=await STAGIA.post(BASE+'/actions/stages/university-results-review.php',fd);STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}
}
$('refreshBtn').onclick=load;$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300)};load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

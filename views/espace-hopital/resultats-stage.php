<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','AUTORITE_HOSPITALIERE']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Résultats de stage';
$activePage='hospital-results';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.result-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.result-card,.result-kpi{background:#fff;border:1px solid #e7ebf0;border-radius:14px}.result-kpi{padding:16px}.result-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.result-kpi strong{font-size:26px}.result-card{overflow:hidden}.metric{font-size:12px;color:#64748b}@media(max-width:900px){.result-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.result-kpis{grid-template-columns:1fr}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Résultats de stage</h1><p>Consolidez les présences, journaux et évaluations puis transmettez les résultats à l’université.</p></div><button id="refreshBtn" class="btn btn-light border"><i class="bi bi-arrow-clockwise me-1"></i>Actualiser</button></div>

<div class="alert alert-light border"><i class="bi bi-info-circle text-primary me-1"></i> Une transmission est possible dès qu’au moins une évaluation est validée ou finalisée. Les résultats envoyés restent consultables par l’université.</div>

<div class="result-kpis mb-4">
    <div class="result-kpi"><small>Sessions</small><strong id="kTotal">0</strong></div>
    <div class="result-kpi"><small>Prêtes</small><strong id="kReady">0</strong></div>
    <div class="result-kpi"><small>Envoyées / reçues</small><strong id="kSent">0</strong></div>
    <div class="result-kpi"><small>Validées</small><strong id="kValid">0</strong></div>
</div>

<div class="result-card">
    <div class="p-3 border-bottom"><input id="search" class="form-control" placeholder="Rechercher une session ou université..." style="max-width:420px"></div>
    <div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
        <thead><tr><th>SESSION</th><th>UNIVERSITÉ</th><th>SUIVI</th><th>NOTE</th><th>TRANSMISSION</th><th class="text-end">ACTION</th></tr></thead>
        <tbody id="rows"><tr><td colspan="6" class="text-center py-5">Chargement...</td></tr></tbody>
    </table></div>
</div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',CSRF='<?=htmlspecialchars($_SESSION['csrf'])?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
let items=[],timer;
function dateFr(v){if(!v)return '-';const p=String(v).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;}
function badge(t){return {ENVOYE:'<span class="badge bg-primary">ENVOYÉ</span>',RECU:'<span class="badge bg-info text-dark">REÇU</span>',VALIDE:'<span class="badge bg-success">VALIDÉ</span>',ARCHIVE:'<span class="badge bg-dark">ARCHIVÉ</span>'}[t]||'<span class="badge bg-secondary">NON ENVOYÉ</span>';}
async function load(){
    const q=$('search').value.trim()?('?q='+encodeURIComponent($('search').value.trim())):'';
    try{
        const r=await STAGIA.request(BASE+'/actions/stages/host-results-list.php'+q),s=r.data.stats||{};
        items=r.data.items||[];$('kTotal').textContent=s.total||0;$('kReady').textContent=s.pret||0;$('kSent').textContent=s.envoye||0;$('kValid').textContent=s.valide||0;render();
    }catch(e){$('rows').innerHTML=`<tr><td colspan="6" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;}
}
function render(){
    $('rows').innerHTML=items.length?items.map(x=>{
        const m=x.metrics||{},t=x.transmission;
        const action=x.can_send?`<button class="btn btn-sm btn-primary-stagia send" data-id="${x.campaign_id}"><i class="bi bi-send-check me-1"></i>Envoyer</button>`:'<span class="text-muted small">—</span>';
        return `<tr>
            <td><strong>${esc(x.titre)}</strong><small class="d-block text-muted">${esc(x.code||'')} · ${dateFr(x.date_debut)} → ${dateFr(x.date_fin)}</small></td>
            <td>${esc(x.university_name||'-')}</td>
            <td><strong>${Number(m.students||0)}</strong> stagiaire(s)<div class="metric">${Number(m.evaluation_count||0)} évaluation(s) · ${Number(m.validated_journal_count||0)}/${Number(m.journal_count||0)} journal(aux)</div><div class="metric">Présence : ${Number(m.presence_rate||0).toLocaleString('fr-FR')}%</div></td>
            <td><strong>${Number(m.average_score||0).toLocaleString('fr-FR')}%</strong><div class="metric">${m.ready?'Prêt à transmettre':'Évaluation attendue'}</div></td>
            <td>${badge(t?.statut)}${t?.transmitted_at?`<small class="d-block text-muted">${dateFr(t.transmitted_at)}</small>`:''}</td>
            <td class="text-end">${action}</td>
        </tr>`;
    }).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucun résultat disponible.</td></tr>';
    document.querySelectorAll('.send').forEach(b=>b.onclick=()=>send(Number(b.dataset.id)));
}
async function send(id){
    const obs=prompt("Observation à transmettre à l'université (facultatif) :")||'';
    if(!STAGIA.confirm('Envoyer officiellement ces résultats à l’université ?'))return;
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('campaign_id',id);fd.append('observation',obs);
    try{const r=await STAGIA.post(BASE+'/actions/stages/host-results-send.php',fd);STAGIA.toast(r.message);await load();}
    catch(e){STAGIA.toast(e.message,'danger');}
}
$('refreshBtn').onclick=load;$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,300)};load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

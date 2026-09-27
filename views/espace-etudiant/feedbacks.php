<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
requireRole(['STAGIAIRE']);
$pageTitle='Mes retours';$activePage='student-feedbacks';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.feedback-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.feedback-kpi,.student-feedback{background:#fff;border:1px solid #e7ebf0;border-radius:14px}.feedback-kpi{padding:16px}.feedback-kpi small{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.feedback-kpi strong{font-size:26px}.student-feedback{padding:18px}.feedback-meta{font-size:12px;color:#64748b}.feedback-text{white-space:pre-line}.type-OBSERVATION{background:#eef2ff;color:#4338ca}.type-ENCOURAGEMENT{background:#ecfdf5;color:#047857}.type-A_AMELIORER{background:#fff7ed;color:#c2410c}.type-AVERTISSEMENT{background:#fef2f2;color:#b91c1c}@media(max-width:900px){.feedback-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.feedback-grid{grid-template-columns:1fr}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Mes retours</h1><p>Consultez les feedbacks publiés par vos encadreurs et rendus visibles dans votre suivi.</p></div></div>
<div class="alert alert-light border"><i class="bi bi-info-circle text-primary me-1"></i> Ces retours servent à vous guider pendant le stage. Ils sont distincts de vos <strong>évaluations officielles</strong>.</div>
<div class="feedback-grid mb-4"><div class="feedback-kpi"><small>Total</small><strong id="kTotal">0</strong></div><div class="feedback-kpi"><small>Encouragements</small><strong id="kEncouragement">0</strong></div><div class="feedback-kpi"><small>À améliorer</small><strong id="kImprove">0</strong></div><div class="feedback-kpi"><small>Avertissements</small><strong id="kWarning">0</strong></div></div>
<div class="d-flex justify-content-end mb-3"><select id="typeFilter" class="form-select" style="max-width:260px"><option value="">Tous les types</option><option value="OBSERVATION">Observation</option><option value="ENCOURAGEMENT">Encouragement</option><option value="A_AMELIORER">À améliorer</option><option value="AVERTISSEMENT">Avertissement</option></select></div>
<div id="feedbackList"><div class="student-feedback text-center text-muted py-5">Chargement...</div></div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const labels={OBSERVATION:'Observation',ENCOURAGEMENT:'Encouragement',A_AMELIORER:'À améliorer',AVERTISSEMENT:'Avertissement'};
function dateFr(v){if(!v)return '—';const p=String(v).slice(0,10).split('-');return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;}
function render(items){$('feedbackList').innerHTML=items.length?items.map(x=>`<div class="student-feedback mb-2"><div class="d-flex align-items-center gap-2 flex-wrap"><h5 class="mb-0">${esc(x.titre||labels[x.type_feedback]||'Feedback')}</h5><span class="badge type-${esc(x.type_feedback)}">${esc(labels[x.type_feedback]||x.type_feedback)}</span></div><div class="feedback-meta mt-2"><i class="bi bi-calendar3 me-1"></i>${dateFr(x.date_feedback)}${x.host_name?` · <i class="bi bi-hospital me-1"></i>${esc(x.host_name)}`:''}${x.unit_name?` · ${esc(x.unit_name)}`:''}${x.author_name?` · <i class="bi bi-person me-1"></i>${esc(x.author_name)}`:''}</div><p class="feedback-text mt-3 mb-0">${esc(x.commentaire)}</p></div>`).join(''):'<div class="student-feedback text-center text-muted py-5">Aucun retour publié pour le moment.</div>';}
async function load(){const q=$('typeFilter').value?`?type=${encodeURIComponent($('typeFilter').value)}`:'';try{const r=await STAGIA.request(BASE+'/actions/espace-etudiant/feedback-list.php'+q);const s=r.data.stats||{};$('kTotal').textContent=s.total||0;$('kEncouragement').textContent=s.encouragements||0;$('kImprove').textContent=s.a_ameliorer||0;$('kWarning').textContent=s.avertissements||0;render(r.data.items||[]);}catch(e){STAGIA.toast(e.message,'danger');}}
$('typeFilter').onchange=load;load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

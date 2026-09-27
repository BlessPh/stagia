<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/admin-scope.php';
exigerResponsableMinisteriel($pdo);
$pageTitle='Espace ministère';$activePage='ministere-dashboard';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.ministry-hero{background:linear-gradient(120deg,#111 0%,#272727 65%,#ff751f 180%);color:#fff;border-radius:20px;padding:26px;position:relative;overflow:hidden}
.ministry-hero:after{content:"";position:absolute;width:230px;height:230px;border-radius:50%;right:-75px;top:-95px;background:rgba(255,117,31,.2)}
.ministry-hero>*{position:relative;z-index:1}.ministry-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px}
.ministry-kpi,.ministry-panel{background:#fff;border:1px solid #e8ebef;border-radius:16px}.ministry-kpi{padding:17px}.ministry-kpi span{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em}.ministry-kpi strong{display:block;font-size:27px;margin-top:7px}.ministry-panel{padding:19px;height:100%}
.ministry-bar{margin:12px 0}.ministry-bar-head{display:flex;justify-content:space-between;gap:12px;font-size:13px;margin-bottom:5px}.ministry-track{height:8px;border-radius:8px;background:#edf0f4;overflow:hidden}.ministry-fill{height:100%;background:#ff751f;border-radius:8px}
.ministry-action{border:1px solid #f0f1f3;border-radius:14px;padding:16px;color:#111;text-decoration:none;display:flex;align-items:center;gap:12px;transition:.2s}.ministry-action:hover{transform:translateY(-2px);border-color:#ff751f;color:#111;box-shadow:0 10px 24px rgba(15,23,42,.08)}.ministry-action i{width:40px;height:40px;border-radius:11px;background:#fff0e8;color:#ff751f;display:grid;place-items:center;font-size:18px}
@media(max-width:1200px){.ministry-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:768px){.ministry-grid{grid-template-columns:repeat(2,1fr)}.ministry-hero{padding:20px}}@media(max-width:480px){.ministry-grid{grid-template-columns:1fr}}
</style>
<main class="dashboard-content">
<section class="ministry-hero mb-4"><div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3"><div><span class="badge rounded-pill mb-3" style="background:#ff751f">PILOTAGE INSTITUTIONNEL</span><h1 class="h3 mb-2" id="ministryName">Espace Ministère</h1><p class="mb-0 text-white-50">Supervision des organisations rattachées, de leurs responsables et des indicateurs académiques autorisés.</p></div><div class="text-lg-end"><small class="text-white-50 d-block">Périmètre sécurisé</small><strong id="scopeName">Chargement…</strong></div></div></section>

<section class="ministry-grid mb-4">
<div class="ministry-kpi"><span>Organisations</span><strong id="kOrganisations">0</strong><small class="text-muted">Rattachées au ministère</small></div>
<div class="ministry-kpi"><span>Responsables</span><strong id="kResponsables">0</strong><small class="text-muted">Comptes institutionnels actifs</small></div>
<div class="ministry-kpi"><span>Étudiants</span><strong id="kEtudiants">0</strong><small class="text-muted">Domaine santé</small></div>
<div class="ministry-kpi"><span>Provinces</span><strong id="kProvinces">0</strong><small class="text-muted">Couverture géographique</small></div>
<div class="ministry-kpi"><span>Années</span><strong id="kAnnees">0</strong><small class="text-muted">Années académiques</small></div>
</section>

<section class="row g-4 mb-4">
<div class="col-xl-8"><div class="ministry-panel"><div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Organisations récemment actualisées</h5><a href="<?=BASE_URL?>/views/espace-ministere/organisations.php" class="btn btn-sm btn-outline-dark">Tout afficher</a></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>ORGANISATION</th><th>TYPE</th><th>PROVINCE</th><th>STATUT</th></tr></thead><tbody id="recentRows"><tr><td colspan="4" class="text-center py-4">Chargement…</td></tr></tbody></table></div></div></div>
<div class="col-xl-4"><div class="ministry-panel"><h5 class="mb-3">Accès rapides</h5><div class="d-grid gap-2"><a class="ministry-action" href="<?=BASE_URL?>/views/espace-ministere/organisations.php"><i class="bi bi-buildings"></i><span><strong class="d-block">Organisations</strong><small class="text-muted">Consulter le réseau rattaché</small></span></a><a class="ministry-action" href="<?=BASE_URL?>/views/admin/responsables/index.php"><i class="bi bi-person-lines-fill"></i><span><strong class="d-block">Responsables</strong><small class="text-muted">Annuaire institutionnel</small></span></a><a class="ministry-action" href="<?=BASE_URL?>/views/national/dashboard.php"><i class="bi bi-graph-up-arrow"></i><span><strong class="d-block">Statistiques</strong><small class="text-muted">Analyse académique détaillée</small></span></a></div></div></div>
</section>

<section class="row g-4"><div class="col-xl-4"><div class="ministry-panel"><h5 class="mb-3">Par type d’organisation</h5><div id="typeBars"></div></div></div><div class="col-xl-4"><div class="ministry-panel"><h5 class="mb-3">Couverture provinciale</h5><div id="provinceBars"></div></div></div><div class="col-xl-4"><div class="ministry-panel"><h5 class="mb-3">Effectifs par année</h5><div id="yearBars"></div></div></div></section>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{const B='<?=BASE_URL?>',E=STAGIA.escape,$=id=>document.getElementById(id);
function bars(id,items){const max=Math.max(1,...items.map(x=>Number(x.total)||0));$(id).innerHTML=items.length?items.map(x=>`<div class="ministry-bar"><div class="ministry-bar-head"><span>${E(x.label||'—')}</span><strong>${Number(x.total)||0}</strong></div><div class="ministry-track"><div class="ministry-fill" style="width:${(Number(x.total||0)/max)*100}%"></div></div></div>`).join(''):'<p class="text-muted">Aucune donnée dans ce périmètre.</p>'}
async function load(){try{const r=await STAGIA.request(B+'/actions/ministere/dashboard.php'),d=r.data,k=d.kpis,m=d.ministeres||[];$('ministryName').textContent=m[0]?.nom||'Espace Ministère';$('scopeName').textContent=m.length?m.map(x=>x.nom).join(', '):'Aucun ministère rattaché';$('kOrganisations').textContent=k.organisations;$('kResponsables').textContent=k.responsables;$('kEtudiants').textContent=k.etudiants;$('kProvinces').textContent=k.provinces;$('kAnnees').textContent=k.annees;bars('typeBars',d.types||[]);bars('provinceBars',d.provinces||[]);bars('yearBars',d.annees||[]);$('recentRows').innerHTML=(d.organisations_recentes||[]).length?d.organisations_recentes.map(x=>`<tr><td><strong>${E(x.nom)}</strong><small class="d-block text-muted">${E(x.code||'')}</small></td><td><span class="badge bg-light text-dark border">${E(x.type_etablissement)}</span></td><td>${E(x.province||'—')}</td><td><span class="badge bg-success-subtle text-success-emphasis">${E(x.statut)}</span></td></tr>`).join(''):'<tr><td colspan="4" class="text-center py-4 text-muted">Aucune organisation rattachée.</td></tr>';}catch(e){STAGIA.toast(e.message,'danger')}}load();});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

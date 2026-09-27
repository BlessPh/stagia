<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
requirePermission($pdo,'capacity.hosting.view');
if(!contextHostEnabled()){http_response_code(403);exit("Cet établissement n'est pas une structure d'accueil.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle="Capacités d'accueil";$activePage='hospital-capacities';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Capacités d'accueil D4</h1><p>Définir la capacité globale de chaque campagne d'accueil et contrôler les allocations aux universités.</p></div></div>
<div class="alert alert-light border"><i class="bi bi-info-circle text-primary me-1"></i>
<strong>La capacité est définie uniquement par l'hôpital.</strong> La réserve hospitalière reste hors allocation universitaire. STAGIA bloque toute réduction qui provoquerait une surallocation.</div>
<div class="stagia-list-card"><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>CAMPAGNE D'ACCUEIL</th><th>CAPACITÉ GLOBALE</th><th>RÉSERVE HÔPITAL</th><th>ALLOUÉ AUX UNIVERSITÉS</th><th>ENCORE ALLOUABLE</th><th>RÉSERVATIONS ACTIVES</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="rows"><tr><td colspan="7" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table></div></div></main>

<div class="modal fade" id="capacityModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="capacityForm"><div class="modal-header"><div><h5 class="modal-title">Définir la capacité</h5><small class="text-muted" id="campaignTitle"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>"><input type="hidden" name="host_campaign_id" id="campaignId">
<div class="row g-3"><div class="col-md-6"><label class="form-label">Capacité globale *</label><input type="number" min="0" name="capacite_totale" id="total" class="form-control" required></div>
<div class="col-md-6"><label class="form-label">Réserve hospitalière *</label><input type="number" min="0" name="reserve_hospitaliere" id="reserve" class="form-control" required></div></div>
<div class="alert alert-warning mt-3 mb-0"><small>Capacité allouable = capacité globale − réserve hospitalière. Les allocations déjà retenues ne peuvent pas être écrasées.</small></div></div>
<div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="saveBtn">Enregistrer la capacité</button></div></form>
</div></div></div>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('capacityModal'));let items=[],permissions={};
async function load(){try{const r=await STAGIA.request(BASE_URL+'/actions/espace-hopital/capacity-list.php');items=r.data.items||[];permissions=r.data.permissions||{};
$('rows').innerHTML=items.length?items.map(x=>`<tr><td><strong>${esc(x.titre)}</strong><small class="d-block text-muted">${esc(x.code||'—')} · ${esc(x.statut)}</small></td>
<td>${x.capacity_defined?`<strong>${x.capacite_totale}</strong>`:'<span class="badge bg-warning-subtle text-warning">À définir</span>'}</td><td>${x.capacity_defined?x.reserve_hospitaliere:'—'}</td>
<td><strong>${x.allocated_total}</strong><small class="d-block text-muted">${x.participations_count} participation(s)</small></td><td><strong>${x.capacity_defined?x.allocation_available:'—'}</strong></td><td>${x.active_reservations}</td>
<td class="text-end">${permissions.manage?`<button class="btn btn-sm btn-outline-primary edit" data-id="${x.id}"><i class="bi bi-sliders me-1"></i>${x.capacity_defined?'Modifier':'Définir'}</button>`:'—'}</td></tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune campagne d’accueil D4.</td></tr>';
document.querySelectorAll('.edit').forEach(b=>b.onclick=()=>open(Number(b.dataset.id)));}catch(e){STAGIA.toast(e.message,'danger');}}
function open(id){const x=items.find(v=>Number(v.id)===id);if(!x)return;$('campaignId').value=x.id;$('campaignTitle').textContent=`${x.code} — ${x.titre}`;$('total').value=x.capacity_defined?x.capacite_totale:'';$('reserve').value=x.capacity_defined?x.reserve_hospitaliere:0;modal.show();}
$('capacityForm').onsubmit=async e=>{e.preventDefault();STAGIA.loading($('saveBtn'),true);try{const r=await STAGIA.post(BASE_URL+'/actions/espace-hopital/capacity-save.php',new FormData(e.currentTarget));STAGIA.toast(r.message);modal.hide();await load();}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading($('saveBtn'),false);}};
load();});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

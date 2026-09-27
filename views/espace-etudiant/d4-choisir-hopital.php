<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
requirePermission($pdo,'reservation.self.view');
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle='Choix hôpital D4';$activePage='student-d4-choice';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Choisir mon hôpital — D4</h1><p>Choix manuel parmi les hôpitaux retenus par votre université et disposant encore de places dans son allocation.</p></div></div>
<div class="alert alert-light border"><i class="bi bi-info-circle text-primary me-1"></i>STAGIA ne choisit pas l'hôpital à votre place. Votre choix réserve une place dans l’allocation. Il est d’abord transmis à votre université, qui confirme ensuite votre placement vers l’hôpital.</div>
<div id="campaigns"></div>
<h5 class="mt-4">Mes réservations D4</h5>
<div class="stagia-list-card"><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>CAMPAGNE</th><th>HÔPITAL</th><th>RÉSERVATION</th><th>PAIEMENT</th><th>PLACEMENT</th></tr></thead><tbody id="reservationRows"><tr><td colspan="5" class="text-center py-4 text-muted">Chargement...</td></tr></tbody></table></div></div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape;let data={};
function badge(s){const m={CONFIRMEE:'bg-success-subtle text-success',EN_ATTENTE_PAIEMENT:'bg-warning-subtle text-warning',RESERVEE_TEMPORAIREMENT:'bg-info-subtle text-info',EXPIREE:'bg-secondary-subtle text-secondary',ANNULEE:'bg-danger-subtle text-danger'};return `<span class="badge ${m[s]||'bg-light text-dark'}">${esc(s||'—')}</span>`;}
function activeReservation(campaignId){
    return (data.reservations||[]).find(r=>
        Number(r.campaign_id)===Number(campaignId) &&
        ['RESERVEE_TEMPORAIREMENT','EN_ATTENTE_PAIEMENT','CONFIRMEE'].includes(r.statut)
    );
}
async function load(){try{const r=await STAGIA.request(BASE_URL+'/actions/espace-etudiant/d4-options-list.php');data=r.data;const groups={};(data.items||[]).forEach(x=>(groups[x.campaign_id]??=[]).push(x));
$('campaigns').innerHTML=Object.keys(groups).length?Object.entries(groups).map(([cid,arr])=>`<div class="stagia-list-card mb-3"><div class="p-3 border-bottom"><strong>${esc(arr[0].campaign_code)} — ${esc(arr[0].campaign_title)}</strong><small class="d-block text-muted">${esc(arr[0].promotion_nom)} · ${esc(arr[0].niveau_code)} · ${esc(arr[0].annee_libelle||'')}</small></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>HÔPITAL</th><th>LOCALISATION</th><th>CONDITIONS</th><th>PLACES RESTANTES</th><th>FRAIS</th><th class="text-end">ACTION</th></tr></thead><tbody>${arr.map(x=>`<tr><td><strong>${esc(x.host_nom)}</strong><small class="d-block text-muted">${esc(x.host_campaign_title||'')}</small></td><td>${esc([x.ville,x.province].filter(Boolean).join(' · ')||'—')}</td><td>${esc(x.conditions||'—')}</td><td><strong>${x.places_restantes}</strong> / ${x.capacite_acceptee}</td><td>${Number(x.frais_requis)===1?`${esc(x.montant_frais||'0')} ${esc(x.devise||'')}`:'Aucun'}</td><td class="text-end">${(()=>{
    const ar=activeReservation(x.campaign_id);
    if(ar){
        if(Number(ar.participation_id)===Number(x.participation_id)){
            return '<span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>Hôpital choisi</span>';
        }
        return '<span class="badge bg-secondary-subtle text-secondary">Déjà réservé</span>';
    }
    return x.places_restantes>0
        ?`<button class="btn btn-sm btn-primary-stagia reserve" data-campaign="${x.campaign_id}" data-academic="${x.academic_enrollment_id}" data-participation="${x.participation_id}" data-host="${esc(x.host_nom)}">Choisir et réserver</button>`
        :'<span class="badge bg-secondary-subtle text-secondary">Complet</span>';
})()}</td></tr>`).join('')}</tbody></table></div></div>`).join(''):'<div class="alert alert-warning">Aucune campagne D4 ouverte avec une offre hospitalière disponible pour votre inscription actuelle.</div>';
$('reservationRows').innerHTML=(data.reservations||[]).length?data.reservations.map(x=>`<tr><td>${esc(x.campaign_code)} — ${esc(x.campaign_title)}</td><td>${esc(x.host_nom)}</td><td>${badge(x.statut)}${x.expires_at?`<small class="d-block text-muted">Échéance : ${esc(x.expires_at)}</small>`:''}</td><td>${Number(x.frais_requis)===1?`${esc(x.montant_frais||'0')} ${esc(x.devise||'')}`:'Aucun'}</td><td>${
    x.placement_id && x.placement_status==='CONFIRME'
        ?'<span class="badge bg-success-subtle text-success">PLACEMENT CONFIRMÉ</span>'
        :(x.statut==='CONFIRMEE'
            ?'<span class="badge bg-warning-subtle text-warning">EN ATTENTE UNIVERSITÉ</span>'
            :'—')
}</td></tr>`).join(''):'<tr><td colspan="5" class="text-center py-4 text-muted">Aucune réservation.</td></tr>';
document.querySelectorAll('.reserve').forEach(b=>b.onclick=()=>reserve(b));}catch(e){STAGIA.toast(e.message,'danger');}}
async function reserve(b){if(!STAGIA.confirm(`Choisir « ${b.dataset.host} » pour cette campagne D4 ?`))return;const fd=new FormData();fd.append('csrf','<?= $_SESSION['csrf'] ?>');fd.append('campaign_id',b.dataset.campaign);fd.append('academic_enrollment_id',b.dataset.academic);fd.append('participation_id',b.dataset.participation);try{const r=await STAGIA.post(BASE_URL+'/actions/espace-etudiant/d4-reserve.php',fd);STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}}
load();});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

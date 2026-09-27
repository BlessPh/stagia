<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL']);
requirePermission($pdo,'attendance.device.view');
if(!contextHostEnabled()){http_response_code(403);exit("Cet établissement n'est pas une structure d'accueil.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Appareils de pointage';$activePage='hopital-presences';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Appareils de pointage</h1><p>Configurez les terminaux biométriques, QR, RFID/NFC ou passerelles API de votre établissement.</p></div>
    <button class="btn btn-primary-stagia" id="newDevice"><i class="bi bi-plus-lg me-1"></i>Nouvel appareil</button>
</div>

<div class="alert alert-light border">
    <i class="bi bi-shield-lock me-1 text-primary"></i>
    STAGIA ne stocke pas les empreintes ni les gabarits faciaux. Il conserve uniquement l’identifiant externe envoyé par le terminal.
</div>

<div class="stagia-list-card mb-4">
<div class="stagia-list-toolbar"><div><h5 class="mb-1">Terminaux</h5><small class="text-muted">Un appareil peut couvrir tout l’établissement ou une unité précise.</small></div></div>
<div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>APPAREIL</th><th>TYPE</th><th>PÉRIMÈTRE</th><th>INTÉGRATION</th><th>DERNIER CONTACT</th><th>IDENTIFIANTS</th><th>STATUT</th><th class="text-center">ACTION</th></tr></thead>
<tbody id="deviceRows"><tr><td colspan="8" class="text-center py-5">Chargement...</td></tr></tbody>
</table></div>
</div>

<div class="stagia-list-card">
<div class="stagia-list-toolbar"><div><h5 class="mb-1">Identifiants des stagiaires</h5><small class="text-muted">Associez le matricule interne du terminal, badge ou QR au stagiaire.</small></div>
<button class="btn btn-sm btn-outline-primary" id="newCredential"><i class="bi bi-person-badge me-1"></i>Associer un identifiant</button></div>
<div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0">
<thead><tr><th>APPAREIL</th><th>STAGIAIRE</th><th>TYPE</th><th>RÉFÉRENCE</th><th>STATUT</th><th class="text-center">ACTION</th></tr></thead>
<tbody id="credentialRows"></tbody>
</table></div>
</div>
</main>

<div class="modal fade" id="deviceModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="deviceForm"><div class="modal-header"><h5 class="modal-title">Appareil de pointage</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>"><input type="hidden" name="id" id="deviceId">
<div class="row g-3">
<div class="col-md-5"><label class="form-label">Code *</label><input name="code" id="deviceCode" class="form-control" placeholder="BIO-URG-01" required></div>
<div class="col-md-7"><label class="form-label">Nom *</label><input name="nom" id="deviceName" class="form-control" required></div>
<div class="col-md-6"><label class="form-label">Type *</label><select name="type" id="deviceType" class="form-select" required>
<option value="BIOMETRIE">Biométrie</option><option value="QR">QR</option><option value="RFID_NFC">RFID / NFC</option><option value="MOBILE">Mobile</option><option value="GENERIC_API">API générique</option>
</select></div>
<div class="col-md-6"><label class="form-label">Mode d’intégration *</label><select name="integration_mode" id="deviceMode" class="form-select">
<option value="PUSH">PUSH vers STAGIA</option><option value="LOCAL_AGENT">Agent local</option><option value="SDK">SDK constructeur</option><option value="PULL">Collecte PULL</option>
</select></div>
<div class="col-12"><label class="form-label">Service / unité</label><select name="host_unit_id" id="deviceUnit" class="form-select"></select><small class="text-muted">Vide = tout l’établissement.</small></div>
<div class="col-md-6"><label class="form-label">Fabricant</label><input name="fabricant" id="deviceMaker" class="form-control" placeholder="Ex. ZKTeco"></div>
<div class="col-md-6"><label class="form-label">Modèle</label><input name="modele" id="deviceModel" class="form-control"></div>
<div class="col-12"><label class="form-label">Heure limite d’arrivée</label><input type="time" name="heure_limite_arrivee" id="deviceLate" class="form-control"><small class="text-muted">Optionnelle. Si renseignée, le retard peut être calculé automatiquement.</small></div>
</div>
<div class="alert alert-warning mt-3 d-none" id="apiKeyBox"><strong>Clé de l’appareil :</strong><div class="input-group mt-2"><input id="apiKeyValue" class="form-control" readonly><button type="button" class="btn btn-outline-secondary" id="copyKey">Copier</button></div><small>Copiez-la maintenant : seule son empreinte est enregistrée en base.</small></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Fermer</button><button class="btn btn-primary-stagia" id="saveDevice">Enregistrer</button></div>
</form></div></div></div>

<div class="modal fade" id="credentialModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="credentialForm"><div class="modal-header"><h5 class="modal-title">Associer un identifiant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
<div class="mb-3"><label class="form-label">Appareil *</label><select name="device_id" id="credentialDevice" class="form-select" required></select></div>
<div class="mb-3"><label class="form-label">Stagiaire *</label><select name="student_id" id="credentialStudent" class="form-select" required></select></div>
<div class="mb-3"><label class="form-label">Type *</label><select name="credential_type" class="form-select" required><option value="EMPREINTE">Empreinte</option><option value="VISAGE">Visage</option><option value="RFID">RFID</option><option value="NFC">NFC</option><option value="QR">QR</option><option value="MOBILE">Mobile</option><option value="AUTRE">Autre</option></select></div>
<div><label class="form-label">Référence externe *</label><input name="credential_ref" class="form-control" placeholder="Ex. 000123 ou UID badge" required></div>
</div>
<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary-stagia" id="saveCredential">Associer</button></div>
</form></div></div></div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?= BASE_URL ?>',CSRF='<?= $_SESSION['csrf'] ?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const dm=new bootstrap.Modal($('deviceModal')),cm=new bootstrap.Modal($('credentialModal'));
let data={devices:[],units:[],students:[],credentials:[]};

async function load(){try{
 const r=await STAGIA.request(BASE+'/actions/stages/attendance-device-list.php');data=r.data;render();
}catch(e){STAGIA.toast(e.message,'danger')}}
function render(){
 $('deviceRows').innerHTML=data.devices.length?data.devices.map(d=>`<tr>
 <td><strong>${esc(d.nom)}</strong><small class="d-block text-muted">${esc(d.code)}${d.fabricant?' · '+esc(d.fabricant):''}${d.modele?' '+esc(d.modele):''}</small></td>
 <td>${esc(d.type)}</td><td>${d.unit_name?esc(d.unit_name):'<span class="text-muted">Tout l’établissement</span>'}</td>
 <td>${esc(d.integration_mode)}</td><td>${esc(d.last_seen_at||'Jamais')}</td><td>${Number(d.credentials_count)||0}</td>
 <td>${Number(d.actif)?'<span class="badge bg-success">ACTIF</span>':'<span class="badge bg-secondary">INACTIF</span>'}</td>
 <td class="text-center"><button class="btn btn-sm btn-outline-secondary edit-device" data-id="${d.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
 <button class="btn btn-sm btn-outline-primary regen-key" data-id="${d.id}" title="Régénérer la clé API"><i class="bi bi-key"></i></button>
 <button class="btn btn-sm btn-outline-${Number(d.actif)?'danger':'success'} status-device" data-id="${d.id}" data-next="${Number(d.actif)?0:1}">${Number(d.actif)?'Désactiver':'Activer'}</button></td></tr>`).join(''):'<tr><td colspan="8" class="text-center py-5 text-muted">Aucun appareil configuré.</td></tr>';

 $('credentialRows').innerHTML=data.credentials.length?data.credentials.map(c=>{const d=data.devices.find(x=>Number(x.id)===Number(c.device_id));return `<tr>
 <td>${esc(d?.nom||'—')}</td><td><strong>${esc([c.nom,c.postnom,c.prenom].filter(Boolean).join(' '))}</strong><small class="d-block text-muted">${esc(c.stagia_code||'')}</small></td>
 <td>${esc(c.credential_type)}</td><td><code>${esc(c.credential_ref)}</code></td><td>${Number(c.actif)?'<span class="badge bg-success">ACTIF</span>':'<span class="badge bg-secondary">INACTIF</span>'}</td>
 <td class="text-center"><button class="btn btn-sm btn-outline-secondary credential-status" data-id="${c.id}" data-next="${Number(c.actif)?0:1}">${Number(c.actif)?'Désactiver':'Activer'}</button></td></tr>`}).join(''):'<tr><td colspan="6" class="text-center py-4 text-muted">Aucun identifiant associé.</td></tr>';
}
function unitOptions(selected=''){return '<option value="">Tout l’établissement</option>'+data.units.map(u=>`<option value="${u.id}" ${String(u.id)===String(selected)?'selected':''}>${esc(u.code)} — ${esc(u.nom)}</option>`).join('')}
$('newDevice').onclick=()=>{ $('deviceForm').reset();$('deviceId').value='';$('deviceUnit').innerHTML=unitOptions();$('apiKeyBox').classList.add('d-none');dm.show(); };
$('deviceRows').onclick=e=>{const b=e.target.closest('.edit-device,.status-device,.regen-key');if(!b)return;const d=data.devices.find(x=>Number(x.id)===Number(b.dataset.id));if(!d)return;
 if(b.classList.contains('status-device'))return setStatus(d.id,Number(b.dataset.next));
 if(b.classList.contains('regen-key'))return regenerateKey(d);
 $('deviceForm').reset();$('deviceId').value=d.id;$('deviceCode').value=d.code;$('deviceName').value=d.nom;$('deviceType').value=d.type;$('deviceMode').value=d.integration_mode;$('deviceUnit').innerHTML=unitOptions(d.host_unit_id);$('deviceMaker').value=d.fabricant||'';$('deviceModel').value=d.modele||'';$('deviceLate').value=(d.heure_limite_arrivee||'').substring(0,5);$('apiKeyBox').classList.add('d-none');dm.show();
};
$('deviceForm').onsubmit=async e=>{e.preventDefault();STAGIA.loading($('saveDevice'),true);try{
 const r=await STAGIA.post(BASE+'/actions/stages/attendance-device-store.php',new FormData(e.currentTarget));
 STAGIA.toast(r.message);if(r.data?.api_key){$('apiKeyValue').value=r.data.api_key;$('apiKeyBox').classList.remove('d-none');await load();}else{dm.hide();await load();}
}catch(e){STAGIA.toast(e.message,'danger')}finally{STAGIA.loading($('saveDevice'),false)}};
async function regenerateKey(d){
 if(!STAGIA.confirm(`Régénérer la clé API de « ${d.nom} » ? L’ancienne clé cessera immédiatement de fonctionner.`))return;
 const f=new FormData();f.append('csrf',CSRF);f.append('id',d.id);
 try{
   const r=await STAGIA.post(BASE+'/actions/stages/attendance-device-regenerate-key.php',f);
   $('deviceForm').reset();$('deviceId').value=d.id;$('deviceCode').value=d.code;$('deviceName').value=d.nom;
   $('deviceType').value=d.type;$('deviceMode').value=d.integration_mode;$('deviceUnit').innerHTML=unitOptions(d.host_unit_id);
   $('deviceMaker').value=d.fabricant||'';$('deviceModel').value=d.modele||'';$('deviceLate').value=(d.heure_limite_arrivee||'').substring(0,5);
   $('apiKeyValue').value=r.data.api_key;$('apiKeyBox').classList.remove('d-none');dm.show();
   STAGIA.toast(r.message,'success');
 }catch(e){STAGIA.toast(e.message,'danger')}
}
async function setStatus(id,actif){const f=new FormData();f.append('csrf',CSRF);f.append('id',id);f.append('actif',actif);try{const r=await STAGIA.post(BASE+'/actions/stages/attendance-device-status.php',f);STAGIA.toast(r.message);await load()}catch(e){STAGIA.toast(e.message,'danger')}}
$('copyKey').onclick=()=>{navigator.clipboard?.writeText($('apiKeyValue').value);STAGIA.toast('Clé copiée.')};

$('newCredential').onclick=()=>{if(!data.devices.some(d=>Number(d.actif))){STAGIA.toast('Créez d’abord un appareil actif.','warning');return}
 $('credentialForm').reset();$('credentialDevice').innerHTML=data.devices.filter(d=>Number(d.actif)).map(d=>`<option value="${d.id}">${esc(d.nom)} · ${esc(d.code)}</option>`).join('');
 $('credentialStudent').innerHTML=data.students.map(s=>`<option value="${s.id}">${esc([s.nom,s.postnom,s.prenom].filter(Boolean).join(' '))} · ${esc(s.stagia_code||'')}</option>`).join('');cm.show()};
$('credentialForm').onsubmit=async e=>{e.preventDefault();STAGIA.loading($('saveCredential'),true);try{const r=await STAGIA.post(BASE+'/actions/stages/attendance-device-credential-save.php',new FormData(e.currentTarget));cm.hide();STAGIA.toast(r.message);await load()}catch(e){STAGIA.toast(e.message,'danger')}finally{STAGIA.loading($('saveCredential'),false)}};
$('credentialRows').onclick=async e=>{const b=e.target.closest('.credential-status');if(!b)return;const f=new FormData();f.append('csrf',CSRF);f.append('id',b.dataset.id);f.append('actif',b.dataset.next);try{const r=await STAGIA.post(BASE+'/actions/stages/attendance-device-credential-status.php',f);STAGIA.toast(r.message);await load()}catch(e){STAGIA.toast(e.message,'danger')}};
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

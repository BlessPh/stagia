<?php
declare(strict_types=1);
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/admin-scope.php';
if(!hasRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL'])&&!estResponsableMinisteriel($pdo)){http_response_code(403);exit('Accès refusé.');}
$pageTitle='Responsables institutionnels';$activePage='admin-responsables';
require_once __DIR__.'/../../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Responsables institutionnels</h1><p>Annuaire limité aux organisations placées dans votre périmètre administratif.</p></div></div>
<div class="alert alert-light border"><i class="bi bi-shield-lock me-1 text-warning"></i>Les étudiants et les organisations non rattachées à votre ministère ne sont jamais exposés dans cet annuaire.</div>
<div class="stagia-list-card">
<div class="stagia-list-toolbar"><div class="stagia-list-filters w-100">
<div class="input-group stagia-table-search flex-grow-1"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span><input id="q" class="form-control border-start-0" placeholder="Nom, rôle, organisation..."></div>
<select id="organisation" class="form-select"><option value="">Toutes les organisations autorisées</option></select>
</div></div>
<div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>RESPONSABLE</th><th>RÔLES</th><th>ORGANISATION</th><th>TYPE</th><th>PROVINCE</th><th>CONTACT</th></tr></thead><tbody id="body"><tr><td colspan="6" class="text-center py-5">Chargement…</td></tr></tbody></table></div>
<div class="stagia-list-footer"><span id="count">0 responsable</span></div></div>
</main>
<script>document.addEventListener('DOMContentLoaded',()=>{const B='<?=BASE_URL?>',E=STAGIA.escape,$=id=>document.getElementById(id);let initial=new URLSearchParams(location.search).get('etablissement_id')||'',timer,init=false;async function load(){const p=new URLSearchParams({q:$('q').value.trim(),etablissement_id:$('organisation').value||initial});try{const r=await STAGIA.request(B+'/actions/admin/responsables/list.php?'+p),d=r.data;if(!init){$('organisation').innerHTML='<option value="">Toutes les organisations autorisées</option>'+d.organisations.map(x=>`<option value="${x.id}">${E(x.nom)}</option>`).join('');if(d.organisations.some(x=>String(x.id)===initial))$('organisation').value=initial;init=true;}$('count').textContent=d.items.length+' responsable(s)';$('body').innerHTML=d.items.length?d.items.map(x=>`<tr><td><strong>${E([x.prenom,x.nom,x.postnom].filter(Boolean).join(' '))}</strong></td><td>${E(x.roles||'—')}</td><td><strong>${E(x.etablissement_nom)}</strong></td><td><span class="badge bg-light text-dark border">${E(x.type_etablissement)}</span></td><td>${E(x.province||'—')}</td><td><small>${E(x.email||'—')}<br>${E(x.telephone||'')}</small></td></tr>`).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucun responsable dans ce périmètre.</td></tr>';}catch(e){STAGIA.toast(e.message,'danger');}}$('q').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,250)};$('organisation').onchange=()=>{initial=$('organisation').value;history.replaceState(null,'',location.pathname+(initial?'?etablissement_id='+initial:''));load()};load();});</script>
<?php require_once __DIR__.'/../../../includes/app-footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/admin-scope.php';
exigerResponsableMinisteriel($pdo);
$pageTitle='Organisations supervisées';$activePage='ministere-organisations';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Organisations supervisées</h1><p>Universités, hôpitaux et structures officiellement rattachés à votre ministère.</p></div><a class="btn btn-outline-dark" href="<?=BASE_URL?>/views/national/dashboard.php"><i class="bi bi-graph-up me-1"></i>Statistiques</a></div>
<div class="alert alert-light border"><i class="bi bi-shield-check text-warning me-1"></i>Cette liste est calculée côté serveur à partir des rattachements validés par l’administration nationale.</div>
<div class="stagia-list-card"><div class="stagia-list-toolbar"><div class="stagia-list-filters w-100"><div class="input-group stagia-table-search flex-grow-1"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span><input id="q" class="form-control border-start-0" placeholder="Nom, code, ville ou e-mail…"></div><select id="type" class="form-select"><option value="">Tous les types</option></select><select id="province" class="form-select"><option value="">Toutes les provinces</option></select><button id="reset" class="btn btn-light border"><i class="bi bi-arrow-clockwise"></i></button></div></div>
<div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>ORGANISATION</th><th>TYPE</th><th>LOCALISATION</th><th>RESPONSABLES</th><th>ÉTUDIANTS</th><th>CONTACT</th><th></th></tr></thead><tbody id="body"><tr><td colspan="7" class="text-center py-5">Chargement…</td></tr></tbody></table></div><div class="stagia-list-footer"><span id="count">0 organisation</span></div></div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{const B='<?=BASE_URL?>',E=STAGIA.escape,$=id=>document.getElementById(id);let timer,init=false;
async function load(){const p=new URLSearchParams({q:$('q').value.trim(),type:$('type').value,province:$('province').value});try{const r=await STAGIA.request(B+'/actions/ministere/organisations.php?'+p),d=r.data;if(!init){$('type').innerHTML='<option value="">Tous les types</option>'+d.types.map(x=>`<option value="${E(x.code)}">${E(x.label)}</option>`).join('');$('province').innerHTML='<option value="">Toutes les provinces</option>'+d.provinces.map(x=>`<option value="${E(x.province)}">${E(x.province)}</option>`).join('');init=true;}$('count').textContent=d.items.length+' organisation(s)';$('body').innerHTML=d.items.length?d.items.map(x=>`<tr><td><strong>${E(x.nom)}</strong><small class="d-block text-muted">${E(x.code||'')}</small></td><td><span class="badge bg-light text-dark border">${E(x.type_libelle)}</span></td><td>${E([x.ville,x.province].filter(Boolean).join(', ')||'—')}</td><td><strong>${Number(x.responsables)||0}</strong></td><td><strong>${Number(x.etudiants)||0}</strong></td><td><small>${E(x.email||'—')}<br>${E(x.telephone||'')}</small></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" title="Responsables" href="${B}/views/admin/responsables/index.php?etablissement_id=${x.id}"><i class="bi bi-people"></i></a> <a class="btn btn-sm btn-outline-primary" title="Statistiques" href="${B}/views/national/dashboard.php?etablissement_id=${x.id}"><i class="bi bi-graph-up"></i></a></td></tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune organisation dans ce périmètre.</td></tr>';}catch(e){STAGIA.toast(e.message,'danger')}}
$('q').oninput=()=>{clearTimeout(timer);timer=setTimeout(load,250)};$('type').onchange=load;$('province').onchange=load;$('reset').onclick=()=>{$('q').value='';$('type').value='';$('province').value='';load()};load();});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

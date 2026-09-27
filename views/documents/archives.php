<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Archives documents';
$activePage='stage-documents-archives';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.archive-card{background:#fff;border:1px solid #e5eaf0;border-radius:16px;overflow:hidden}.archive-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.archive-kpi{background:#fff;border:1px solid #e5eaf0;border-radius:14px;padding:16px}.archive-kpi small{display:block;color:#64748b;font-size:10px;text-transform:uppercase}.archive-kpi strong{font-size:28px}.doc-type{width:42px;height:42px;border-radius:12px;background:#fff3e8;color:#f97316;display:flex;align-items:center;justify-content:center;font-size:20px}.archive-muted{font-size:12px;color:#64748b}.archive-actions .btn{min-width:34px}.status-chip{font-weight:800;letter-spacing:.2px}.status-valide{background:#dcfce7;color:#15803d}.status-archive{background:#e2e8f0;color:#334155}.status-annule{background:#fee2e2;color:#b91c1c}@media(max-width:900px){.archive-kpis{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.archive-kpis{grid-template-columns:1fr}.archive-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:4px}.archive-actions .btn{border-radius:8px!important}}
</style>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1>Archives documents</h1><p>Gérez les documents officiels générés : vérification QR, impression, archivage et annulation.</p></div></div>

<div class="archive-kpis mb-4">
    <div class="archive-kpi"><small>Total</small><strong id="kTotal">0</strong></div>
    <div class="archive-kpi"><small>Valides</small><strong id="kValid">0</strong></div>
    <div class="archive-kpi"><small>Archivés</small><strong id="kArchived">0</strong></div>
    <div class="archive-kpi"><small>Annulés</small><strong id="kCancelled">0</strong></div>
</div>

<div class="archive-card">
    <div class="p-3 border-bottom">
        <div class="row g-2">
            <div class="col-lg-6"><input id="search" class="form-control" placeholder="Rechercher étudiant, référence, session, hôpital..."></div>
            <div class="col-lg-3"><select id="typeFilter" class="form-select"><option value="">Tous les documents</option><option value="ATTESTATION_STAGE">Attestations</option><option value="CERTIFICAT_STAGE">Certificats</option><option value="CONVENTION_STAGE">Conventions</option><option value="FICHE_PRESENCE">Fiches de présence</option><option value="FICHE_APPRECIATION">Fiches d'appréciation</option><option value="RECOMMANDATION_STAGE">Lettres de recommandation</option><option value="RAPPORT_FINAL">Rapports finaux</option></select></div>
            <div class="col-lg-3"><select id="statusFilter" class="form-select"><option value="">Tous les statuts</option><option value="VALIDE">Valides</option><option value="ARCHIVÉ">Archivés</option><option value="ANNULÉ">Annulés</option></select></div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>DOCUMENT</th><th>ÉTUDIANT / SESSION</th><th>ÉTABLISSEMENT</th><th>DATE</th><th>STATUT</th><th class="text-end">ACTIONS</th></tr></thead>
            <tbody id="rows"><tr><td colspan="6" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
        </table>
    </div>
</div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?=BASE_URL?>',CSRF='<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>',$=id=>document.getElementById(id),esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
let items=[];
function date(v){if(!v)return '—';let d=String(v).slice(0,10).split('-');return d.length===3?`${d[2]}/${d[1]}/${d[0]}`:v;}
function badge(s){let v=String(s||'').toUpperCase(),c=v==='ANNULÉ'?'status-annule':(v==='ARCHIVÉ'?'status-archive':'status-valide');return `<span class="badge status-chip ${c}">${esc(s||'—')}</span>`;}
function icon(t){return t==='ATTESTATION_STAGE'?'bi-patch-check':(t==='CONVENTION_STAGE'?'bi-file-earmark-sign':(t==='CERTIFICAT_STAGE'?'bi-award':'bi-file-earmark-text'));}
async function load(){
 try{
   const r=await fetch(BASE+'/actions/documents/document-archive-list.php',{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
   const raw=(await r.text()).replace(/^\uFEFF/,'').trim();
   let j;try{j=JSON.parse(raw);}catch(e){throw new Error('Réponse serveur invalide : '+raw.substring(0,500));}
   if(!r.ok||j.success!==true)throw new Error(j.message||'Erreur serveur');
   items=j.data.items||[];let k=j.data.kpi||{};$('kTotal').textContent=k.total||0;$('kValid').textContent=k.valides||0;$('kArchived').textContent=k.archives||0;$('kCancelled').textContent=k.annules||0;render();
 }catch(e){$('rows').innerHTML=`<tr><td colspan="6" class="text-center py-5 text-danger">${esc(e.message)}</td></tr>`;}
}
function actions(x){
 let html=[];
 if(x.verify_url)html.push(`<a class="btn btn-outline-success" target="_blank" title="Vérifier QR" href="${esc(x.verify_url)}"><i class="bi bi-qr-code"></i></a>`);
 if(x.url)html.push(`<a class="btn btn-outline-primary" target="_blank" title="Voir / imprimer" href="${esc(x.url)}"><i class="bi bi-eye"></i></a>`);
 if(x.download_url)html.push(`<a class="btn btn-outline-secondary" title="Télécharger" href="${esc(x.download_url)}"><i class="bi bi-download"></i></a>`);
 if(x.can_archive)html.push(`<button class="btn btn-outline-secondary doc-status-action" title="Archiver" data-key="${esc(x.key)}" data-action="archive"><i class="bi bi-archive"></i></button>`);
 if(x.can_cancel)html.push(`<button class="btn btn-outline-danger doc-status-action" title="Annuler" data-key="${esc(x.key)}" data-action="cancel"><i class="bi bi-x-circle"></i></button>`);
 if(x.can_reactivate)html.push(`<button class="btn btn-outline-success doc-status-action" title="Réactiver" data-key="${esc(x.key)}" data-action="reactivate"><i class="bi bi-arrow-clockwise"></i></button>`);
 return `<div class="btn-group btn-group-sm archive-actions">${html.join('')}</div>`;
}
function render(){
 const q=$('search').value.trim().toLowerCase(),t=$('typeFilter').value,st=$('statusFilter').value;
 const list=items.filter(x=>(!t||x.type===t)&&(!st||x.status===st)&&(!q||[x.reference,x.title,x.student_name,x.student_code,x.campaign,x.establishment,x.status,x.status_raw,x.type_label].filter(Boolean).join(' ').toLowerCase().includes(q)));
 $('rows').innerHTML=list.length?list.map(x=>`<tr>
 <td><div class="d-flex align-items-center gap-2"><div class="doc-type"><i class="bi ${icon(x.type)}"></i></div><div><strong>${esc(x.type_label||'Document')}</strong><div class="archive-muted">${esc(x.reference||'—')}</div><div class="archive-muted">${esc(x.title||'')}</div></div></div></td>
 <td><strong>${esc(x.student_name||'—')}</strong><div class="archive-muted">${esc(x.student_code||'')}</div><div class="archive-muted">${esc(x.campaign||'')}</div></td>
 <td>${esc(x.establishment||'—')}</td><td>${date(x.date)}</td><td>${badge(x.status)}</td>
 <td class="text-end">${actions(x)}</td>
 </tr>`).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucun document trouvé.</td></tr>';
}
async function changeStatus(key,action,btn){
 const labels={archive:'archiver',cancel:'annuler',reactivate:'réactiver'};
 if(action==='cancel'&&!confirm('Confirmer l’annulation de ce document ? Le QR indiquera que le document n’est plus valide.'))return;
 try{
   btn.disabled=true;
   const f=new FormData();f.append('csrf',CSRF);f.append('key',key);f.append('action',action);
   const r=await fetch(BASE+'/actions/documents/document-status-update.php',{method:'POST',body:f,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
   const raw=(await r.text()).replace(/^\uFEFF/,'').trim();let j;try{j=JSON.parse(raw);}catch(e){throw new Error(raw.substring(0,500));}
   if(!r.ok||j.success!==true)throw new Error(j.message||'Erreur serveur');
   if(window.STAGIA?.toast)STAGIA.toast(j.message||'Statut mis à jour.','success');
   await load();
 }catch(e){if(window.STAGIA?.toast)STAGIA.toast(e.message,'danger');else alert(e.message);}finally{btn.disabled=false;}
}
document.addEventListener('click',e=>{const b=e.target.closest('.doc-status-action');if(!b)return;changeStatus(b.dataset.key,b.dataset.action,b);});
$('search').oninput=render;$('typeFilter').onchange=render;$('statusFilter').onchange=render;load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

$role=strtoupper(trim((string)($_SESSION['role_code']??'')));
if(!in_array($role,['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE'],true)){http_response_code(403);exit('Accès refusé.');}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Délibérations finales';
$activePage='hospital-final-deliberations';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.fd-state{padding:45px 20px;text-align:center;color:#64748b}.fd-status{font-size:10px;font-weight:800;border-radius:999px;padding:5px 9px;display:inline-flex;gap:5px;align-items:center}.fd-ready{background:#fff1e7;color:#c2410c}.fd-wait{background:#f1f5f9;color:#64748b}.fd-draft{background:#f3e8ff;color:#7e22ce}.fd-valid{background:#dcfce7;color:#15803d}.fd-avatar{width:38px;height:38px;border-radius:11px;background:#fff1e7;color:#f97316;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}.fd-progress{height:6px;background:#edf2f7;border-radius:999px;overflow:hidden}.fd-progress span{display:block;height:100%;background:#f97316}.fd-action{border-radius:9px;font-size:11px;font-weight:800}
</style>

<main class="dashboard-content">
    <div class="stagia-page-head">
        <div>
            <h1>Délibérations finales</h1>
            <p>Consolidation hospitalière après validation de tous les mini-jurys du stagiaire.</p>
        </div>
    </div>

    <div class="stagia-kpi-grid">
        <div class="stagia-kpi-card"><div><span>PRÊTS</span><strong id="kReady">0</strong><small>Tous les mini-jurys validés</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-hourglass-split"></i></div></div>
        <div class="stagia-kpi-card"><div><span>BROUILLONS</span><strong id="kDraft">0</strong><small>En préparation</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-pencil-square"></i></div></div>
        <div class="stagia-kpi-card"><div><span>VALIDÉES</span><strong id="kValid">0</strong><small>Résultats finalisés</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-patch-check"></i></div></div>
        <div class="stagia-kpi-card"><div><span>EN ATTENTE</span><strong id="kWait">0</strong><small>Mini-jurys incomplets</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-clock-history"></i></div></div>
    </div>

    <div class="stagia-list-card">
        <div class="stagia-list-toolbar">
            <div><h5 class="mb-1">Stagiaires à délibérer</h5><small class="text-muted">La délibération s’ouvre uniquement après tous les mini-jurys.</small></div>
            <input id="search" class="form-control" style="max-width:340px" placeholder="Rechercher stagiaire, université...">
        </div>
        <div class="table-responsive">
            <table class="table stagia-modern-table align-middle mb-0">
                <thead><tr><th>Stagiaire</th><th>Départements</th><th>Mini-jurys</th><th>Moyenne jurys</th><th>Délibération</th><th class="text-end">Action</th></tr></thead>
                <tbody id="rows"><tr><td colspan="6"><div class="fd-state">Chargement...</div></td></tr></tbody>
            </table>
        </div>
        <div class="p-3 border-top d-flex justify-content-between"><small id="info" class="text-muted"></small><div id="pages" class="d-flex gap-1"></div></div>
    </div>
</main>

<div class="modal fade" id="fdModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><div><h5 class="modal-title">Délibération finale hospitalière</h5><small id="mStudent" class="text-muted"></small></div><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label">Départements requis</label><input id="mTotal" class="form-control" disabled></div>
            <div class="col-md-4"><label class="form-label">Mini-jurys validés</label><input id="mValid" class="form-control" disabled></div>
            <div class="col-md-4"><label class="form-label">Moyenne des mini-jurys</label><input id="mAvg" class="form-control fw-bold" disabled></div>
        </div>
        <div id="notReady" class="alert alert-warning d-none">Tous les mini-jurys requis doivent être validés avant la délibération finale.</div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Date de délibération</label><input id="mDate" type="date" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Note finale / 100</label><input id="mScore" type="number" min="0" max="100" step="0.01" class="form-control" placeholder="Saisir la note"></div>
            <div class="col-md-4"><label class="form-label">Décision finale</label><select id="mDecision" class="form-select"><option value="">Choisir...</option><option value="VALIDE">Validé</option><option value="A_REPRENDRE">À reprendre</option><option value="AJOURNE">Ajourné</option></select></div>
        </div>
        <div class="mt-3"><label class="form-label">Appréciation / observations</label><textarea id="mApp" class="form-control" rows="4" placeholder="Observations de la délibération..."></textarea></div>
        <div class="mt-3" id="mStatus"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal">Fermer</button><button id="draftBtn" class="btn btn-outline-secondary d-none">Enregistrer brouillon</button><button id="validBtn" class="btn btn-success d-none"><i class="bi bi-check2-circle me-1"></i>Valider la délibération</button></div>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE='<?= BASE_URL ?>',CSRF='<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('fdModal'));
let all=[],view=[],current=null,page=1,timer=null; const PER=15;

const badge=x=>x.deliberation_status==='VALIDEE'?'<span class="fd-status fd-valid"><i class="bi bi-check2-circle"></i>VALIDÉE</span>':x.deliberation_status==='BROUILLON'?'<span class="fd-status fd-draft"><i class="bi bi-pencil"></i>BROUILLON</span>':Number(x.ready)===1?'<span class="fd-status fd-ready"><i class="bi bi-hourglass-split"></i>PRÊT</span>':'<span class="fd-status fd-wait"><i class="bi bi-clock"></i>EN ATTENTE</span>';
const initials=n=>String(n||'').split(/\s+/).filter(Boolean).slice(0,2).map(x=>x[0]).join('').toUpperCase();

async function load(){
    try{
        const src=BASE+'/actions/espace-hopital/groupes-rotations-list.php';
        const first=await STAGIA.request(src),contexts=first.data.contexts||[],keys=[],seen=new Set();
        contexts.forEach(c=>{const k=c.campaign_id+'-'+c.promotion_id;if(!seen.has(k)){seen.add(k);keys.push(c);}});
        const sources=keys.length?await Promise.all(keys.map(k=>STAGIA.request(src+'?campaign_id='+k.campaign_id+'&promotion_id='+k.promotion_id))):[first];
        const jm=await STAGIA.request(BASE+'/actions/stages/host-mini-jury-meta.php');
        const fm=await STAGIA.request(BASE+'/actions/stages/host-final-deliberation-meta.php');
        all=build(sources.map(x=>x.data||{}),jm.data||{},fm.data||{}); kpis(); filter(1);
    }catch(e){$('rows').innerHTML='<tr><td colspan="6"><div class="fd-state text-danger">'+esc(e.message)+'</div></td></tr>';}
}

function build(sources,jm,fm){
    const jury=new Map((jm.juries||[]).map(j=>[Number(j.assignment_id)+'-'+Number(j.department_unit_id),j]));
    const delib=new Map((fm.deliberations||[]).map(d=>[Number(d.assignment_id),d]));
    const map=new Map();
    sources.forEach(s=>{
        const units=new Map((s.units||[]).map(u=>[Number(u.id),u])),groups=new Map((s.groups||[]).map(g=>[Number(g.id),g]));
        (s.students||[]).forEach(st=>{
            const aid=Number(st.assignment_id||0),gid=Number(st.group_id||0),g=groups.get(gid); if(!aid||!g)return;
            if(!map.has(aid))map.set(aid,{assignment_id:aid,student_name:[st.nom,st.postnom,st.prenom].filter(Boolean).join(' ')||'Stagiaire',stagia_code:st.stagia_code||'',university_name:(s.contexts||[]).find(c=>Number(c.campaign_id)===Number(s.selected?.campaign_id))?.university_name||'',deps:new Map()});
            const item=map.get(aid);
            (g.rotations||[]).forEach(p=>{const u=units.get(Number(p.host_unit_id||0))||{},parent=u.parent_id?units.get(Number(u.parent_id)):null,did=parent?Number(parent.id):Number(p.host_unit_id||0);if(did&&!item.deps.has(did))item.deps.set(did,{id:did,name:parent?.nom||p.parent_name||u.nom||p.unit_name||'Département'});});
        });
    });
    return [...map.values()].map(x=>{
        const deps=[...x.deps.values()],valid=[],scores=[];
        deps.forEach(d=>{const j=jury.get(x.assignment_id+'-'+d.id);if(j&&j.statut==='VALIDEE'){valid.push(j);if(j.note_finale!==null&&j.note_finale!==undefined)scores.push(Number(j.note_finale));}});
        const f=delib.get(x.assignment_id)||{};
        return {...x,required_departments:deps,mini_juries_total:deps.length,mini_juries_valides:valid.length,jury_average:scores.length?scores.reduce((a,b)=>a+b,0)/scores.length:null,ready:deps.length&&valid.length===deps.length?1:0,deliberation_id:f.id||null,deliberation_status:f.statut||null,final_score:f.note_finale!==null&&f.note_finale!==undefined?Number(f.note_finale):null,decision:f.decision||null,appreciation:f.appreciation||null,deliberation_date:f.date_deliberation||null,can_manage:Number(fm.can_manage||0)};
    });
}

function kpis(){let s={r:0,d:0,v:0,w:0};all.forEach(x=>x.deliberation_status==='VALIDEE'?s.v++:x.deliberation_status==='BROUILLON'?s.d++:Number(x.ready)===1?s.r++:s.w++);$('kReady').textContent=s.r;$('kDraft').textContent=s.d;$('kValid').textContent=s.v;$('kWait').textContent=s.w;}

function filter(p=1){const q=$('search').value.trim().toLowerCase();view=all.filter(x=>!q||[x.student_name,x.stagia_code,x.university_name,...x.required_departments.map(d=>d.name)].join(' ').toLowerCase().includes(q));const max=Math.max(1,Math.ceil(view.length/PER));page=Math.max(1,Math.min(p,max));render(view.slice((page-1)*PER,page*PER),max);}

function render(rows,max){
    if(!rows.length){$('rows').innerHTML='<tr><td colspan="6"><div class="fd-state">Aucun stagiaire à délibérer.</div></td></tr>';}else $('rows').innerHTML=rows.map(x=>{const pct=x.mini_juries_total?Math.round(x.mini_juries_valides*100/x.mini_juries_total):0,validated=x.deliberation_status==='VALIDEE',existing=Number(x.deliberation_id||0)>0;let btn=existing?`<button class="btn fd-action ${validated?'btn-outline-success':'btn-primary-stagia'} open" data-id="${x.assignment_id}">${validated?'Voir':'Continuer'}</button>`:Number(x.ready)===1&&Number(x.can_manage)===1?`<button class="btn fd-action btn-primary-stagia open" data-id="${x.assignment_id}">Délibérer</button>`:'<button class="btn fd-action btn-light border" disabled>En attente</button>';
    return `<tr><td><div class="d-flex gap-2 align-items-center"><div class="fd-avatar">${esc(initials(x.student_name))}</div><div><strong>${esc(x.student_name)}</strong><small class="d-block text-muted">${esc(x.stagia_code)} • ${esc(x.university_name||'-')}</small></div></div></td><td><strong>${x.mini_juries_total}</strong><small class="d-block text-muted">${x.required_departments.map(d=>esc(d.name)).join(' • ')}</small></td><td><div class="d-flex justify-content-between"><span>${x.mini_juries_valides}/${x.mini_juries_total}</span></div><div class="fd-progress"><span style="width:${pct}%"></span></div></td><td><strong>${x.jury_average!==null?Number(x.jury_average).toFixed(2)+' %':'-'}</strong><small class="d-block text-muted">Référence uniquement</small></td><td>${badge(x)}</td><td class="text-end">${btn}</td></tr>`;}).join('');
    $('info').textContent=view.length?`${view.length} stagiaire(s) • page ${page}/${max}`:'0 stagiaire';
    $('pages').innerHTML=`<button class="btn btn-sm btn-outline-secondary pg" data-p="${page-1}" ${page<=1?'disabled':''}>Précédent</button><button class="btn btn-sm btn-outline-secondary pg" data-p="${page+1}" ${page>=max?'disabled':''}>Suivant</button>`;
}

$('pages').onclick=e=>{const b=e.target.closest('.pg');if(b&&!b.disabled)filter(Number(b.dataset.p));};
$('search').oninput=()=>{clearTimeout(timer);timer=setTimeout(()=>filter(1),250);};

$('rows').onclick=e=>{
    const b=e.target.closest('.open');if(!b)return;current=all.find(x=>Number(x.assignment_id)===Number(b.dataset.id));if(!current)return;
    $('mStudent').textContent=current.student_name+' • '+(current.university_name||'-');$('mTotal').value=current.mini_juries_total;$('mValid').value=current.mini_juries_valides+'/'+current.mini_juries_total;$('mAvg').value=current.jury_average!==null?Number(current.jury_average).toFixed(2)+' %':'-';
    $('mDate').value=current.deliberation_date||new Date().toISOString().slice(0,10);$('mScore').value=current.final_score!==null?current.final_score:'';$('mDecision').value=current.decision||'';$('mApp').value=current.appreciation||'';
    const ready=Number(current.ready)===1,validated=current.deliberation_status==='VALIDEE',editable=ready&&Number(current.can_manage)===1&&!validated;
    $('notReady').classList.toggle('d-none',ready);$('mStatus').innerHTML=badge(current);['mDate','mScore','mDecision','mApp'].forEach(id=>$(id).disabled=!editable);$('draftBtn').classList.toggle('d-none',!editable);$('validBtn').classList.toggle('d-none',!editable);modal.show();
};

async function save(action){
    if(!current)return;
    if(action==='VALIDATE'){if(!$('mScore').value)return STAGIA.toast('Saisissez la note finale.','danger');if(!$('mDecision').value)return STAGIA.toast('Choisissez la décision finale.','danger');if(!$('mDate').value)return STAGIA.toast('Choisissez la date.','danger');if(!confirm('Valider définitivement cette délibération ?'))return;}
    const fd=new FormData();fd.append('csrf',CSRF);fd.append('assignment_id',current.assignment_id);fd.append('action',action);fd.append('date_deliberation',$('mDate').value);fd.append('final_score',$('mScore').value);fd.append('decision',$('mDecision').value);fd.append('appreciation',$('mApp').value.trim());
    try{const r=await STAGIA.post(BASE+'/actions/stages/host-final-deliberation-save.php',fd);modal.hide();STAGIA.toast(r.message);await load();}catch(e){STAGIA.toast(e.message,'danger');}
}
$('draftBtn').onclick=()=>save('SAVE');$('validBtn').onclick=()=>save('VALIDATE');load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>



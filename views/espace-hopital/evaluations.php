<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole([
    'ADMIN_ACCUEIL','CHEF_SERVICE','ENCADREUR','ENCADREUR_CLINIQUE','EVALUATEUR_CLINIQUE',
    'MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_STAGE_CLINIQUE','MAITRE_DE_STAGE_CLINIQUE'
]);

/* Jeton CSRF nécessaire aussi pour le Chef de service (Valider / Retourner). */
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Évaluations';
$activePage='hopital-evaluations';
require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
.eval-card{border:1px solid #e1e7ee;border-radius:13px;margin:16px;background:#fff;overflow:hidden}
.eval-head{padding:16px 18px;border-bottom:1px solid #edf0f3;display:flex;justify-content:space-between;gap:15px;align-items:flex-start}
.eval-body{padding:18px}.eval-meta{font-size:.875rem;color:#6c757d}.eval-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.comp-row{border:1px solid #edf0f3;border-radius:10px;padding:12px;margin-bottom:10px}.score-input{max-width:110px}
.eval-note{font-size:1.5rem;font-weight:700}.eval-text{background:#f8fafc;border-radius:9px;padding:10px;min-height:42px}
@media(max-width:767px){.eval-head{flex-direction:column}.eval-actions{justify-content:flex-start}.score-input{max-width:none}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Évaluations</h1>
        <p>Évaluez les stagiaires affectés à vos rotations et traitez les évaluations selon votre rôle.</p>
    </div>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card"><div><span>ROTATIONS</span><strong id="statRotations">0</strong><small>Rotations visibles</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-arrow-repeat"></i></div></div>
    <div class="stagia-kpi-card"><div><span>BROUILLONS</span><strong id="statDrafts">0</strong><small>À compléter</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-pencil-square"></i></div></div>
    <div class="stagia-kpi-card"><div><span>SOUMISES</span><strong id="statSubmitted">0</strong><small>En attente</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-send-check"></i></div></div>
    <div class="stagia-kpi-card"><div><span>VALIDÉES</span><strong id="statValidated">0</strong><small>Validées</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-check2-circle"></i></div></div>
    <div class="stagia-kpi-card"><div><span>FINALISÉES</span><strong id="statFinalized">0</strong><small>Finalisées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-patch-check"></i></div></div>
</div>

<div class="stagia-list-card">
    <div class="stagia-list-toolbar">
        <div><h5 class="mb-1">Évaluations des stagiaires</h5><small class="text-muted">Seules les rotations autorisées dans votre périmètre sont affichées.</small></div>
        <div style="max-width:320px;width:100%"><input type="search" id="search" class="form-control" placeholder="Rechercher..."></div>
    </div>
    <div id="evaluationContainer"><div class="text-center py-5">Chargement...</div></div>
</div>
</main>

<div class="modal fade" id="evaluationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div><h5 class="modal-title" id="modalTitle">Évaluation</h5><small class="text-muted" id="modalSubtitle"></small></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Type d'évaluation</label>
            <select id="typeEvaluation" class="form-select">
              <option value="CONTINUE">Évaluation continue</option>
              <option value="MI_ROTATION">Évaluation mi-rotation</option>
              <option value="FIN_ROTATION">Évaluation de fin de rotation</option>
            </select>
          </div>
        </div>
        <h6 class="mb-2">Compétences</h6>
        <div id="competencyContainer"></div>
        <div class="row g-3 mt-1">
          <div class="col-12"><label class="form-label">Appréciation générale</label><textarea id="appreciation" class="form-control" rows="3"></textarea></div>
          <div class="col-md-6"><label class="form-label">Points forts</label><textarea id="pointsForts" class="form-control" rows="3"></textarea></div>
          <div class="col-md-6"><label class="form-label">Axes d'amélioration</label><textarea id="axesAmelioration" class="form-control" rows="3"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Fermer</button>
        <button type="button" class="btn btn-outline-primary" id="btnDraft">Enregistrer brouillon</button>
        <button type="button" class="btn btn-primary" id="btnSubmit">Soumettre</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>', CSRF='<?= htmlspecialchars($_SESSION['csrf']??'',ENT_QUOTES) ?>', $=id=>document.getElementById(id);
let items=[], current=null, currentEvaluation=null;
const modal=new bootstrap.Modal($('evaluationModal'));

async function load(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/host-evaluation-list.php');
        items=r.data.items||[]; const s=r.data.stats||{};
        $('statRotations').textContent=s.rotations||0;
        $('statDrafts').textContent=s.brouillons||0;
        $('statSubmitted').textContent=s.soumises||0;
        $('statValidated').textContent=s.validees||0;
        $('statFinalized').textContent=s.finalisees||0;
        renderFiltered();
    }catch(e){ $('evaluationContainer').innerHTML=`<div class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</div>`; }
}

function renderFiltered(){
    const q=$('search').value.trim().toLowerCase();
    render(items.filter(x=>!q||[
        x.nom,x.postnom,x.prenom,x.stagia_code,x.unit_name,x.parent_name,x.university_name,x.campaign_title,x.campaign_code
    ].filter(Boolean).join(' ').toLowerCase().includes(q)));
}

function render(list){
    if(!list.length){ $('evaluationContainer').innerHTML='<div class="text-center py-5 text-muted"><i class="bi bi-clipboard-check fs-2 d-block mb-2"></i>Aucune évaluation à traiter pour le moment.</div>'; return; }
    $('evaluationContainer').innerHTML=list.map(x=>{
        const evs=x.evaluations||[];
        return `<div class="eval-card">
            <div class="eval-head">
                <div>
                    <h5 class="mb-1">${STAGIA.escape([x.nom,x.postnom,x.prenom].filter(Boolean).join(' '))}</h5>
                    <div class="eval-meta">${STAGIA.escape(x.stagia_code||'-')} • ${STAGIA.escape(x.unit_name||'-')} • Rotation ${Number(x.sequence_no||0)}</div>
                    <div class="eval-meta mt-1">${STAGIA.escape(x.university_name||'-')} • ${STAGIA.escape(x.campaign_title||x.campaign_code||'-')} • ${dateFr(x.date_debut)} au ${dateFr(x.date_fin)}</div>
                    <div class="eval-meta mt-1">Journaux validés : ${Number(x.validated_logbooks||0)} • Présences : ${Number(x.attendance_count||0)}</div>
                </div>
                <div class="eval-actions">
                    ${x.can_create?`<button class="btn btn-primary btn-sm" onclick="openEvaluation(${x.rotation_id},0)"><i class="bi bi-plus-circle me-1"></i>Évaluer</button>`:''}
                </div>
            </div>
            <div class="eval-body">
                ${evs.length?evs.map(e=>evaluationBlock(x,e)).join(''):'<div class="text-muted">Aucune évaluation enregistrée.</div>'}
            </div>
        </div>`;
    }).join('');
}

function evaluationBlock(x,e){
    const buttons=[];
    if(e.can_edit) buttons.push(`<button class="btn btn-outline-primary btn-sm" onclick="openEvaluation(${x.rotation_id},${e.id})">Modifier</button>`);
    if(e.can_validate){
        buttons.push(`<button class="btn btn-success btn-sm" onclick="review(${e.id},'VALIDATE')">Valider</button>`);
        buttons.push(`<button class="btn btn-outline-danger btn-sm" onclick="review(${e.id},'RETURN')">Retourner</button>`);
    }
    if(e.can_finalize) buttons.push(`<button class="btn btn-dark btn-sm" onclick="review(${e.id},'FINALIZE')">Finaliser</button>`);
    return `<div class="border rounded-3 p-3 mb-3">
        <div class="d-flex justify-content-between gap-3 flex-wrap">
            <div>
                <strong>${typeLabel(e.type_evaluation)}</strong>
                <div class="eval-meta">Évaluateur : ${STAGIA.escape(e.evaluator_name||'-')} • Statut : ${statusLabel(e.statut)}</div>
            </div>
            <div class="text-end"><div class="eval-note">${e.note_finale===null?'-':Number(e.note_finale).toLocaleString('fr-FR')+'%'}</div><div class="eval-actions">${buttons.join('')}</div></div>
        </div>
        ${e.appreciation||e.points_forts||e.axes_amelioration?`<div class="row g-2 mt-2">
            <div class="col-12"><small class="text-muted">Appréciation</small><div class="eval-text">${STAGIA.escape(e.appreciation||'-')}</div></div>
            <div class="col-md-6"><small class="text-muted">Points forts</small><div class="eval-text">${STAGIA.escape(e.points_forts||'-')}</div></div>
            <div class="col-md-6"><small class="text-muted">Axes d'amélioration</small><div class="eval-text">${STAGIA.escape(e.axes_amelioration||'-')}</div></div>
        </div>`:''}
    </div>`;
}

window.openEvaluation=(rotationId,evaluationId)=>{
    current=items.find(x=>Number(x.rotation_id)===Number(rotationId));
    if(!current)return;
    currentEvaluation=(current.evaluations||[]).find(e=>Number(e.id)===Number(evaluationId))||null;
    $('modalTitle').textContent=currentEvaluation?'Modifier l’évaluation':'Nouvelle évaluation';
    $('modalSubtitle').textContent=[current.nom,current.postnom,current.prenom].filter(Boolean).join(' ')+' • '+(current.unit_name||'-');
    $('typeEvaluation').value=currentEvaluation?.type_evaluation||'CONTINUE';
    $('typeEvaluation').disabled=!!currentEvaluation;
    $('appreciation').value=currentEvaluation?.appreciation||'';
    $('pointsForts').value=currentEvaluation?.points_forts||'';
    $('axesAmelioration').value=currentEvaluation?.axes_amelioration||'';
    const scoreMap={}; (currentEvaluation?.scores||[]).forEach(s=>scoreMap[Number(s.competency_id)]=s);
    let lastSection='';
    $('competencyContainer').innerHTML=(current.competencies||[]).map(c=>{
        const s=scoreMap[Number(c.id)]||{},max=Number(c.note_max||5);
        const section=(c.section||'').trim();
        const head=section&&section!==lastSection?`<div class="fw-bold text-uppercase small mt-3 mb-2 text-secondary">${STAGIA.escape(section)}</div>`:'';
        if(section)lastSection=section;
        return `${head}<div class="comp-row" data-cid="${c.id}">
            <div class="row g-2 align-items-center">
                <div class="col-md-7"><strong>${STAGIA.escape(c.nom||'-')}${Number(c.obligatoire)?' *':''}</strong><div class="small text-muted">${STAGIA.escape(c.categorie||'')} ${c.description?'• '+STAGIA.escape(c.description):''}</div></div>
                <div class="col-md-2"><div class="input-group"><input type="number" min="0" max="${max}" step="0.5" class="form-control score-input comp-note" placeholder="0 à ${max}" value="${s.note??''}"><span class="input-group-text">/${max}</span></div></div>
                <div class="col-md-3"><input type="text" class="form-control comp-comment" placeholder="Commentaire" value="${STAGIA.escape(s.commentaire||'')}"></div>
            </div>
        </div>`;
    }).join('');
    modal.show();
};

function collectScores(){
    return [...document.querySelectorAll('#competencyContainer .comp-row')].map(r=>({
        competency_id:Number(r.dataset.cid), note:r.querySelector('.comp-note').value, commentaire:r.querySelector('.comp-comment').value.trim()
    })).filter(x=>x.note!=='');
}

async function save(action){
    if(!current)return;
    const fd=new FormData();
    fd.append('csrf',CSRF); fd.append('rotation_id',current.rotation_id);
    fd.append('type_evaluation',$('typeEvaluation').value); fd.append('action',action);
    fd.append('appreciation',$('appreciation').value.trim()); fd.append('points_forts',$('pointsForts').value.trim()); fd.append('axes_amelioration',$('axesAmelioration').value.trim());
    fd.append('scores',JSON.stringify(collectScores()));
    try{
        setBusy(true);
        const r=await post('/actions/stages/host-evaluation-save.php',fd);
        modal.hide(); await load(); notify(r.message||'Évaluation enregistrée.');
    }catch(e){ alert(e.message); }finally{ setBusy(false); }
}

window.review=async(id,action)=>{
    const labels={VALIDATE:'valider',RETURN:'retourner',FINALIZE:'finaliser'};
    if(!confirm(`Confirmer : ${labels[action]||action} cette évaluation ?`))return;
    const fd=new FormData(); fd.append('csrf',CSRF); fd.append('id',id); fd.append('action',action);
    try{ const r=await post('/actions/stages/host-evaluation-review.php',fd); await load(); notify(r.message||'Action effectuée.'); }
    catch(e){ alert(e.message); }
};

async function post(path,fd){
    const res=await fetch(BASE_URL+path,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
    let r; try{ r=await res.json(); }catch(_){ throw new Error('Réponse serveur invalide.'); }
    if(!res.ok||!r.success)throw new Error(r.message||'Une erreur est survenue.');
    return r;
}
function setBusy(v){ $('btnDraft').disabled=v; $('btnSubmit').disabled=v; }
function notify(msg){ if(window.STAGIA?.toast)STAGIA.toast(msg,'success'); else console.log(msg); }
function typeLabel(v){ return {CONTINUE:'Évaluation continue',MI_ROTATION:'Évaluation mi-rotation',FIN_ROTATION:'Évaluation de fin de rotation'}[v]||v||'-'; }
function statusLabel(v){ return {BROUILLON:'Brouillon',SOUMISE:'Soumise',VALIDEE:'Validée',FINALISEE:'Finalisée'}[v]||v||'-'; }
function dateFr(v){ if(!v)return '-'; const p=String(v).substring(0,10).split('-'); return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v; }

$('search').addEventListener('input',renderFiltered);
$('btnDraft').addEventListener('click',()=>save('SAVE'));
$('btnSubmit').addEventListener('click',()=>save('SUBMIT'));
load();
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

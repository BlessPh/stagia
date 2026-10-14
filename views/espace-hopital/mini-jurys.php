<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

/* =========================================================
   MINI-JURYS — PAGE HÔPITAL
   ---------------------------------------------------------
   La liste réutilise directement la même source que
   « Rotations du service » :
   actions/espace-hopital/groupes-rotations-list.php

   Aucun traitement du menu ou des notifications n'est modifié.
========================================================= */

$roleUpper=strtoupper(trim((string)($_SESSION['role_code']??'')));

$isChef=
    $roleUpper==='CHEF_SERVICE'
    ||$roleUpper==='ENCADREUR_CHEF_SERVICE'
    ||$roleUpper==='RESPONSABLE_SERVICE'
    ||preg_match('/ROLE_CHEF_SERVICE$/',$roleUpper);

$isAdmin=in_array($roleUpper,[
    'ADMIN_ACCUEIL',
    'COORDINATEUR_STAGES'
],true);

$isAuthority=$roleUpper==='AUTORITE_HOSPITALIERE';

if(!$isChef&&!$isAdmin&&!$isAuthority){
    http_response_code(403);
    exit('Accès refusé.');
}

if(empty($_SESSION['csrf']))
    $_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Mini-jurys';
$activePage='hospital-mini-juries';

require_once __DIR__.'/../../includes/app-header.php';
?>

<style>
/* =========================================================
   MINI-JURYS — TABLEAU
   Style local uniquement.
========================================================= */
.mj-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px;border-bottom:1px solid #e8edf3}.mj-toolbar-title{font-size:16px;font-weight:800;color:#0f172a;margin:0}.mj-toolbar-subtitle{font-size:12px;color:#64748b;margin-top:3px}.mj-toolbar-actions{display:flex;align-items:center;gap:10px}.mj-search{position:relative;width:330px;max-width:100%}.mj-search i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8}.mj-search input{padding-left:38px;height:40px;border-radius:10px}.mj-refresh{height:40px;width:40px;border:1px solid #dbe3ec;border-radius:10px;background:#fff;color:#475569;display:inline-flex;align-items:center;justify-content:center}.mj-refresh:hover{background:#fff7ed;color:#f97316;border-color:#fed7aa}

.mj-table-wrap{overflow:auto}.mj-table{min-width:1080px;margin:0}.mj-table thead th{background:#f8fafc;border-bottom:1px solid #dfe6ee;color:#64748b;font-size:11px;font-weight:800;letter-spacing:.045em;text-transform:uppercase;padding:12px 14px;white-space:nowrap}.mj-table tbody td{padding:14px;border-bottom:1px solid #edf1f5;vertical-align:middle;color:#1e293b}.mj-table tbody tr{transition:.15s ease}.mj-table tbody tr:hover{background:#fffaf6}.mj-table tbody tr:last-child td{border-bottom:0}

.mj-student{display:flex;align-items:center;gap:11px;min-width:230px}.mj-avatar{width:38px;height:38px;border-radius:11px;background:#fff1e7;color:#f97316;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;flex:0 0 auto}.mj-name{font-size:13px;font-weight:800;color:#0f172a;line-height:1.25}.mj-meta{font-size:11px;color:#64748b;margin-top:3px;white-space:normal}.mj-dept{font-size:13px;font-weight:800;color:#0f172a}.mj-dept-code{display:inline-flex;margin-top:5px;padding:3px 7px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:999px;font-size:10px;font-weight:700;color:#64748b}.mj-services{font-size:11px;color:#64748b;margin-top:5px;max-width:250px;line-height:1.35}

.mj-metric{min-width:170px}.mj-metric-head{display:flex;align-items:center;justify-content:space-between;gap:8px}.mj-metric-title{font-size:12px;font-weight:800;color:#334155}.mj-metric-count{font-size:11px;font-weight:800;color:#64748b}.mj-progress{height:6px;border-radius:999px;background:#edf2f7;overflow:hidden;margin-top:7px}.mj-progress>span{display:block;height:100%;border-radius:999px;background:#f97316}.mj-date{font-size:10px;color:#94a3b8;margin-top:6px}.mj-score{font-size:13px;font-weight:900;color:#0f172a}

.mj-status{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:800;white-space:nowrap}.mj-status-ready{background:#fff1e7;color:#c2410c}.mj-status-draft{background:#f3e8ff;color:#7e22ce}.mj-status-valid{background:#dcfce7;color:#15803d}.mj-status-wait{background:#f1f5f9;color:#64748b}.mj-result{margin-top:5px;font-size:11px;color:#64748b}

.mj-action{min-width:105px;border-radius:9px;font-size:11px;font-weight:800;padding:7px 11px}.mj-action-wait{background:#f8fafc;border:1px solid #e2e8f0;color:#94a3b8}.mj-action-open{background:#f97316;border:1px solid #f97316;color:#fff}.mj-action-open:hover{background:#ea580c;border-color:#ea580c;color:#fff}.mj-action-view{background:#fff;border:1px solid #bbf7d0;color:#15803d}.mj-action-view:hover{background:#f0fdf4;color:#166534}

.mj-state{padding:46px 20px;text-align:center}.mj-state-icon{width:48px;height:48px;border-radius:14px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;background:#f8fafc;color:#94a3b8;font-size:20px}.mj-state strong{display:block;color:#334155;font-size:13px}.mj-state small{display:block;color:#94a3b8;margin-top:4px}.mj-state-error .mj-state-icon{background:#fef2f2;color:#ef4444}.mj-state-error strong{color:#dc2626}

.mj-footer{padding:12px 16px;border-top:1px solid #e8edf3;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.mj-pagination{display:flex;align-items:center;gap:6px}.mj-pagination .btn{border-radius:8px;font-size:11px;font-weight:700}.mj-card{background:#fff;border:1px solid #e5eaf0;border-radius:15px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,.035)}

@media(max-width:767.98px){.mj-toolbar{align-items:flex-start;flex-direction:column}.mj-toolbar-actions,.mj-search{width:100%}.mj-search{flex:1}.mj-table{min-width:980px}}
</style>

<main class="dashboard-content">

    <div class="stagia-page-head">
        <div>
            <h1>Mini-jurys</h1>
            <p>Validation du passage de chaque stagiaire par département avant la délibération finale.</p>
        </div>
    </div>

    <div class="stagia-kpi-grid">

        <div class="stagia-kpi-card">
            <div>
                <span>À FAIRE</span>
                <strong id="kTodo">0</strong>
                <small>Départements prêts</small>
            </div>
            <div class="stagia-kpi-icon kpi-orange"><i class="bi bi-hourglass-split"></i></div>
        </div>

        <div class="stagia-kpi-card">
            <div>
                <span>BROUILLONS</span>
                <strong id="kDraft">0</strong>
                <small>Mini-jurys en préparation</small>
            </div>
            <div class="stagia-kpi-icon kpi-purple"><i class="bi bi-pencil-square"></i></div>
        </div>

        <div class="stagia-kpi-card">
            <div>
                <span>VALIDÉS</span>
                <strong id="kDone">0</strong>
                <small>Mini-jurys terminés</small>
            </div>
            <div class="stagia-kpi-icon kpi-green"><i class="bi bi-patch-check"></i></div>
        </div>

        <div class="stagia-kpi-card">
            <div>
                <span>EN ATTENTE</span>
                <strong id="kWaiting">0</strong>
                <small>Rotations / évaluations incomplètes</small>
            </div>
            <div class="stagia-kpi-icon kpi-blue"><i class="bi bi-clock-history"></i></div>
        </div>

    </div>

    <div class="mj-card">

        <div class="mj-toolbar">

            <div>
                <h5 class="mj-toolbar-title">Passages par département</h5>
                <div class="mj-toolbar-subtitle">Un seul mini-jury par stagiaire et par département.</div>
            </div>

            <div class="mj-toolbar-actions">

                <div class="mj-search">
                    <i class="bi bi-search"></i>
                    <input id="search" class="form-control" placeholder="Rechercher stagiaire, département...">
                </div>

                <button id="refreshBtn" type="button" class="mj-refresh" title="Actualiser">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>

            </div>

        </div>

        <div class="mj-table-wrap">

            <table class="table mj-table">

                <thead>
                    <tr>
                        <th>Stagiaire</th>
                        <th>Département</th>
                        <th>Passage</th>
                        <th>Évaluations</th>
                        <th>Mini-jury</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>

                <tbody id="rows"></tbody>

            </table>

        </div>

        <div class="mj-footer">
            <small id="pageInfo" class="text-muted"></small>
            <div id="pagination" class="mj-pagination"></div>
        </div>

    </div>

</main>

<div class="modal fade" id="juryModal" tabindex="-1">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Mini-jury départemental</h5>
                    <small id="modalStudent" class="text-muted"></small>
                </div>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">

                <div class="row g-3 mb-3">

                    <div class="col-md-6">
                        <label class="form-label">Département</label>
                        <input id="department" class="form-control" disabled>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Services / unités parcourus</label>
                        <input id="services" class="form-control" disabled>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Rotations terminées</label>
                        <input id="rotations" class="form-control" disabled>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">FIN_ROTATION finalisées</label>
                        <input id="evaluations" class="form-control" disabled>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Moyenne des évaluations</label>
                        <input id="evaluationAverage" class="form-control fw-bold" disabled>
                    </div>

                </div>

                <div id="notReady" class="alert alert-warning d-none">
                    Ce mini-jury n'est pas encore disponible. Toutes les rotations du département doivent être terminées
                    et chaque rotation doit avoir une évaluation <strong>FIN_ROTATION finalisée</strong>.
                </div>

                <div class="row g-3">

                    <div class="col-md-4">
                        <label class="form-label">Date du mini-jury</label>
                        <input id="juryDate" type="date" class="form-control">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Note du mini-jury / 100</label>
                        <input id="score" type="number" min="0" max="100" step="0.01" class="form-control" placeholder="0 à 100">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Décision</label>
                        <select id="decision" class="form-select">
                            <option value="">Choisir...</option>
                            <option value="VALIDE">Validé</option>
                            <option value="A_REPRENDRE">À reprendre</option>
                            <option value="AJOURNE">Ajourné</option>
                        </select>
                    </div>

                </div>

                <div class="mt-3">
                    <label class="form-label">Appréciation / observations du jury</label>
                    <textarea id="appreciation" class="form-control" rows="4" placeholder="Observations du mini-jury..."></textarea>
                </div>

                <div class="mt-3">
                    <span id="juryStatus"></span>
                </div>

            </div>

            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Fermer</button>
                <button id="saveDraft" class="btn btn-outline-secondary d-none">Enregistrer brouillon</button>
                <button id="validateJury" class="btn btn-success d-none">
                    <i class="bi bi-check2-circle me-1"></i>Valider le mini-jury
                </button>
            </div>

        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{

    const BASE='<?= BASE_URL ?>';
    const CSRF='<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>';
    const SERVER_ITEMS=[];
    const SERVER_ERROR='';

    const $=id=>document.getElementById(id);
    const esc=STAGIA.escape;
    const modal=new bootstrap.Modal($('juryModal'));

    let allItems=Array.isArray(SERVER_ITEMS)?SERVER_ITEMS:[];
    let items=[];
    let current=null;
    let page=1;
    let timer=null;

    const PER_PAGE=15;

    function initials(name){
        const p=String(name||'').trim().split(/\s+/).filter(Boolean);
        return ((p[0]?.[0]||'S')+(p[1]?.[0]||'')).toUpperCase();
    }

    function fr(v){
        if(!v)return '-';

        const p=String(v).slice(0,10).split('-');

        return p.length===3
            ?`${p[2]}/${p[1]}/${p[0]}`
            :v;
    }

    function pct(done,total){
        total=Number(total||0);
        done=Number(done||0);

        return total>0
            ?Math.max(0,Math.min(100,Math.round(done*100/total)))
            :0;
    }

    function juryStatus(x){
        if(x.mini_jury_status==='VALIDEE')
            return '<span class="mj-status mj-status-valid"><i class="bi bi-check2-circle"></i>VALIDÉ</span>';

        if(x.mini_jury_status==='BROUILLON')
            return '<span class="mj-status mj-status-draft"><i class="bi bi-pencil"></i>BROUILLON</span>';

        if(Number(x.ready)===1)
            return '<span class="mj-status mj-status-ready"><i class="bi bi-hourglass-split"></i>À FAIRE</span>';

        return '<span class="mj-status mj-status-wait"><i class="bi bi-clock"></i>EN ATTENTE</span>';
    }

    function emptyRow(){
        return `
            <tr>
                <td colspan="6">
                    <div class="mj-state">
                        <div class="mj-state-icon"><i class="bi bi-people"></i></div>
                        <strong>Aucun passage à afficher</strong>
                        <small>Aucun stagiaire avec un plan de rotation n'est disponible dans votre périmètre.</small>
                    </div>
                </td>
            </tr>
        `;
    }

    function errorRow(message){
        return `
            <tr>
                <td colspan="6">
                    <div class="mj-state mj-state-error">
                        <div class="mj-state-icon"><i class="bi bi-exclamation-triangle"></i></div>
                        <strong>${esc(message)}</strong>
                        <small>La page est connectée ; l'erreur vient uniquement de la requête de données.</small>
                    </div>
                </td>
            </tr>
        `;
    }


    /*
     * Charge exactement la même source que « Rotations du service ».
     * On récupère d'abord les contextes, puis chaque couple
     * campagne/promotion visible dans le périmètre.
     */
    async function loadFromRotations(){

        $('rows').innerHTML=`
            <tr>
                <td colspan="6">
                    <div class="mj-state">
                        <div class="mj-state-icon"><i class="bi bi-arrow-repeat"></i></div>
                        <strong>Chargement des passages...</strong>
                    </div>
                </td>
            </tr>
        `;

        try{

            const sourceUrl=
                BASE+
                '/actions/espace-hopital/groupes-rotations-list.php';

            const first=await STAGIA.request(sourceUrl);

            const contexts=first.data.contexts||[];
            const keys=[];
            const seen=new Set();

            contexts.forEach(c=>{
                const key=
                    Number(c.campaign_id)+
                    '-'+
                    Number(c.promotion_id);

                if(seen.has(key))return;

                seen.add(key);

                keys.push({
                    campaign_id:Number(c.campaign_id),
                    promotion_id:Number(c.promotion_id)
                });
            });

            let sources=[];

            if(keys.length){

                sources=await Promise.all(
                    keys.map(k=>
                        STAGIA.request(
                            sourceUrl+
                            '?campaign_id='+
                            encodeURIComponent(k.campaign_id)+
                            '&promotion_id='+
                            encodeURIComponent(k.promotion_id)
                        )
                    )
                );

            }else{

                sources=[first];
            }

            /*
             * Métadonnées Mini-jury uniquement :
             * rotations individuelles, évaluations FIN_ROTATION
             * et mini-jurys déjà enregistrés.
             */
            const meta=await STAGIA.request(
                BASE+
                '/actions/stages/host-mini-jury-meta.php'
            );

            allItems=buildItemsFromRotationSource(
                sources.map(x=>x.data||{}),
                meta.data||{}
            );

            updateKpis();
            filterAndRender(1);

        }catch(e){

            $('rows').innerHTML=errorRow(
                e.message||'Impossible de charger les passages.'
            );

            $('pageInfo').textContent='';
            $('pagination').innerHTML='';
        }
    }

    function buildItemsFromRotationSource(sources,meta){

        const individualByPlan=new Map();
        const individualBySequence=new Map();

        (meta.rotations||[]).forEach(r=>{
            const assignment=Number(r.assignment_id||0);
            const plan=Number(r.group_rotation_plan_id||0);
            const group=Number(r.group_id||0);
            const sequence=Number(r.sequence_no||0);

            if(assignment&&plan)
                individualByPlan.set(
                    assignment+'-'+plan,
                    r
                );

            if(assignment&&group&&sequence)
                individualBySequence.set(
                    assignment+'-'+group+'-'+sequence,
                    r
                );
        });

        const juryMap=new Map();

        (meta.juries||[]).forEach(j=>{
            juryMap.set(
                Number(j.assignment_id)+
                '-'+
                Number(j.department_unit_id),
                j
            );
        });

        const grouped=new Map();
        const seenPlans=new Set();

        sources.forEach(source=>{

            const unitMap=new Map();

            (source.units||[]).forEach(u=>
                unitMap.set(
                    Number(u.id),
                    u
                )
            );

            const groupMap=new Map();

            (source.groups||[]).forEach(g=>
                groupMap.set(
                    Number(g.id),
                    g
                )
            );

            (source.students||[]).forEach(student=>{

                const groupId=Number(student.group_id||0);
                const assignmentId=Number(student.assignment_id||0);

                if(!groupId||!assignmentId)return;

                const group=groupMap.get(groupId);

                if(!group)return;

                (group.rotations||[]).forEach(plan=>{

                    const planId=Number(plan.id||0);

                    const duplicateKey=
                        assignmentId+
                        '-'+
                        planId;

                    if(seenPlans.has(duplicateKey))
                        return;

                    seenPlans.add(duplicateKey);

                    const unitId=Number(plan.host_unit_id||0);
                    const unit=unitMap.get(unitId)||{};

                    const parentId=Number(unit.parent_id||0);
                    const parent=parentId
                        ?unitMap.get(parentId)
                        :null;

                    const departmentId=
                        parent
                            ?Number(parent.id)
                            :unitId;

                    const departmentName=
                        parent?.nom
                        ||plan.parent_name
                        ||unit.nom
                        ||plan.unit_name
                        ||'Département';

                    const departmentCode=
                        parent?.code
                        ||unit.code
                        ||'';

                    const rotation=
                        individualByPlan.get(
                            assignmentId+'-'+planId
                        )
                        ||individualBySequence.get(
                            assignmentId+
                            '-'+
                            groupId+
                            '-'+
                            Number(plan.sequence_no||0)
                        )
                        ||null;

                    const jury=
                        juryMap.get(
                            assignmentId+
                            '-'+
                            departmentId
                        )
                        ||null;

                    const key=
                        assignmentId+
                        '-'+
                        departmentId;

                    if(!grouped.has(key)){

                        grouped.set(key,{
                            assignment_id:assignmentId,
                            student_id:Number(student.student_id||0),

                            student_name:[
                                student.nom,
                                student.postnom,
                                student.prenom
                            ].filter(Boolean).join(' ')||'Stagiaire',

                            stagia_code:student.stagia_code||'',
                            university_name:
                                (source.contexts||[]).find(c=>
                                    Number(c.campaign_id)===
                                    Number(source.selected?.campaign_id)
                                )?.university_name
                                ||'',

                            department_unit_id:departmentId,
                            department_code:departmentCode,
                            department_name:departmentName,

                            services_map:new Map(),

                            rotation_total:0,
                            rotation_done:0,
                            final_eval_count:0,
                            evaluation_scores:[],

                            date_debut:null,
                            date_fin:null,

                            mini_jury_id:jury
                                ?Number(jury.id)
                                :null,

                            mini_jury_status:jury?.statut||null,

                            mini_jury_score:
                                jury?.note_finale!==null
                                &&jury?.note_finale!==undefined
                                    ?Number(jury.note_finale)
                                    :null,

                            mini_jury_decision:
                                jury?.decision||null,

                            mini_jury_appreciation:
                                jury?.appreciation||null,

                            jury_date:
                                jury?.date_jury||null,

                            validated_at:
                                jury?.validated_at||null,

                            can_manage:
                                Number(meta.can_manage||0)
                        });
                    }

                    const item=grouped.get(key);

                    item.services_map.set(
                        unitId,
                        plan.unit_name
                        ||unit.nom
                        ||'Service'
                    );

                    item.rotation_total++;

                    const status=
                        rotation?.statut
                        ||plan.statut
                        ||'PLANIFIEE';

                    if(status==='TERMINEE')
                        item.rotation_done++;

                    if(
                        rotation
                        &&Number(rotation.final_eval_ready)===1
                    ){
                        item.final_eval_count++;

                        if(
                            rotation.final_score!==null
                            &&rotation.final_score!==undefined
                        ){
                            item.evaluation_scores.push(
                                Number(rotation.final_score)
                            );
                        }
                    }

                    const start=
                        rotation?.date_debut
                        ||plan.date_debut
                        ||null;

                    const end=
                        rotation?.date_fin
                        ||plan.date_fin
                        ||null;

                    if(
                        start
                        &&(
                            !item.date_debut
                            ||start<item.date_debut
                        )
                    ){
                        item.date_debut=start;
                    }

                    if(
                        end
                        &&(
                            !item.date_fin
                            ||end>item.date_fin
                        )
                    ){
                        item.date_fin=end;
                    }
                });
            });
        });

        return [...grouped.values()].map(item=>{

            item.services=
                [...item.services_map.values()].join(', ');

            delete item.services_map;

            item.evaluation_average=
                item.evaluation_scores.length
                    ?item.evaluation_scores.reduce(
                        (a,b)=>a+b,
                        0
                    )/item.evaluation_scores.length
                    :null;

            delete item.evaluation_scores;

            item.ready=
                item.rotation_total>0
                &&item.rotation_done===item.rotation_total
                &&item.final_eval_count===item.rotation_total
                    ?1
                    :0;

            return item;
        });
    }

    function updateKpis(){

        const stats={
            todo:0,
            drafts:0,
            validated:0,
            waiting:0
        };

        allItems.forEach(x=>{

            if(x.mini_jury_status==='VALIDEE'){
                stats.validated++;

            }else if(x.mini_jury_status==='BROUILLON'){
                stats.drafts++;

            }else if(Number(x.ready)===1){
                stats.todo++;

            }else{
                stats.waiting++;
            }
        });

        $('kTodo').textContent=stats.todo;
        $('kDraft').textContent=stats.drafts;
        $('kDone').textContent=stats.validated;
        $('kWaiting').textContent=stats.waiting;
    }

    function filterAndRender(targetPage=1){

        const q=$('search').value.trim().toLowerCase();

        items=allItems.filter(x=>{
            if(!q)return true;

            return [
                x.student_name,
                x.stagia_code,
                x.university_name,
                x.department_name,
                x.department_code,
                x.services
            ].join(' ').toLowerCase().includes(q);
        });

        const pages=Math.max(1,Math.ceil(items.length/PER_PAGE));

        page=Math.max(1,Math.min(Number(targetPage||1),pages));

        render(
            items.slice(
                (page-1)*PER_PAGE,
                page*PER_PAGE
            )
        );

        renderPagination({
            page,
            pages,
            total:items.length
        });
    }

    function render(rows){

        if(!rows.length){
            $('rows').innerHTML=emptyRow();
            return;
        }

        $('rows').innerHTML=rows.map(x=>{

            const ready=Number(x.ready)===1;
            const existing=Number(x.mini_jury_id||0)>0;
            const canManage=Number(x.can_manage)===1;
            const validated=x.mini_jury_status==='VALIDEE';

            const rotPct=pct(x.rotation_done,x.rotation_total);
            const evalPct=pct(x.final_eval_count,x.rotation_total);

            let button='';

            if(existing){
                button=`
                    <button
                        class="btn mj-action ${validated?'mj-action-view':'mj-action-open'} open"
                        data-assignment="${Number(x.assignment_id)}"
                        data-department="${Number(x.department_unit_id)}">
                        <i class="bi ${validated?'bi-eye':'bi-pencil-square'} me-1"></i>
                        ${validated?'Voir':'Continuer'}
                    </button>
                `;
            }else if(ready&&canManage){
                button=`
                    <button
                        class="btn mj-action mj-action-open open"
                        data-assignment="${Number(x.assignment_id)}"
                        data-department="${Number(x.department_unit_id)}">
                        <i class="bi bi-people me-1"></i>
                        Ouvrir
                    </button>
                `;
            }else{
                button=`
                    <button class="btn mj-action mj-action-wait" disabled>
                        <i class="bi bi-lock me-1"></i>
                        En attente
                    </button>
                `;
            }

            return `
                <tr>

                    <td>
                        <div class="mj-student">
                            <div class="mj-avatar">${esc(initials(x.student_name))}</div>
                            <div>
                                <div class="mj-name">${esc(x.student_name||'-')}</div>
                                <div class="mj-meta">
                                    ${esc(x.stagia_code||'Sans code')}
                                    <span class="mx-1">•</span>
                                    ${esc(x.university_name||'-')}
                                </div>
                            </div>
                        </div>
                    </td>

                    <td>
                        <div class="mj-dept">${esc(x.department_name||'-')}</div>
                        ${x.department_code
                            ?`<span class="mj-dept-code">${esc(x.department_code)}</span>`
                            :''
                        }
                        <div class="mj-services">
                            <i class="bi bi-diagram-2 me-1"></i>
                            ${esc(x.services||'-')}
                        </div>
                    </td>

                    <td>
                        <div class="mj-metric">
                            <div class="mj-metric-head">
                                <span class="mj-metric-title">Rotations</span>
                                <span class="mj-metric-count">${Number(x.rotation_done)}/${Number(x.rotation_total)}</span>
                            </div>
                            <div class="mj-progress"><span style="width:${rotPct}%"></span></div>
                            <div class="mj-date">
                                <i class="bi bi-calendar3 me-1"></i>
                                ${fr(x.date_debut)} → ${fr(x.date_fin)}
                            </div>
                        </div>
                    </td>

                    <td>
                        <div class="mj-metric">
                            <div class="mj-metric-head">
                                <span class="mj-metric-title">FIN_ROTATION</span>
                                <span class="mj-metric-count">${Number(x.final_eval_count)}/${Number(x.rotation_total)}</span>
                            </div>
                            <div class="mj-progress"><span style="width:${evalPct}%"></span></div>
                            <div class="mj-date">
                                Moyenne :
                                <span class="mj-score">
                                    ${x.evaluation_average!==null
                                        ?Number(x.evaluation_average).toFixed(2)+' %'
                                        :'-'
                                    }
                                </span>
                            </div>
                        </div>
                    </td>

                    <td>
                        ${juryStatus(x)}

                        ${validated&&x.mini_jury_score!==null
                            ?`<div class="mj-result">Note jury : <strong>${Number(x.mini_jury_score).toFixed(2)} %</strong></div>`
                            :''
                        }

                        ${validated&&x.mini_jury_decision
                            ?`<div class="mj-result">Décision : <strong>${esc(x.mini_jury_decision.replaceAll('_',' '))}</strong></div>`
                            :''
                        }
                    </td>

                    <td class="text-end">
                        ${button}
                    </td>

                </tr>
            `;
        }).join('');
    }

    function renderPagination(p){

        const currentPage=Number(p.page||1);
        const pages=Number(p.pages||1);
        const total=Number(p.total||0);

        $('pageInfo').textContent=
            total
                ?`${total} passage(s) • page ${currentPage}/${pages}`
                :'0 passage';

        $('pagination').innerHTML=`
            <button
                class="btn btn-sm btn-outline-secondary pg"
                data-page="${currentPage-1}"
                ${currentPage<=1?'disabled':''}>
                <i class="bi bi-chevron-left"></i>
                Précédent
            </button>

            <button
                class="btn btn-sm btn-outline-secondary pg"
                data-page="${currentPage+1}"
                ${currentPage>=pages?'disabled':''}>
                Suivant
                <i class="bi bi-chevron-right"></i>
            </button>
        `;
    }

    $('pagination').onclick=e=>{
        const b=e.target.closest('.pg');

        if(!b||b.disabled)return;

        filterAndRender(Number(b.dataset.page));
    };

    $('search').oninput=()=>{
        clearTimeout(timer);
        timer=setTimeout(()=>filterAndRender(1),250);
    };

    /*
     * Actualiser recharge uniquement cette page.
     * Le système général de notifications reste inchangé.
     */
    $('refreshBtn').onclick=()=>loadFromRotations();

    $('rows').onclick=e=>{

        const b=e.target.closest('.open');

        if(!b)return;

        current=allItems.find(x=>
            Number(x.assignment_id)===Number(b.dataset.assignment)
            &&Number(x.department_unit_id)===Number(b.dataset.department)
        );

        if(!current)return;

        $('modalStudent').textContent=
            current.student_name+
            ' • '+
            (current.university_name||'-');

        $('department').value=current.department_name||'';
        $('services').value=current.services||'';
        $('rotations').value=`${current.rotation_done}/${current.rotation_total}`;
        $('evaluations').value=`${current.final_eval_count}/${current.rotation_total}`;

        $('evaluationAverage').value=
            current.evaluation_average!==null
                ?Number(current.evaluation_average).toFixed(2)+' %'
                :'-';

        $('juryDate').value=
            current.jury_date
            ||new Date().toISOString().slice(0,10);

        $('score').value=
            current.mini_jury_score!==null
            &&current.mini_jury_score!==''
                ?current.mini_jury_score
                :(current.evaluation_average??'');

        $('decision').value=
            current.mini_jury_decision
            ||(
                current.evaluation_average!==null
                    ?(
                        Number(current.evaluation_average)>=50
                            ?'VALIDE'
                            :'A_REPRENDRE'
                    )
                    :''
            );

        $('appreciation').value=current.mini_jury_appreciation||'';

        const ready=Number(current.ready)===1;
        const canManage=Number(current.can_manage)===1;
        const validated=current.mini_jury_status==='VALIDEE';
        const editable=ready&&canManage&&!validated;

        $('notReady').classList.toggle('d-none',ready);
        $('juryStatus').innerHTML=juryStatus(current);

        [
            'juryDate',
            'score',
            'decision',
            'appreciation'
        ].forEach(id=>$(id).disabled=!editable);

        $('saveDraft').classList.toggle('d-none',!editable);
        $('validateJury').classList.toggle('d-none',!editable);

        modal.show();
    };

    async function save(action){

        if(!current)return;

        if(action==='VALIDATE'){

            if(!$('score').value)
                return STAGIA.toast(
                    'Saisissez la note du mini-jury.',
                    'danger'
                );

            if(!$('decision').value)
                return STAGIA.toast(
                    'Choisissez la décision du mini-jury.',
                    'danger'
                );

            if(!$('juryDate').value)
                return STAGIA.toast(
                    'Choisissez la date du mini-jury.',
                    'danger'
                );

            if(!confirm('Valider définitivement ce mini-jury ?'))
                return;
        }

        const f=new FormData();

        f.append('csrf',CSRF);
        f.append('assignment_id',current.assignment_id);
        f.append('department_unit_id',current.department_unit_id);
        f.append('action',action);
        f.append('date_jury',$('juryDate').value);
        f.append('score',$('score').value);
        f.append('decision',$('decision').value);
        f.append('appreciation',$('appreciation').value.trim());

        try{

            const r=await STAGIA.post(
                BASE+
                '/actions/stages/host-mini-jury-save.php',
                f
            );

            modal.hide();
            STAGIA.toast(r.message);

            /*
             * Recharger la même page pour refléter le nouveau mini-jury.
             * Les notifications globales restent gérées par app-header.
             */
            window.location.reload();

        }catch(e){

            STAGIA.toast(
                e.message,
                'danger'
            );
        }
    }

    $('saveDraft').onclick=()=>save('SAVE');
    $('validateJury').onclick=()=>save('VALIDATE');

    loadFromRotations();

});
</script>

<!-- MINI-JURYS V14 : source exacte de Rotations du service -->
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>



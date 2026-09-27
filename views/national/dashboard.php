<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/admin-scope.php';

requirePermission($pdo,'analytics.overview.view');

$estMinisteriel=estResponsableMinisteriel($pdo);
$pageTitle=hasRole('ORDRE_MEDECINS')&&!$estMinisteriel?'Supervision médicale':($estMinisteriel?'Pilotage ministériel':(hasRole('SUPER_ADMIN')?'Pilotage national':'Statistiques de l’établissement'));
$activePage='national-dashboard';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.national-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.national-card,.national-panel{background:#fff;border:1px solid #e7ebf0;border-radius:16px}
.national-card{padding:18px}.national-panel{padding:18px;height:100%}
.national-card h6{font-size:11px;letter-spacing:.04em;color:#64748b;margin:0 0 8px;text-transform:uppercase}
.national-card strong{font-size:30px;line-height:1;color:#0f172a}
.national-sub{font-size:12px;color:#64748b;margin-top:7px}
.national-panel h5{font-size:16px;margin:0}
.national-bar{margin:12px 0}.national-bar-head{display:flex;justify-content:space-between;gap:10px;font-size:13px;margin-bottom:5px}
.national-track{height:8px;border-radius:10px;background:#eef2f7;overflow:hidden}
.national-fill{height:100%;border-radius:10px;background:#f97316}
.scope-note{padding:12px 14px;border:1px solid #fed7aa;background:#fff7ed;border-radius:12px;font-size:13px;color:#9a3412}
.warning-note{padding:12px 14px;border:1px solid #fde68a;background:#fffbeb;border-radius:12px;font-size:13px;color:#92400e}
@media(max-width:1200px){.national-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:700px){.national-grid{grid-template-columns:1fr}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
        <p><?= hasRole('ORDRE_MEDECINS')
            ?'Indicateurs agrégés du domaine médecine, par établissement et par année académique.'
            :($estMinisteriel?'Indicateurs des organisations officiellement rattachées à votre ministère.':(hasRole('SUPER_ADMIN')?'Indicateurs agrégés nationaux par établissement et structure académique.':'Indicateurs agrégés de votre établissement par année, faculté, département, promotion et province.')) ?></p>
    </div>
    <?php if(hasPermission($pdo,'analytics.export')): ?>
    <a id="exportBtn" class="btn btn-outline-dark" href="<?= BASE_URL ?>/actions/national/export-etudiants.php">
        <i class="bi bi-file-earmark-spreadsheet me-2"></i>Exporter
    </a>
    <?php endif; ?>
</div>

<div class="scope-note mb-3">
    <i class="bi bi-shield-check me-1"></i>
    Cet espace restitue des <strong>données agrégées</strong>. Aucun dossier étudiant individuel n'est exposé ici.
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4 col-xl-3">
        <label class="form-label">Année académique</label>
        <select id="yearFilter" class="form-select"><option value="">Toutes les années</option></select>
    </div>
    <div class="col-md-4 col-xl-3">
        <label class="form-label">Établissement</label>
        <select id="etabFilter" class="form-select"><option value="">Tous les établissements</option></select>
    </div>
    <div class="col-md-4 col-xl-3"><label class="form-label">Province</label><select id="provinceFilter" class="form-select"><option value="">Toutes les provinces</option></select></div>
    <div class="col-md-4 col-xl-3"><label class="form-label">Faculté / Institut</label><select id="facultyFilter" class="form-select"><option value="">Toutes les facultés</option></select></div>
    <div class="col-md-4 col-xl-3"><label class="form-label">Département</label><select id="departmentFilter" class="form-select"><option value="">Tous les départements</option></select></div>
    <div class="col-md-4 col-xl-3"><label class="form-label">Promotion</label><select id="promotionFilter" class="form-select"><option value="">Toutes les promotions</option></select></div>
</div>

<div class="national-grid mb-4">
    <div class="national-card"><h6>Étudiants</h6><strong id="kStudents">0</strong><div class="national-sub">Effectif agrégé du périmètre</div></div>
    <div class="national-card"><h6>Établissements de formation</h6><strong id="kAcademic">0</strong><div class="national-sub">Avec étudiants dans le périmètre</div></div>
    <div class="national-card"><h6>Structures d'accueil</h6><strong id="kHosts">0</strong><div class="national-sub">Hôpitaux actifs / validés</div></div>
    <div class="national-card"><h6>Années couvertes</h6><strong id="kYears">0</strong><div class="national-sub">Années académiques représentées</div></div>
</div>

<div id="classificationWarning" class="warning-note mb-4 d-none"></div>

<div class="row g-4 mb-4" id="repartition">
<div class="col-xl-8">
<div class="national-panel">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5>Effectifs par établissement et par année</h5>
        <span id="modeBadge" class="badge bg-light text-dark border"></span>
    </div>
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>ÉTABLISSEMENT</th><th>TYPE</th><th>ANNÉE</th><th class="text-end">ÉTUDIANTS</th></tr></thead>
            <tbody id="establishmentRows"><tr><td colspan="4" class="text-center py-4 text-muted">Chargement...</td></tr></tbody>
        </table>
    </div>
</div>
</div>
<div class="col-xl-4">
<div class="national-panel">
    <h5 class="mb-3">Établissements par type</h5>
    <div id="typeBars"></div>
</div>
</div>
</div>

<div class="row g-4" id="annees">
<div class="col-xl-6">
<div class="national-panel">
    <h5 class="mb-3">Effectifs par année académique</h5>
    <div id="yearBars"></div>
</div>
</div>
<div class="col-xl-6">
<div class="national-panel">
    <h5 class="mb-3">Répartition géographique</h5>
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>PROVINCE</th><th class="text-center">ÉTABL.</th><th class="text-end">ÉTUDIANTS</th></tr></thead>
            <tbody id="provinceRows"></tbody>
        </table>
    </div>
</div>
</div>
</div>

<div class="row g-4 mt-1" id="academicBreakdowns">
<div class="col-xl-4"><div class="national-panel"><h5 class="mb-3">Par faculté / institut</h5><div id="facultyBars"></div></div></div>
<div class="col-xl-4"><div class="national-panel"><h5 class="mb-3">Par département</h5><div id="departmentBars"></div></div></div>
<div class="col-xl-4"><div class="national-panel"><h5 class="mb-3">Par promotion</h5><div id="promotionBars"></div></div></div>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const initialParams=new URLSearchParams(location.search);let initialized=false;

function bars(target,items,labelKey='label'){
    const max=Math.max(1,...items.map(x=>Number(x.total)||0));
    $(target).innerHTML=items.length?items.map(x=>`
        <div class="national-bar">
            <div class="national-bar-head"><span>${esc(x[labelKey]||'—')}</span><strong>${Number(x.total)||0}</strong></div>
            <div class="national-track"><div class="national-fill" style="width:${((Number(x.total)||0)/max)*100}%"></div></div>
        </div>`).join(''):'<div class="text-muted py-3">Aucune donnée.</div>';
}

async function load(){
    try{
        const q=new URLSearchParams();
        if($('yearFilter').value||(!initialized&&initialParams.get('year')))q.set('year',$('yearFilter').value||initialParams.get('year'));
        if($('etabFilter').value||(!initialized&&initialParams.get('etablissement_id')))q.set('etablissement_id',$('etabFilter').value||initialParams.get('etablissement_id'));
        if($('provinceFilter').value)q.set('province',$('provinceFilter').value);
        if($('facultyFilter').value)q.set('faculte_id',$('facultyFilter').value);
        if($('departmentFilter').value)q.set('departement_id',$('departmentFilter').value);
        if($('promotionFilter').value)q.set('promotion_id',$('promotionFilter').value);

        const r=await STAGIA.request(BASE_URL+'/actions/national/dashboard-data.php?'+q.toString());
        const d=r.data,k=d.kpis;

        if(!initialized){
            $('yearFilter').innerHTML='<option value="">Toutes les années</option>'+
                (d.filters.years||[]).map(x=>`<option value="${esc(x.libelle)}">${esc(x.libelle)}</option>`).join('');
            $('etabFilter').innerHTML='<option value="">Tous les établissements</option>'+
                (d.filters.establishments||[]).map(x=>`<option value="${x.id}">${esc(x.nom)} · ${esc(x.type_etablissement)}</option>`).join('');
            $('provinceFilter').innerHTML='<option value="">Toutes les provinces</option>'+(d.filters.provinces||[]).map(x=>`<option value="${esc(x.province)}">${esc(x.province)}</option>`).join('');
            $('facultyFilter').innerHTML='<option value="">Toutes les facultés</option>'+(d.filters.faculties||[]).map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');
            $('departmentFilter').innerHTML='<option value="">Tous les départements</option>'+(d.filters.departments||[]).map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');
            $('promotionFilter').innerHTML='<option value="">Toutes les promotions</option>'+(d.filters.promotions||[]).map(x=>`<option value="${x.id}">${esc(x.nom)}</option>`).join('');
            ['year','etablissement_id','province','faculte_id','departement_id','promotion_id'].forEach((name,i)=>{const id=['yearFilter','etabFilter','provinceFilter','facultyFilter','departmentFilter','promotionFilter'][i],value=initialParams.get(name);if(value&&[...$(id).options].some(o=>o.value===value))$(id).value=value;});
            initialized=true;
        }

        $('kStudents').textContent=k.students||0;
        $('kAcademic').textContent=k.academic_establishments||0;
        $('kHosts').textContent=k.host_establishments||0;
        $('kYears').textContent=k.covered_years||0;
        $('modeBadge').textContent=d.mode==='MEDICINE'?'Médecine':(d.mode==='SANTE'?'Santé':'Tous domaines');

        const unclassified=Number(k.unclassified_students)||0;
        $('classificationWarning').classList.toggle('d-none',unclassified===0);
        $('classificationWarning').innerHTML=unclassified
            ?`<i class="bi bi-exclamation-triangle me-1"></i><strong>${unclassified}</strong> étudiant(s) ont une filière sans référentiel de cursus. Ils ne sont pas inclus dans le périmètre réglementé tant que la filière n'est pas classée.`
            :'';

        $('establishmentRows').innerHTML=(d.by_establishment||[]).length?(d.by_establishment||[]).map(x=>`<tr>
            <td><strong>${esc(x.nom)}</strong><small class="d-block text-muted">${esc(x.code||'')}</small></td>
            <td><span class="badge bg-light text-dark border">${esc(x.type_etablissement||'—')}</span></td>
            <td>${esc(x.annee||'—')}</td>
            <td class="text-end"><strong>${Number(x.total)||0}</strong></td>
        </tr>`).join(''):'<tr><td colspan="4" class="text-center py-4 text-muted">Aucune donnée pour ce filtre.</td></tr>';

        bars('typeBars',d.establishment_types||[]);
        bars('yearBars',d.by_year||[]);
        bars('facultyBars',d.by_faculty||[]);bars('departmentBars',d.by_department||[]);bars('promotionBars',d.by_promotion||[]);

        $('provinceRows').innerHTML=(d.by_province||[]).length?(d.by_province||[]).map(x=>`<tr>
            <td>${esc(x.label||'—')}</td>
            <td class="text-center">${Number(x.etablissements)||0}</td>
            <td class="text-end"><strong>${Number(x.etudiants)||0}</strong></td>
        </tr>`).join(''):'<tr><td colspan="3" class="text-center py-4 text-muted">Aucune donnée.</td></tr>';

        const exportBtn=$('exportBtn');
        if(exportBtn)exportBtn.href=BASE_URL+'/actions/national/export-etudiants.php?'+q.toString();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

$('yearFilter').addEventListener('change',load);
$('etabFilter').addEventListener('change',load);
['provinceFilter','facultyFilter','departmentFilter','promotionFilter'].forEach(id=>$(id).addEventListener('change',load));
load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

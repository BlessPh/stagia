<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/document-branding.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));
$brand=stagiaDocumentBrand($pdo,$eid);
$pageTitle='Modèles de documents';$activePage='stage-documents-models';require_once __DIR__.'/../../includes/app-header.php';
?>
<style>.doc-brand-card{background:#fff;border:1px solid #e5eaf0;border-radius:16px;padding:18px;display:flex;gap:18px;align-items:center}.doc-brand-card img{width:92px;height:72px;object-fit:contain;border:1px solid #eef2f7;border-radius:12px;background:#fff}.doc-template-card{background:#fff;border:1px solid #e5eaf0;border-radius:16px;padding:20px;height:100%;transition:.2s}.doc-template-card:hover{box-shadow:0 8px 24px rgba(15,23,42,.08);transform:translateY(-2px)}.doc-template-icon{width:54px;height:54px;border-radius:14px;background:#fff3e8;color:#f97316;display:flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:14px}</style>
<main class="dashboard-content"><div class="stagia-page-head"><div><h1>Modèles de documents</h1><p>Documents imprimables avec logo de l’établissement et filigrane STAGIA-RDC.</p></div><a href="<?=BASE_URL?>/views/documents/archives.php" class="btn btn-light border"><i class="bi bi-folder2-open me-1"></i>Archives documents</a></div>
<div class="doc-brand-card mb-4"><img src="<?=stagiaDocEsc($brand['logo_url'])?>" alt="Logo établissement"><div><h5 class="mb-1"><?=stagiaDocEsc($brand['name'])?></h5><div class="text-muted small"><?=stagiaDocEsc($brand['address'])?></div><div class="text-muted small"><?=stagiaDocEsc($brand['phone'])?> <?= $brand['email']?' · '.stagiaDocEsc($brand['email']):'' ?> <?= $brand['website']?' · '.stagiaDocEsc($brand['website']):'' ?></div><small class="text-muted">Le logo affiché ici sera utilisé comme logo principal des documents.</small></div></div>
<div class="stagia-list-card mb-4"><div class="stagia-list-toolbar"><div><h5 class="mb-1">Stage concerné</h5><small class="text-muted">Sélectionnez l’étudiant/stage à utiliser pour remplir automatiquement les documents.</small></div><button class="btn btn-sm btn-light border" id="reloadCtx"><i class="bi bi-arrow-clockwise me-1"></i>Actualiser la liste</button></div><div class="p-3"><select id="contextId" class="form-select"><option value="">Chargement...</option></select></div></div>
<div class="row g-3" id="docCards"></div></main>
<script>
document.addEventListener('DOMContentLoaded',()=>{const BASE='<?=BASE_URL?>',CSRF='<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const docs=<?=json_encode((function(){
    $r=strtoupper((string)($_SESSION['role_code']??''));
    $d=[
        ['presence','bi-calendar-check','Fiche de présence de l’étudiant','Tableau de présence rempli automatiquement avec les pointages.'],
        ['appreciation','bi-clipboard-check','Fiche d’appréciation du stagiaire','Notes et appréciations issues de l’évaluation validée.']
    ];
    if(in_array($r,['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],true))
        $d[]=['recommandation','bi-envelope-paper','Lettre de recommandation de stage','Lettre officielle avec entête académique.'];
    $d[]=['rapport','bi-file-earmark-bar-graph','Rapport / certificat final','Synthèse présences, journaux, évaluation et décision finale.'];
    return $d;
})(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
$('docCards').innerHTML=docs.map(d=>`<div class="col-md-6 col-xl-3"><div class="doc-template-card"><div class="doc-template-icon"><i class="bi ${d[1]}"></i></div><h5>${esc(d[2])}</h5><p class="text-muted small">${esc(d[3])}</p><button class="btn btn-primary-stagia w-100 open-doc" data-type="${d[0]}"><i class="bi bi-printer me-1"></i>Ouvrir / imprimer</button></div></div>`).join('');
function archiveType(t){return {presence:'PRESENCE',appreciation:'APPRECIATION',recommandation:'RECOMMANDATION',rapport:'RAPPORT_FINAL'}[t]||String(t).toUpperCase();}
async function loadCtx(){try{const r=await STAGIA.request(BASE+'/actions/documents/stage-document-context-list.php');const list=r.data.items||[];$('contextId').innerHTML='<option value="">Sélectionner un étudiant / stage...</option>'+list.map(x=>`<option value="${x.id||x.assignment_id}">${esc([x.student_name,x.stagia_code,x.campaign_title,x.host_name,x.unit_name].filter(Boolean).join(' — '))}</option>`).join('');if(!list.length)$('contextId').innerHTML='<option value="">Aucun stage disponible</option>';}catch(e){$('contextId').innerHTML='<option value="">Erreur de chargement</option>';STAGIA.toast(e.message,'danger');}}
async function registerDoc(type,ctx){try{const f=new FormData();f.append('csrf',CSRF);f.append('type',archiveType(type));f.append('context_id',ctx);await STAGIA.post(BASE+'/actions/documents/document-archive-register.php',f);}catch(e){console.warn(e.message);}}
document.querySelectorAll('.open-doc').forEach(b=>b.onclick=async()=>{const type=b.dataset.type,ctx=$('contextId').value;if(!ctx){STAGIA.toast('Sélectionnez d’abord un étudiant / stage.','danger');return;}await registerDoc(type,ctx);window.open(`${BASE}/views/documents/print-stage-document.php?type=${encodeURIComponent(type)}&context_id=${encodeURIComponent(ctx)}&assignment_id=${encodeURIComponent(ctx)}`,'_blank');});
$('reloadCtx').onclick=loadCtx;loadCtx();});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

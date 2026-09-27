<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

requirePermission($pdo,'campaign.university.view');
if(!contextAcademicEnabled()){http_response_code(403);exit("Cet espace n'est pas un établissement de formation.");}
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle="Structures d’accueil partenaires";
$activePage='stages-d4-partenaires';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.partner-card,.partner-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 2px 8px rgba(15,23,42,.04)}
.partner-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
.partner-kpi{position:relative;padding:18px 20px;min-height:105px;overflow:hidden}
.partner-kpi small{display:block;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;margin-bottom:8px}
.partner-kpi strong{display:block;color:#0f172a;font-size:30px;line-height:1}
.partner-kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:#f97316}
.partner-card{overflow:hidden}
.partner-card .table-responsive{width:100%;max-width:none!important;overflow-x:auto}
.partner-table{width:100%!important;max-width:none!important;min-width:850px;margin:0!important;table-layout:auto}
.partner-table thead th{background:#f8fafc!important;color:#64748b!important;font-size:11px!important;font-weight:700!important;padding:13px 18px!important;white-space:nowrap;border-bottom:1px solid #e5e7eb}
.partner-table tbody td{padding:15px 18px!important;vertical-align:middle;border-bottom:1px solid #eef2f7}
.partner-table tbody tr:last-child td{border-bottom:0}
.partner-table tbody tr:hover{background:#fffaf5}
.partner-actions{white-space:nowrap;text-align:right}
#solicitModal .modal-content{border:0;border-radius:16px;overflow:hidden}
#solicitModal .modal-header{background:#fffaf5;border-bottom:1px solid #fed7aa}
@media(max-width:900px){.partner-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:576px){.partner-kpis{grid-template-columns:1fr}}
</style>

<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Structures d’accueil partenaires</h1><p>Suivez les sollicitations envoyées, les réponses reçues et les capacités proposées.</p></div>
</div>

<div class="alert alert-light border">
    <i class="bi bi-info-circle text-primary me-1"></i>
    L’université peut solliciter des structures d’accueil pour <strong>tous les types de stage</strong>.
    La capacité réelle reste définie par la structure d’accueil.
</div>

<div class="partner-card p-3 mb-4">
    <label class="form-label">Session universitaire</label>
    <select id="campaign" class="form-select"></select>
</div>

<div class="partner-kpis mb-4">
    <div class="partner-kpi"><small>Minimum requis</small><strong id="kMinimum">0</strong></div>
    <div class="partner-kpi"><small>Structures sollicitées</small><strong id="kSolicited">0</strong></div>
    <div class="partner-kpi"><small>Offres reçues</small><strong id="kOffers">0</strong></div>
    <div class="partner-kpi"><small>Offres retenues</small><strong id="kFinalized">0</strong></div>
</div>

<div class="partner-card mb-4">
<div class="p-3 border-bottom">
    <h5 class="mb-1">Structures disponibles</h5>
    <small class="text-muted">Les structures déjà sélectionnées lors de la création de la session apparaissent comme sollicitées.</small>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0 partner-table">
<thead><tr><th>STRUCTURE</th><th>LOCALISATION</th><th>ÉTAT</th><th class="text-end">ACTION</th></tr></thead>
<tbody id="hospitalRows"><tr><td colspan="4" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>

<div class="partner-card">
<div class="p-3 border-bottom">
    <h5 class="mb-1">Sollicitations et réponses</h5>
    <small class="text-muted">Après avoir retenu une offre, vous pouvez ouvrir la session aux étudiants depuis la colonne Action.</small>
</div>
<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0 partner-table">
<thead><tr>
    <th>STRUCTURE</th><th>BESOIN SOUHAITÉ</th><th>OFFRE D’ACCUEIL</th>
    <th>ENGAGEMENT RETENU</th><th>PÉRIODE</th><th>STATUT</th><th class="text-end">ACTION</th>
</tr></thead>
<tbody id="participationRows"><tr><td colspan="7" class="text-center py-5 text-muted">Chargement...</td></tr></tbody>
</table>
</div>
</div>
</main>

<div class="modal fade" id="solicitModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
<form id="solicitForm">
<div class="modal-header">
    <div><h5 class="modal-title">Solliciter une structure</h5><small class="text-muted" id="hospitalName"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
<input type="hidden" name="campaign_id" id="solCampaign">
<input type="hidden" name="host_etablissement_id" id="solHost">

<div class="mb-3">
    <label class="form-label">Volume souhaité *</label>
    <input type="number" min="1" name="capacite_demandee" class="form-control" required>
    <small class="text-muted">Ce volume représente le besoin de l’université, pas une capacité imposée à la structure.</small>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Début souhaité</label>
        <input type="date" name="date_debut" id="solStart" class="form-control">
    </div>
    <div class="col-md-6">
        <label class="form-label">Fin souhaitée</label>
        <input type="date" name="date_fin" id="solEnd" class="form-control">
    </div>
</div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button class="btn btn-primary-stagia" id="solicitBtn">Envoyer la sollicitation</button>
</div>
</form>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape,modal=new bootstrap.Modal($('solicitModal'));
let data={campaigns:[],hospitals:[],participations:[],permissions:{}};

function badge(s){
    const m={SOLLICITEE:['Sollicitée','secondary'],EN_ETUDE:['En étude','warning'],ACCEPTEE:['Acceptée','success'],REFUSEE:['Refusée','danger'],ANNULEE:['Annulée','dark'],CLOTUREE:['Clôturée','info']}[s]||[s,'secondary'];
    return `<span class="badge bg-${m[1]}-subtle text-${m[1]}">${esc(m[0])}</span>`;
}
const selectedCampaign=()=>data.campaigns.find(x=>Number(x.id)===Number($('campaign').value));

async function load(){
    const cid=$('campaign').value||'',q=cid?`?campaign_id=${encodeURIComponent(cid)}`:'';
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/stages/d4-partenaires-list.php'+q);data=r.data;
        const selected=String(data.selected_campaign_id||'');

        $('campaign').innerHTML=(data.campaigns||[]).length
            ?data.campaigns.map(c=>`<option value="${c.id}">${esc(c.code)} — ${esc(c.titre)} · ${esc(c.stage_type_libelle||'')} · ${esc(c.statut)}</option>`).join('')
            :'<option value="">Aucune session avec structure d’accueil</option>';

        $('campaign').value=selected;
        const c=selectedCampaign();

        $('kMinimum').textContent=c?.minimum_hospitals||0;
        $('kSolicited').textContent=c?.solicited_count||0;
        $('kOffers').textContent=c?.offers_count||0;
        $('kFinalized').textContent=c?.finalized_count||0;

        $('hospitalRows').innerHTML=(data.hospitals||[]).length?data.hospitals.map(h=>`<tr>
            <td><strong>${esc(h.nom)}</strong><small class="d-block text-muted">${esc(h.code||'')}</small></td>
            <td>${esc([h.ville,h.province].filter(Boolean).join(' · ')||'—')}</td>
            <td>${Number(h.already_solicited)?badge(h.participation_status):'<span class="badge bg-light text-dark border">Disponible</span>'}</td>
            <td class="text-end">
                ${!Number(h.already_solicited)&&data.permissions.solicit&&c?.statut==='EN_PREPARATION'
                    ?`<button class="btn btn-sm btn-outline-primary solicit" data-id="${h.id}"><i class="bi bi-send me-1"></i>Solliciter</button>`:'—'}
            </td>
        </tr>`).join(''):'<tr><td colspan="4" class="text-center py-5 text-muted">Aucune structure disponible.</td></tr>';

        $('participationRows').innerHTML=(data.participations||[]).length?data.participations.map(p=>{
            const canFinalize=!!data.permissions.finalize&&p.statut==='ACCEPTEE'&&Number(p.capacite_proposee)>0&&!Number(p.capacite_acceptee);
            const canOpen=!!data.permissions.open_students&&c?.statut==='EN_PREPARATION'&&p.statut==='ACCEPTEE'&&Number(p.capacite_acceptee)>0;
            const canCancel=!!data.permissions.cancel&&['SOLLICITEE','EN_ETUDE'].includes(p.statut);

            return `<tr>
                <td><strong>${esc(p.host_nom)}</strong><small class="d-block text-muted">${esc(p.host_campaign_title||'Aucune session d’accueil liée')}</small></td>
                <td>${p.capacite_demandee?Number(p.capacite_demandee)+' place(s)':'—'}</td>
                <td>
                    ${p.capacite_proposee?`<strong>${Number(p.capacite_proposee)} place(s)</strong>`:'—'}
                    ${p.conditions?`<small class="d-block text-muted">${esc(p.conditions)}</small>`:''}
                    ${Number(p.frais_requis)===1?`<small class="d-block text-muted">Frais : ${esc(p.montant_frais||'0')} ${esc(p.devise||'')}</small>`:''}
                </td>
                <td>${p.capacite_acceptee?`<strong>${Number(p.capacite_acceptee)} place(s)</strong>`:'—'}</td>
                <td>${esc(p.date_debut||'—')} → ${esc(p.date_fin||'—')}</td>
                <td>${badge(p.statut)}${p.motif_refus?`<small class="d-block text-danger">${esc(p.motif_refus)}</small>`:''}</td>
                <td class="partner-actions">
                    ${canFinalize?`<button class="btn btn-sm btn-outline-success finalize" data-id="${p.id}"><i class="bi bi-check2-circle me-1"></i>Retenir & publier</button>`:''}
                    ${canOpen?`<button class="btn btn-sm btn-primary-stagia open-students" data-id="${p.id}"><i class="bi bi-unlock me-1"></i>Ouvrir aux étudiants</button>`:''}
                    ${canCancel?`<button class="btn btn-sm btn-outline-danger cancel" data-id="${p.id}" title="Annuler la sollicitation"><i class="bi bi-x-circle"></i></button>`:''}
                    ${!canFinalize&&!canOpen&&!canCancel?'—':''}
                </td>
            </tr>`;
        }).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune sollicitation pour cette session.</td></tr>';

        document.querySelectorAll('.solicit').forEach(b=>b.onclick=()=>openSolicit(Number(b.dataset.id)));
        document.querySelectorAll('.finalize').forEach(b=>b.onclick=()=>finalize(Number(b.dataset.id)));
        document.querySelectorAll('.open-students').forEach(b=>b.onclick=()=>openStudents());
        document.querySelectorAll('.cancel').forEach(b=>b.onclick=()=>cancelParticipation(Number(b.dataset.id)));
    }catch(e){STAGIA.toast(e.message,'danger');}
}

function openSolicit(id){
    const h=data.hospitals.find(x=>Number(x.id)===id),c=selectedCampaign();
    if(!h||!c)return;
    $('solicitForm').reset();$('solCampaign').value=c.id;$('solHost').value=h.id;
    $('hospitalName').textContent=h.nom;$('solStart').value=c.date_debut||'';$('solEnd').value=c.date_fin||'';
    modal.show();
}

$('solicitForm').onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('solicitBtn'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/stages/d4-solliciter.php',new FormData(e.currentTarget));
        STAGIA.toast(r.message);modal.hide();await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('solicitBtn'),false);}
};

async function finalize(id){
    const c=selectedCampaign();if(!c)return;
    if(!STAGIA.confirm("Retenir cette offre et ouvrir automatiquement la session aux étudiants éligibles ?"))return;

    const fd=new FormData();fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('id',id);

    try{
        const retained=await STAGIA.post(BASE_URL+'/actions/stages/d4-offre-finaliser.php',fd);

        if(c.statut==='OUVERTE'){
            STAGIA.toast(retained.message+' La session est déjà ouverte aux étudiants.','success');
            await load();return;
        }

        if(c.statut==='EN_PREPARATION'){
            const openFd=new FormData();
            openFd.append('csrf','<?=$_SESSION['csrf']?>');openFd.append('campaign_id',c.id);

            try{
                const opened=await STAGIA.post(BASE_URL+'/actions/stages/d4-campagne-open-students.php',openFd);
                STAGIA.toast(retained.message+' '+opened.message,'success');
            }catch(openError){
                STAGIA.toast(retained.message,'success');
                STAGIA.toast('Offre retenue. Ouverture automatique impossible : '+openError.message,'warning');
            }
        }else STAGIA.toast(retained.message,'success');

        await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

async function openStudents(){
    const c=selectedCampaign();if(!c)return;
    if(!STAGIA.confirm('Ouvrir cette session aux étudiants éligibles ?'))return;

    const fd=new FormData();fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('campaign_id',c.id);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/stages/d4-campagne-open-students.php',fd);
        STAGIA.toast(r.message);await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

async function cancelParticipation(id){
    const reason=prompt("Motif d'annulation :")||'';
    if(!reason.trim())return;

    const fd=new FormData();fd.append('csrf','<?=$_SESSION['csrf']?>');fd.append('id',id);fd.append('reason',reason);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/stages/d4-participation-annuler.php',fd);
        STAGIA.toast(r.message);await load();
    }catch(e){STAGIA.toast(e.message,'danger');}
}

$('campaign').onchange=load;load();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
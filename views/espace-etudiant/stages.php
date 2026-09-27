<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['STAGIAIRE']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$pageTitle='Mes stages';
$activePage='student-stages';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div><h1>Choisir ma structure d’accueil</h1><p>Consultez les structures disponibles pour vos sessions de stage.</p></div>
</div>

<div id="campaignContainer">
    <div class="stagia-list-card p-5 text-center">
        <div class="spinner-border spinner-border-sm me-2"></div>Chargement des sessions...
    </div>
</div>
</main>

<div class="modal fade" id="reserveModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="reserveForm">
<div class="modal-header">
    <div><h5 class="modal-title">Confirmer mon choix</h5><small id="reserveHospital" class="text-muted"></small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
    <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>">
    <input type="hidden" name="campaign_id" id="reserveCampaign">
    <input type="hidden" name="academic_enrollment_id" id="reserveAcademic">
    <input type="hidden" name="participation_id" id="reserveParticipation">

    <div id="reserveInfo" class="alert alert-light border small"></div>

    <div>
        <label class="form-label">Motivation / observation</label>
        <textarea name="motivation" class="form-control" rows="3" maxlength="1000" placeholder="Facultatif..."></textarea>
    </div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button id="reserveSaveBtn" class="btn btn-primary-stagia"><i class="bi bi-check-circle me-1"></i>Réserver ma place</button>
</div>
</form>
</div></div>
</div>

<div class="modal fade" id="successModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<div class="modal-body p-4 text-center">
    <div class="stagia-kpi-icon kpi-green mx-auto mb-3"><i class="bi bi-check-lg"></i></div>
    <h4>Place réservée</h4>
    <p id="reservationMessage" class="text-muted mb-0"></p>
</div>
</div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?=BASE_URL?>',$=id=>document.getElementById(id),esc=STAGIA.escape;
const reserveModal=new bootstrap.Modal($('reserveModal')),successModal=new bootstrap.Modal($('successModal')),form=$('reserveForm');
let campaigns=[];

async function charger(){
    try{
        const r=await STAGIA.request(BASE_URL+'/actions/etudiants/student-stage-options.php');
        campaigns=r.data.campaigns||[];afficher();
    }catch(e){
        $('campaignContainer').innerHTML=`<div class="alert alert-danger">${esc(e.message)}</div>`;
    }
}

function afficher(){
    if(!campaigns.length){
        $('campaignContainer').innerHTML=`<div class="stagia-list-card p-5 text-center text-muted">
            <i class="bi bi-briefcase fs-1 d-block mb-3"></i>
            <h5>Aucune session disponible</h5>
            <p class="mb-0">Vous n'avez actuellement aucune session de stage ouverte.</p>
        </div>`;
        return;
    }

    $('campaignContainer').innerHTML=campaigns.map(c=>`<div class="stagia-list-card mb-4">
        <div class="p-4 border-bottom">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <span class="badge bg-primary mb-2">${esc(c.code)}</span>
                    <h4 class="mb-1">${esc(c.titre)}</h4>
                    <div class="text-muted small">${esc(c.annee_academique)} • ${esc(c.filiere)} • ${esc(c.promotion)}</div>
                </div>
                <div class="text-end"><small class="text-muted">Période</small><div class="fw-semibold">${date(c.date_debut)} au ${date(c.date_fin)}</div></div>
            </div>
        </div>

        <div class="p-4">
            <h6 class="mb-3">Structures d’accueil disponibles</h6>
            ${c.hopitaux.length?`<div class="row g-3">${c.hopitaux.map(h=>`<div class="col-md-6 col-xl-4">
                <div class="border rounded-3 p-3 h-100">
                    <div class="d-flex justify-content-between gap-2 mb-3">
                        <div>
                            <strong>${esc(h.hopital)}</strong>
                            <div class="small text-muted">${esc([h.ville,h.province].filter(Boolean).join(', ')||'-')}</div>
                        </div>
                        <span class="badge ${Number(h.places_disponibles)>0?'bg-success':'bg-danger'}">${Number(h.places_disponibles)} place(s)</span>
                    </div>

                    ${Number(h.frais_requis)?`<div class="small mb-3"><i class="bi bi-cash me-1"></i>Frais : <strong>${Number(h.montant_frais||0)} ${esc(h.devise||'')}</strong></div>`:`<div class="small text-muted mb-3">Aucun frais indiqué</div>`}
                    ${h.conditions?`<div class="small text-muted mb-3">${esc(h.conditions)}</div>`:''}

                    <button type="button" class="btn btn-primary-stagia btn-sm w-100 btn-reserve"
                        data-campaign="${c.campaign_id}" data-academic="${c.academic_enrollment_id}"
                        data-participation="${h.participation_id}" data-name="${esc(h.hopital)}"
                        data-places="${h.places_disponibles}" data-fees="${h.frais_requis}"
                        data-amount="${h.montant_frais||''}" data-currency="${h.devise||''}"
                        ${Number(h.places_disponibles)<=0?'disabled':''}>
                        <i class="bi bi-hospital me-1"></i>${Number(h.places_disponibles)>0?'Choisir cette structure':'Complet'}
                    </button>
                </div>
            </div>`).join('')}</div>`:`<div class="alert alert-light border mb-0">Aucune structure d’accueil disponible pour cette session.</div>`}
        </div>
    </div>`).join('');

    document.querySelectorAll('.btn-reserve').forEach(btn=>btn.onclick=()=>ouvrir(btn));
}

function ouvrir(btn){
    form.reset();
    $('reserveCampaign').value=btn.dataset.campaign;
    $('reserveAcademic').value=btn.dataset.academic;
    $('reserveParticipation').value=btn.dataset.participation;
    $('reserveHospital').textContent=btn.dataset.name;

    let info=`<strong>${esc(btn.dataset.name)}</strong><br>Places actuellement disponibles : <strong>${btn.dataset.places}</strong>`;
    if(Number(btn.dataset.fees))info+=`<hr class="my-2">Frais indiqués : <strong>${esc(btn.dataset.amount)} ${esc(btn.dataset.currency)}</strong>`;
    $('reserveInfo').innerHTML=info;
    reserveModal.show();
}

form.onsubmit=async e=>{
    e.preventDefault();
    const btn=$('reserveSaveBtn');STAGIA.loading(btn,true);
    try{
        const response=await fetch(BASE_URL+'/actions/stages/student-stage-reserve.php',{
            method:'POST',body:new FormData(form),credentials:'same-origin',
            headers:{'X-Requested-With':'XMLHttpRequest'}
        });

        let raw=(await response.text()).replace(/^\uFEFF/,'');
        console.log('REPONSE RESERVATION :',raw);
        if(!raw.trim())throw new Error('Le serveur a retourné une réponse vide. HTTP '+response.status);

        let r;
        try{r=JSON.parse(raw);}
        catch(jsonError){throw new Error('Réponse brute du serveur : '+raw.substring(0,1500));}

        if(!response.ok||r.success!==true)throw new Error(r.message||'Erreur HTTP '+response.status);

        reserveModal.hide();
        let msg='Votre place est réservée jusqu’au '+formatDateTime(r.data.expires_at)+'.';
        if(r.data.frais_requis)msg+=' Un paiement de '+r.data.montant_frais+' '+r.data.devise+' est requis pour poursuivre.';
        $('reservationMessage').textContent=msg;
        successModal.show();
        await charger();
    }catch(e){
        alert(e.message);STAGIA.toast(e.message,'danger');
    }finally{STAGIA.loading(btn,false);}
};

function date(v){
    if(!v)return '-';
    const p=v.substring(0,10).split('-');
    return `${p[2]}/${p[1]}/${p[0]}`;
}
function formatDateTime(v){
    if(!v)return '-';
    return new Date(v.replace(' ','T')).toLocaleString('fr-FR');
}

charger();
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
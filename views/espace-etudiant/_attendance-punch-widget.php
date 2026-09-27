<?php
if(empty($_SESSION['csrf'])){
    $_SESSION['csrf']=bin2hex(random_bytes(32));
}
?>

<div class="stagia-list-card mb-4" id="attendancePunchCard">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center gap-3">
        <div>
            <h5 class="mb-1">
                <i class="bi bi-fingerprint me-1"></i>
                Pointeuse du jour
            </h5>
            <small class="text-muted">
                L’arrivée et le départ sont rattachés automatiquement à votre rotation active.
            </small>
        </div>

        <span class="badge bg-light text-dark" id="punchSource">
            <i class="bi bi-phone me-1"></i>
            MOBILE
        </span>
    </div>

    <div class="p-3">
        <div id="punchLoading" class="text-center py-3">
            <div class="spinner-border spinner-border-sm me-2"></div>
            Vérification de votre rotation...
        </div>

        <div id="punchLocked" class="alert alert-warning d-none mb-0"></div>

        <div id="punchReady" class="d-none">
            <div class="row g-3 align-items-stretch">

                <div class="col-lg-4">
                    <div class="border rounded-3 p-3 h-100">
                        <small class="text-muted">ROTATION ACTIVE</small>
                        <div class="fw-bold mt-1" id="punchUnit">—</div>
                        <small class="text-muted" id="punchPeriod">—</small>
                    </div>
                </div>

                <div class="col-lg-2 col-md-4">
                    <div class="border rounded-3 p-3 h-100">
                        <small class="text-muted">ARRIVÉE</small>
                        <div class="fs-4 fw-bold mt-1" id="punchArrival">--:--</div>
                    </div>
                </div>

                <div class="col-lg-2 col-md-4">
                    <div class="border rounded-3 p-3 h-100">
                        <small class="text-muted">DÉPART</small>
                        <div class="fs-4 fw-bold mt-1" id="punchDeparture">--:--</div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-4">
                    <div class="border rounded-3 p-3 h-100 d-flex flex-column justify-content-center">
                        <button type="button"
                                class="btn btn-primary-stagia w-100"
                                id="punchBtn">
                            Pointer
                        </button>

                        <small class="text-muted text-center mt-2"
                               id="punchHint">
                        </small>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>';
const CSRF='<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>';
const $=id=>document.getElementById(id);

let punch=null;

function frDate(v){
    if(!v)return '—';
    const p=String(v).slice(0,10).split('-');
    return p.length===3?`${p[2]}/${p[1]}/${p[0]}`:v;
}

function time(v){
    return v?String(v).slice(0,5):'--:--';
}

async function loadPunch(){
    try{
        const r=await STAGIA.request(
            BASE_URL+
            '/actions/etudiants/student-attendance-punch-context.php'
        );

        punch=r.data.punch||{};
        renderPunch();

    }catch(e){
        $('punchLoading').classList.add('d-none');
        $('punchLocked').classList.remove('d-none');
        $('punchLocked').innerHTML=
            '<strong>Pointeuse indisponible :</strong> '+
            STAGIA.escape(e.message);
    }
}

function renderPunch(){
    $('punchLoading').classList.add('d-none');

    if(!punch.rotation){
        $('punchReady').classList.add('d-none');
        $('punchLocked').classList.remove('d-none');

        let msg=STAGIA.escape(
            punch.reason||'Aucune rotation active aujourd’hui.'
        );

        if(punch.next_rotation){
            msg+=`<br>
                Prochaine rotation :
                <strong>${STAGIA.escape(punch.next_rotation.unit_name||'')}</strong>
                le <strong>${frDate(punch.next_rotation.date_debut)}</strong>.`;
        }

        $('punchLocked').innerHTML=`
            <i class="bi bi-lock-fill me-1"></i>
            ${msg}
        `;
        return;
    }

    $('punchLocked').classList.add('d-none');
    $('punchReady').classList.remove('d-none');

    $('punchUnit').textContent=
        punch.rotation.unit_name||'—';

    $('punchPeriod').textContent=
        'Rotation '+Number(punch.rotation.sequence_no||0)+
        ' · '+frDate(punch.rotation.date_debut)+
        ' → '+frDate(punch.rotation.date_fin);

    const a=punch.attendance||{};

    $('punchArrival').textContent=time(a.heure_arrivee);
    $('punchDeparture').textContent=time(a.heure_depart);

    const btn=$('punchBtn');

    if(punch.next_action==='ARRIVEE'){
        btn.disabled=false;
        btn.dataset.action='ARRIVEE';
        btn.innerHTML=
            '<i class="bi bi-box-arrow-in-right me-1"></i> Pointer mon arrivée';
        $('punchHint').textContent=
            'La date, l’heure et la rotation sont déterminées automatiquement.';
    }else if(punch.next_action==='DEPART'){
        btn.disabled=false;
        btn.dataset.action='DEPART';
        btn.innerHTML=
            '<i class="bi bi-box-arrow-right me-1"></i> Pointer mon départ';
        $('punchHint').textContent=
            'Votre arrivée a déjà été enregistrée.';
    }else{
        btn.disabled=true;
        btn.dataset.action='';
        btn.innerHTML=
            '<i class="bi bi-check-circle me-1"></i> Pointage terminé';
        $('punchHint').textContent=
            'Arrivée et départ enregistrés pour aujourd’hui.';
    }
}

$('punchBtn').addEventListener('click',async()=>{

    const action=$('punchBtn').dataset.action;

    if(!['ARRIVEE','DEPART'].includes(action)){
        return;
    }

    const label=action==='ARRIVEE'
        ?'Pointer votre arrivée maintenant ?'
        :'Pointer votre départ maintenant ?';

    if(!confirm(label)){
        return;
    }

    const fd=new FormData();
    fd.append('csrf',CSRF);
    fd.append('action',action);

    const btn=$('punchBtn');
    STAGIA.loading(btn,true);

    try{
        const r=await STAGIA.post(
            BASE_URL+
            '/actions/etudiants/student-attendance-punch.php',
            fd
        );

        punch=r.data.punch||{};
        STAGIA.toast(r.message);
        renderPunch();

        /*
         * Le tableau "Historique des présences" est géré
         * par le script de la page existante.
         * On recharge la page pour actualiser aussi ses KPI.
         */
        setTimeout(()=>window.location.reload(),500);

    }catch(e){
        STAGIA.toast(e.message,'danger');
    }finally{
        STAGIA.loading(btn,false);
    }
});

loadPunch();

});
</script>

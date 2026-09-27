<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Vérification publique d'un étudiant reconnu dans STAGIA-RDC">
<title>Vérifier un étudiant | STAGIA-RDC</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">

<style>
body{background:#f7f8fa}
.institution{background:linear-gradient(90deg,#160900,#ff751f);color:#fff;padding:7px 0;font-size:12px}
.public-nav{background:#000;border-top:3px solid #ff751f;padding:14px 0}
.public-nav img{width:110px}
.public-nav a{color:#fff;text-decoration:none}
.verify-main{min-height:calc(100vh - 150px);padding:55px 15px}
.verify-card{max-width:900px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-top:5px solid #ff751f;border-radius:20px;box-shadow:0 20px 55px #0000000d;overflow:hidden}
.verify-head{text-align:center;padding:30px 32px 20px}
.verify-head img{width:115px;margin-bottom:12px}
.verify-head h1{font-size:30px;font-weight:850}
.verify-head p{color:#718096;max-width:690px;margin:auto}
.verify-body{padding:0 32px 34px}
.verify-box{background:#fafafa;border:1px solid #e7e9ed;border-radius:14px;padding:22px}
.form-label{font-weight:700}
.verify-btn{min-height:52px;border:0;border-radius:10px;background:linear-gradient(120deg,#111,#ff751f);color:#fff;font-weight:750}
.result{display:none;margin-top:22px;padding:22px;border-radius:14px}
.result.ok{display:block;background:#effbf4;border:1px solid #bce8ce}
.result.no{display:block;background:#fff7f2;border:1px solid #ffd0b2}
.ricon{width:46px;height:46px;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:22px;flex:none}
.ok .ricon{background:#d9f5e4;color:#198754}
.no .ricon{background:#ffe9dc;color:#e96500}
.identity-box{margin-top:16px;padding:16px;border-radius:12px;background:#fff;border:1px solid #d9eadf}
.identity-name{font-size:20px;font-weight:800;color:#1f2937}
.attendance-title{margin-top:26px;margin-bottom:10px;font-weight:800}
.today-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.today-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px}
.today-card small{display:block;color:#64748b;margin-bottom:4px}
.today-card strong{font-size:14px}
.privacy{margin-top:20px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;color:#64748b;font-size:13px}
@media(max-width:720px){.today-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:576px){.verify-body,.verify-head{padding-left:18px;padding-right:18px}}
</style>
</head>

<body>

<div class="institution">
    <div class="container d-flex justify-content-between">
        <span>République Démocratique du Congo</span>
        <span>Portail National des Stages</span>
    </div>
</div>

<nav class="public-nav">
    <div class="container d-flex justify-content-between align-items-center">
        <a href="index.php">
            <img src="assets/img/logo.png" alt="STAGIA-RDC">
        </a>

        <div class="d-flex gap-3 align-items-center">
            <a href="index.php"><i class="bi bi-arrow-left me-1"></i>Accueil</a>
            <a href="login.php" class="btn btn-outline-light btn-sm">Se connecter</a>
        </div>
    </div>
</nav>

<main class="verify-main">
<div class="verify-card">

    <div class="verify-head">
        <img src="assets/img/logo.png" alt="STAGIA-RDC">

        <h1>Vérifier un étudiant</h1>

        <p>
            Parent ou étudiant : saisissez simplement le matricule
            pour vérifier l'inscription et consulter les présences
            du jour et de la semaine en cours.
        </p>
    </div>

    <div class="verify-body">

        <div class="verify-box">

            <form id="verifyForm">

                <div class="mb-3">
                    <label class="form-label">Matricule</label>

                    <input
                        id="matricule"
                        class="form-control form-control-lg"
                        maxlength="100"
                        placeholder="Ex. STG-ETU-00000009"
                        autocomplete="off"
                        required
                    >
                </div>

                <button class="verify-btn w-100" type="submit">
                    <i class="bi bi-search me-2"></i>
                    Vérifier
                </button>

            </form>

        </div>

        <div class="result" id="result"></div>

        <div class="privacy">
            <i class="bi bi-shield-lock me-1"></i>
            Cette page affiche uniquement le nom complet de l'étudiant reconnu,
            son établissement et ses présences du jour et de la semaine en cours.
            Les notes, évaluations, documents, téléphone et e-mail ne sont pas affichés.
        </div>

    </div>

</div>
</main>

<script>
const endpoint='actions/public/student-verify.php';
const $=id=>document.getElementById(id);
const result=$('result');

$('verifyForm').onsubmit=
async e=>{

    e.preventDefault();

    const btn=e.submitter;
    const old=btn.innerHTML;

    btn.disabled=true;
    btn.innerHTML=
        '<span class="spinner-border spinner-border-sm me-2"></span>Vérification...';

    result.className='result';
    result.innerHTML='';

    const data=
        new FormData();

    data.append(
        'matricule',
        $('matricule').value
    );

    try{

        const response=
            await fetch(
                endpoint,
                {
                    method:'POST',
                    body:data,
                    credentials:'same-origin',
                    headers:{
                        'X-Requested-With':'XMLHttpRequest'
                    }
                }
            );

        const j=
            await response.json();

        if(!j.success)
            throw new Error(j.message);

        if(!j.data.found){

            result.className='result no';

            result.innerHTML=`
                <div class="d-flex gap-3">
                    <div class="ricon">
                        <i class="bi bi-exclamation-circle"></i>
                    </div>

                    <div>
                        <h5>Étudiant non reconnu</h5>
                        <p class="mb-0">${escapeHtml(j.message)}</p>
                    </div>
                </div>
            `;

            return;
        }

        const today=
            j.data.today;

        const todayHtml=
            today
            ?`
                <div class="today-grid">

                    <div class="today-card">
                        <small>Date</small>
                        <strong>${formatDate(today.date_presence)}</strong>
                    </div>

                    <div class="today-card">
                        <small>Statut</small>
                        <strong>${escapeHtml(statusLabel(today.statut))}</strong>
                    </div>

                    <div class="today-card">
                        <small>Heure d'arrivée</small>
                        <strong>${formatTime(today.heure_arrivee)}</strong>
                    </div>

                    <div class="today-card">
                        <small>Heure de sortie</small>
                        <strong>${formatTime(today.heure_depart)}</strong>
                    </div>

                </div>
            `
            :`
                <div class="alert alert-light border mb-0">
                    Aucun pointage enregistré aujourd'hui.
                </div>
            `;

        const rows=
            (j.data.week||[])
            .map(
                x=>`
                    <tr>
                        <td>${formatDate(x.date_presence)}</td>
                        <td>${escapeHtml(statusLabel(x.statut))}</td>
                        <td>${formatTime(x.heure_arrivee)}</td>
                        <td>${formatTime(x.heure_depart)}</td>
                    </tr>
                `
            ).join('');

        const weekHtml=
            rows
            ?`
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Arrivée</th>
                                <th>Sortie</th>
                            </tr>
                        </thead>

                        <tbody>${rows}</tbody>
                    </table>
                </div>
            `
            :`
                <div class="text-muted">
                    Aucune présence enregistrée cette semaine.
                </div>
            `;

        result.className='result ok';

        result.innerHTML=`

            <div class="d-flex gap-3">

                <div class="ricon">
                    <i class="bi bi-patch-check"></i>
                </div>

                <div class="flex-grow-1">

                    <h5 class="mb-1">
                        Étudiant reconnu dans STAGIA-RDC
                    </h5>

                    <div class="identity-box">

                        <small class="text-muted">
                            Étudiant
                        </small>

                        <div class="identity-name">
                            ${escapeHtml(j.data.full_name||'-')}
                        </div>

                        <div class="small mt-1">
                            <strong>Établissement :</strong>
                            ${escapeHtml(j.data.etablissement_nom||'-')}
                        </div>

                        <div class="small">
                            <strong>Statut :</strong>
                            Inscription reconnue
                        </div>

                    </div>

                    <div class="attendance-title">
                        Présence du jour
                    </div>

                    ${todayHtml}

                    <div class="attendance-title">
                        Présences de la semaine
                    </div>

                    ${weekHtml}

                </div>

            </div>
        `;

    }catch(e){

        result.className='result no';

        result.innerHTML=`
            <div class="d-flex gap-3">

                <div class="ricon">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>

                <div>
                    <h5>Vérification impossible</h5>
                    <p class="mb-0">${escapeHtml(e.message||'Erreur inconnue.')}</p>
                </div>

            </div>
        `;

    }finally{

        btn.disabled=false;
        btn.innerHTML=old;
    }
};

function statusLabel(v){

    return {
        PRESENT:'Présent',
        RETARD:'Retard',
        ABSENT:'Absent',
        JUSTIFIE:'Justifié',
        GARDE:'Garde'
    }[v]
    ||v
    ||'-';
}

function formatDate(v){

    if(!v)
        return '-';

    const p=
        String(v)
        .substring(0,10)
        .split('-');

    return p.length===3
        ?`${p[2]}/${p[1]}/${p[0]}`
        :v;
}

function formatTime(v){

    return v
        ?String(v).substring(0,5)
        :'-';
}

function escapeHtml(v){

    const d=
        document.createElement('div');

    d.textContent=
        String(v??'');

    return d.innerHTML;
}
</script>

</body>
</html>

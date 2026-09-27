<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$etablissementId=currentEtablissementId($pdo);
if(!$etablissementId) exit('Aucun établissement associé.');
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

/* Référentiels */
$stmt=$pdo->prepare("SELECT id,libelle FROM annees_academiques WHERE etablissement_id=? AND actif=1 ORDER BY date_debut DESC");
$stmt->execute([$etablissementId]); $annees=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT p.id,p.nom,f.nom filiere_nom FROM promotions p
                     JOIN filieres f ON f.id=p.filiere_id
                     WHERE p.etablissement_id=? AND p.actif=1 ORDER BY f.nom,p.nom");
$stmt->execute([$etablissementId]); $promotions=$stmt->fetchAll();

$pageTitle='Inscrire un étudiant'; $activePage='etudiants-inscrire';
require_once __DIR__.'/../../includes/app-header.php';
?>

<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <a href="index.php" class="detail-back"><i class="bi bi-arrow-left"></i> Étudiants</a>
        <h1>Inscrire un étudiant</h1>
        <p>Recherchez d'abord l’étudiant dans STAGIA avant de créer un nouveau profil.</p>
    </div>
</div>

<div class="stagia-list-card p-4">

<!-- Choix -->
<div class="stagia-tabs mb-4">
    <button type="button" class="stagia-tab active" data-mode="existing"><i class="bi bi-search me-1"></i> Étudiant STAGIA existant</button>
    <button type="button" class="stagia-tab" data-mode="new"><i class="bi bi-person-plus me-1"></i> Nouveau profil</button>
</div>

<form id="studentForm">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="mode" id="mode" value="existing">
<input type="hidden" name="student_id" id="studentId">

<!-- Recherche étudiant existant -->
<div id="existingBlock">
    <label class="form-label">Rechercher dans STAGIA</label>
    <div class="input-group">
        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
        <input id="studentSearch" class="form-control" placeholder="Identifiant STAGIA, nom, email ou téléphone...">
    </div>
    <div id="searchResults" class="mt-2"></div>
</div>

<!-- Nouveau profil -->
<div id="newBlock" class="d-none">
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Nom *</label><input name="nom" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Postnom</label><input name="postnom" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Prénom</label><input name="prenom" class="form-control"></div>
        <div class="col-md-3"><label class="form-label">Sexe</label><select name="sexe" class="form-select"><option value="">-</option><option value="M">Masculin</option><option value="F">Féminin</option></select></div>
        <div class="col-md-3"><label class="form-label">Naissance</label><input type="date" name="date_naissance" class="form-control"></div>
        <div class="col-md-3"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control"></div>
        <div class="col-md-3"><label class="form-label">Téléphone</label><input name="telephone" class="form-control"></div>
    </div>
</div>

<hr class="my-4">

<!-- Données propres à l'université -->
<h6 class="mb-3">Inscription dans votre établissement</h6>

<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">Matricule *</label>
        <input name="matricule" class="form-control" required>
    </div>

    <div class="col-md-4">
        <label class="form-label">E-mail institutionnel</label>
        <input type="email" name="email_institutionnel" class="form-control">
    </div>

    <div class="col-md-4">
        <label class="form-label">Date d'inscription</label>
        <input type="date" name="date_inscription" class="form-control">
    </div>

    <div class="col-md-6">
        <label class="form-label">Année académique *</label>
        <select name="annee_academique_id" class="form-select" required>
            <option value="">Sélectionner...</option>
            <?php foreach($annees as $a): ?><option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['libelle']) ?></option><?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-6">
        <label class="form-label">Promotion *</label>
        <select name="promotion_id" class="form-select" required>
            <option value="">Sélectionner...</option>
            <?php foreach($promotions as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['filiere_nom'].' — '.$p['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="text-end mt-4">
    <button type="submit" id="saveBtn" class="btn btn-primary-stagia px-4">
        <i class="bi bi-check-lg me-1"></i> Inscrire l'étudiant
    </button>
</div>
</form>
</div>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{

const BASE_URL='<?= BASE_URL ?>',$=id=>document.getElementById(id),
      form=$('studentForm');
let timer=null;

/* Choisir étudiant existant / nouveau */
document.querySelectorAll('.stagia-tab[data-mode]').forEach(tab=>tab.onclick=()=>{
    document.querySelectorAll('.stagia-tab[data-mode]').forEach(t=>t.classList.remove('active'));
    tab.classList.add('active'); $('mode').value=tab.dataset.mode; $('studentId').value='';
    $('existingBlock').classList.toggle('d-none',tab.dataset.mode!=='existing');
    $('newBlock').classList.toggle('d-none',tab.dataset.mode!=='new');
});

/* Recherche globale STAGIA */
$('studentSearch').oninput=e=>{
    clearTimeout(timer);
    timer=setTimeout(async()=>{
        const q=e.target.value.trim(),box=$('searchResults');
        if(q.length<2){box.innerHTML='';return;}

        try{
            const r=await STAGIA.request(BASE_URL+'/actions/etudiants/student-search.php?q='+encodeURIComponent(q));
            const items=r.data.items||[];

            box.innerHTML=!items.length
                ? '<div class="text-muted small p-2">Aucun étudiant trouvé.</div>'
                : items.map(s=>`<button type="button" class="list-group-item list-group-item-action student-result ${Number(s.deja_rattache)?'disabled':''}" data-id="${s.id}">
                    <div class="d-flex justify-content-between">
                        <div><strong>${STAGIA.escape(s.nom+' '+(s.postnom||'')+' '+(s.prenom||''))}</strong><br>
                        <small>${STAGIA.escape(s.stagia_code||'-')}</small></div>
                        <span class="badge ${Number(s.deja_rattache)?'bg-warning text-dark':'bg-success'}">${Number(s.deja_rattache)?'Déjà inscrit':'Disponible'}</span>
                    </div>
                </button>`).join('');

            box.className='mt-2 list-group';
            box.querySelectorAll('.student-result:not(.disabled)').forEach(b=>b.onclick=()=>{
                $('studentId').value=b.dataset.id;
                box.querySelectorAll('.student-result').forEach(x=>x.classList.remove('active'));
                b.classList.add('active');
                STAGIA.toast('Étudiant STAGIA sélectionné.');
            });
        }catch(e){STAGIA.toast(e.message,'danger');}
    },300);
};

/* Enregistrer */
form.onsubmit=async e=>{
    e.preventDefault();

    if($('mode').value==='existing' && !$('studentId').value){
        STAGIA.toast('Sélectionnez d’abord un étudiant STAGIA.','warning'); return;
    }

    STAGIA.loading($('saveBtn'),true);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/etudiants/student-enroll-store.php',form);
        STAGIA.toast(r.message); form.reset(); $('studentId').value=''; $('searchResults').innerHTML='';
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('saveBtn'),false);}
};

});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>
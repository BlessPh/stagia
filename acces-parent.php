<?php
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
requireRole(['STAGIAIRE']);
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Accès parent | STAGIA-RDC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<style>
body{background:#f7f8fa}.wrap{max-width:720px;margin:55px auto;padding:0 15px}.cardx{background:#fff;border:1px solid #e5e7eb;border-top:5px solid #ff751f;border-radius:18px;padding:28px;box-shadow:0 18px 45px #0000000d}
.code{font-size:34px;letter-spacing:.18em;font-weight:900;color:#e96500;background:#fff7f2;border:1px dashed #ffb37f;border-radius:12px;padding:18px;text-align:center}
</style>
</head>
<body>
<div class="wrap">
<a href="verifier-etudiant.php" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Retour à la vérification</a>
<div class="cardx mt-3">
<h2>Accès parent / tuteur</h2>
<p class="text-muted">Générez un code temporaire pour permettre à votre parent ou tuteur de consulter uniquement vos présences du jour et de la semaine.</p>
<div class="alert alert-light border"><i class="bi bi-shield-lock me-1"></i>Le code expire après 30 jours. Vous pouvez le révoquer ou en générer un nouveau à tout moment.</div>
<div id="status" class="mb-3"></div>
<div id="codeBox" class="code d-none"></div>
<div class="d-flex gap-2 flex-wrap mt-3">
<button id="generate" class="btn btn-primary"><i class="bi bi-key me-1"></i>Générer un code</button>
<button id="revoke" class="btn btn-outline-danger d-none"><i class="bi bi-x-circle me-1"></i>Révoquer l’accès</button>
<a href="dashboard.php" class="btn btn-light border">Retour à mon espace</a>
</div>
</div>
</div>
<script>
const BASE='<?= BASE_URL ?>',CSRF='<?= $_SESSION['csrf'] ?>',$=id=>document.getElementById(id);
async function load(){
 try{
  const j=await(await fetch(BASE+'/actions/etudiants/student-parent-access.php',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
  if(!j.success)throw new Error(j.message);
  $('status').innerHTML=j.data.active?`<div class="alert alert-success mb-0">Un code parent est actif jusqu’au <strong>${fd(j.data.access.expires_at)}</strong>. Pour des raisons de sécurité, le code existant n’est pas réaffiché : générez-en un nouveau si vous l’avez perdu.</div>`:'<div class="alert alert-secondary mb-0">Aucun code parent actif.</div>';
  $('revoke').classList.toggle('d-none',!j.data.active);
 }catch(e){$('status').innerHTML=`<div class="alert alert-danger">${e.message}</div>`}
}
async function act(action){
 const d=new FormData();d.append('csrf',CSRF);d.append('action',action);
 try{
  const j=await(await fetch(BASE+'/actions/etudiants/student-parent-access.php',{method:'POST',body:d,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})).json();
  if(!j.success)throw new Error(j.message);
  if(j.data?.code){$('codeBox').textContent=j.data.code;$('codeBox').classList.remove('d-none');$('status').innerHTML='<div class="alert alert-warning mb-0">Copiez ce code maintenant : il ne sera plus affiché ensuite.</div>'}
  else{$('codeBox').classList.add('d-none');$('status').innerHTML='<div class="alert alert-success mb-0">'+j.message+'</div>'}
  await load();
 }catch(e){alert(e.message)}
}
$('generate').onclick=()=>act('GENERATE');$('revoke').onclick=()=>confirm('Révoquer immédiatement l’accès parent ?')&&act('REVOKE');
function fd(v){if(!v)return'-';const d=new Date(String(v).replace(' ','T'));return isNaN(d)?v:d.toLocaleDateString('fr-FR')}
load();
</script>
</body></html>

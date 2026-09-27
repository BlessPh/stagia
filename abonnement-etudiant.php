<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/settings.php';
require_once __DIR__.'/includes/payment/activation-subscription.php';

if(empty($_SESSION['user_id'])){
    header('Location: '.BASE_URL.'/login.php');
    exit;
}

$stmt=$pdo->prepare("SELECT u.id,u.nom,u.postnom,u.prenom,u.identifiant,u.email,u.telephone,sp.stagia_code FROM users u JOIN roles r ON r.id=u.role_id AND r.code='STAGIAIRE' JOIN student_profiles sp ON sp.user_id=u.id WHERE u.id=? AND u.actif=1 LIMIT 1");
$stmt->execute([(int)$_SESSION['user_id']]);
$student=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$student){
    http_response_code(403);
    exit('Accès non autorisé.');
}

if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$message='';
$messageType='danger';
$maishapayResponse=null;
$pendingPayment=null;
$pdo->prepare("UPDATE student_activation_subscriptions SET statut='EXPIRE' WHERE user_id=? AND statut='EN_ATTENTE' AND created_at<DATE_SUB(NOW(),INTERVAL 2 MINUTE)")->execute([(int)$student['id']]);
$pendingStmt=$pdo->prepare("SELECT reference,created_at FROM student_activation_subscriptions WHERE user_id=? AND statut='EN_ATTENTE' AND created_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) ORDER BY id DESC LIMIT 1");
$pendingStmt->execute([(int)$student['id']]);
$pendingPayment=$pendingStmt->fetch(PDO::FETCH_ASSOC)?:null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))){
        $message='Jeton de sécurité invalide.';
    }else{
        try{
            $payment=initiateActivationSubscription($pdo,(int)$student['id'],strtoupper(trim($_POST['provider']??'')),trim($_POST['wallet_phone']??''));
            $message=$payment['status']==='SUCCESS'
                ?'Paiement confirmé. Votre abonnement est renouvelé pour un an.'
                :'Demande de paiement envoyée. Confirmez-la sur votre téléphone.';
            $messageType='success';
            if($payment['status']==='PENDING')$pendingPayment=$payment;
        }catch(Throwable $e){
            $message=$e->getMessage();
            $maishapayResponse=lastActivationSubscriptionResponse($pdo,(int)$student['id']);
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Réabonnement étudiant | STAGIA-RDC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<style>
.subscription-page{background:linear-gradient(135deg,#fff 0%,#fff9f5 48%,#fff 100%)}
.subscription-card{border:1px solid #ebe3dd;border-radius:8px;box-shadow:0 18px 48px rgba(47,25,11,.08);overflow:hidden}
.subscription-head{padding:22px 24px 18px;text-align:center;border-bottom:1px solid #f1ebe7}
.subscription-head .login-logo{margin-bottom:8px}.subscription-head h2{font-size:24px;margin:0 0 4px}.subscription-body{padding:22px 24px 26px}
.student-summary{background:#fffaf6;border:1px solid #f1dfd1;border-radius:8px;padding:14px 16px}.student-summary strong{font-size:15px}.student-summary .text-muted{line-height:1.55}
.subscription-fee{display:flex;align-items:center;justify-content:space-between;gap:16px;background:#111;border:1px solid #111;border-radius:8px;padding:14px 16px;color:#fff}.subscription-fee small{color:#d7d7d7}.subscription-fee strong{color:#ff8e48;font-size:18px}
.provider-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.provider-option{position:relative;margin:0}.provider-option input{position:absolute;opacity:0;pointer-events:none}.provider-card{height:92px;border:1px solid #e5ded9;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;position:relative;cursor:pointer;overflow:hidden;transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease}.provider-card:hover{border-color:#ff751f;transform:translateY(-1px)}.provider-option input:checked+.provider-card{border:2px solid #ff751f;box-shadow:0 0 0 3px #ff751f24}.provider-option input:focus-visible+.provider-card{outline:3px solid #ff751f55;outline-offset:2px}.provider-card img{width:100%;height:100%;object-fit:contain;padding:10px}.provider-fallback{font-weight:700;color:#2e2e2e;font-size:14px}.provider-option input:checked+.provider-card:after{content:"\F26A";font-family:"bootstrap-icons";position:absolute;right:7px;top:6px;width:20px;height:20px;display:grid;place-items:center;border-radius:50%;background:#ff751f;color:#fff;font-size:12px}.phone-help{font-size:12px;color:#747474;margin:7px 0 0}.btn-payment{min-height:48px;font-weight:700;border-radius:8px}.payment-wait{border-radius:8px}.payment-wait #paymentTimer{font-size:28px;letter-spacing:1px;color:#0c5460}.login-return{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:16px;color:#666;font-size:13px;text-decoration:none}.login-return:hover{color:#e85e00;text-decoration:underline}
@media(max-width:575px){.subscription-body{padding:18px}.subscription-head{padding:20px 18px 16px}.provider-grid{grid-template-columns:1fr}.provider-card{height:68px}.subscription-fee{align-items:flex-start;flex-direction:column;gap:4px}}
</style>
</head>
<body class="login-page subscription-page">
<main class="login-main">
<div class="container">
<div class="row justify-content-center align-items-center min-vh-100">
<div class="col-md-7 col-lg-5">
<div class="login-card subscription-card p-0">
<div class="subscription-head">
<div class="login-logo"><i class="bi bi-arrow-repeat"></i></div>
<h2>Renouveler mon abonnement</h2>
<p class="text-muted mb-0"><?= htmlspecialchars($student['stagia_code']?:$student['identifiant'],ENT_QUOTES,'UTF-8') ?></p>
</div>
<div class="subscription-body">
<?php if($pendingPayment!==null): ?>
<div class="alert alert-info text-center payment-wait">
<i class="bi bi-phone-vibrate me-1"></i> Confirmez le paiement sur votre téléphone.<br>
<strong id="paymentTimer">02:00</strong>
</div>
<div id="paymentStatus" class="small text-muted text-center mb-3">Vérification de la confirmation MaishaPay...</div>
<?php elseif($message!==''): ?>
<div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></div>
<?php endif; ?>
<?php if($maishapayResponse!==null): ?>
<script>console.error('Retour MaishaPay (activation étudiant) :',<?= json_encode($maishapayResponse,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>);</script>
<?php endif; ?>
<div class="student-summary mb-3 small">
<div class="fw-semibold mb-2"><i class="bi bi-person-vcard me-1"></i>Étudiant payeur</div>
<div><strong><?= htmlspecialchars(trim($student['nom'].' '.$student['postnom'].' '.$student['prenom']),ENT_QUOTES,'UTF-8') ?></strong></div>
<div class="text-muted mt-1">Code : <?= htmlspecialchars($student['stagia_code']?:$student['identifiant'],ENT_QUOTES,'UTF-8') ?></div>
<?php if(!empty($student['email'])): ?><div class="text-muted">E-mail : <?= htmlspecialchars($student['email'],ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
<?php if(!empty($student['telephone'])): ?><div class="text-muted">Téléphone : <?= htmlspecialchars($student['telephone'],ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
</div>
<div class="subscription-fee mb-4">
<div><strong class="d-block">Abonnement annuel</strong><small>Valable douze (12) mois après confirmation.</small></div>
<strong><?= htmlspecialchars((string)setting($pdo,'student_activation.amount','0'),ENT_QUOTES,'UTF-8') ?> <?= htmlspecialchars((string)setting($pdo,'student_activation.currency','USD'),ENT_QUOTES,'UTF-8') ?></strong>
</div>
<?php if($pendingPayment===null): ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>">
<fieldset class="mb-4">
<legend class="form-label mb-2">Choisissez votre opérateur Mobile Money</legend>
<div class="provider-grid">
<label class="provider-option"><input type="radio" name="provider" value="MPESA" required><span class="provider-card"><img src="<?= BASE_URL ?>/assets/img/partners/mpesa.png" alt="M-Pesa" onerror="this.style.display='none';this.nextElementSibling.hidden=false"><span class="provider-fallback" hidden>M-Pesa</span></span></label>
<label class="provider-option"><input type="radio" name="provider" value="ORANGE" required><span class="provider-card"><img src="<?= BASE_URL ?>/assets/img/partners/orange-money.png" alt="Orange Money" onerror="this.style.display='none';this.nextElementSibling.hidden=false"><span class="provider-fallback" hidden>Orange Money</span></span></label>
<label class="provider-option"><input type="radio" name="provider" value="AIRTEL" required><span class="provider-card"><img src="<?= BASE_URL ?>/assets/img/partners/airtel-money.png" alt="Airtel Money" onerror="this.style.display='none';this.nextElementSibling.hidden=false"><span class="provider-fallback" hidden>Airtel Money</span></span></label>
<label class="provider-option"><input type="radio" name="provider" value="AFRICELL" required><span class="provider-card"><img src="<?= BASE_URL ?>/assets/img/partners/afrimoney.png" alt="Afrimoney" onerror="this.style.display='none';this.nextElementSibling.hidden=false"><span class="provider-fallback" hidden>Afrimoney</span></span></label>
</div>
</fieldset>
</div>
<div class="mb-4">
<label class="form-label">Numéro Mobile Money *</label>
<input type="tel" name="wallet_phone" class="form-control" inputmode="tel" autocomplete="tel" placeholder="0891021363" required>
<p class="phone-help">Le préfixe RDC <strong>+243</strong> sera ajouté automatiquement.</p>
</div>
<button class="btn btn-stagia-login btn-payment w-100"><i class="bi bi-shield-check me-1"></i>Payer mon abonnement</button>
</form>
<?php endif; ?>
<a class="login-return" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-left"></i>Retour à la connexion</a>
</div>
</div>
</div>
</div>
</div>
</main>
<?php if($pendingPayment!==null): ?>
<script>
const reference=<?= json_encode($pendingPayment['reference']) ?>;
const statusUrl=<?= json_encode(BASE_URL.'/activation-payment-status.php?reference='.rawurlencode($pendingPayment['reference'])) ?>;
const redirectUrl=<?= json_encode(BASE_URL.'/dashboard.php') ?>;
const logoutUrl=<?= json_encode(BASE_URL.'/logout.php') ?>;
const timer=document.getElementById('paymentTimer');
const statusLabel=document.getElementById('paymentStatus');
const startedAt=<?= json_encode($pendingPayment['created_at']) ?>;
let remaining=Math.max(0,120-Math.floor((Date.now()-new Date(startedAt.replace(' ','T')).getTime())/1000));

async function checkPayment(){
    try{
        const response=await fetch(statusUrl,{credentials:'same-origin',cache:'no-store'});
        const payment=await response.json();
        if(payment.status==='VALIDE'){
            statusLabel.textContent='Paiement confirmé. Redirection vers votre espace...';
            window.location.replace(redirectUrl);
            return;
        }
        if(payment.status==='ECHOUE'||payment.status==='ANNULE'||payment.status==='EXPIRE'){
            statusLabel.textContent='Le paiement n’a pas été validé. Vous pouvez effectuer une nouvelle demande.';
            window.location.reload();
        }
    }catch(error){
        console.error('Vérification MaishaPay impossible :',error);
    }
}

const interval=window.setInterval(()=>{
    remaining-=1;
    timer.textContent=`${String(Math.floor(remaining/60)).padStart(2,'0')}:${String(remaining%60).padStart(2,'0')}`;
    if(remaining<=0){
        window.clearInterval(interval);
        statusLabel.textContent='Délai d’attente écoulé. Vous allez être déconnecté.';
        window.setTimeout(()=>window.location.replace(logoutUrl),1000);
    }
},1000);

checkPayment();
window.setInterval(checkPayment,5000);
</script>
<?php endif; ?>
</body>
</html>
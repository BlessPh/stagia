<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/certificate-service.php';

$token=trim((string)($_GET['token']??''));
$data=$token!==''?certificateData($pdo,$token):null;
http_response_code($data?200:404);

function vhtml($v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}

function publicMaskName(string $name):string{
    $name=trim(preg_replace('/\s+/u',' ',$name));
    if($name===''||$name==='—')return '—';
    $parts=preg_split('/\s+/u',$name)?:[];
    $out=[];
    foreach($parts as $i=>$p){
        $p=trim($p);
        if($p==='')continue;
        if($i===0){
            $out[]=$p;
            continue;
        }
        $first=mb_substr($p,0,1,'UTF-8');
        $out[]=$first.'.';
    }
    return implode(' ',$out);
}

function publicMaskCode(string $code):string{
    $code=trim($code);
    if($code===''||$code==='—')return '—';
    $len=mb_strlen($code,'UTF-8');
    if($len<=4)return str_repeat('•',$len);
    $start=mb_substr($code,0,min(4,$len),'UTF-8');
    $end=mb_substr($code,max(0,$len-2),2,'UTF-8');
    return $start.str_repeat('•',max(3,$len-6)).$end;
}

function publicDocStatus(?string $status):array{
    $raw=strtoupper(trim((string)($status?:'VALIDE')));
    return match($raw){
        'ANNULEE','ANNULE','REVOQUEE','REVOQUE','EXPIREE','EXPIRE','SUPPRIMEE','SUPPRIME' => [
            'class'=>'danger','icon'=>'bi-x-circle-fill','title'=>'Document non valide',
            'label'=>$raw,'text'=>'Ce document existe dans STAGIA-RDC, mais il ne doit plus être utilisé comme document valide.'
        ],
        'ARCHIVEE','ARCHIVE' => [
            'class'=>'warning','icon'=>'bi-archive-fill','title'=>'Document archivé',
            'label'=>$raw,'text'=>'Ce document est authentique et archivé dans STAGIA-RDC.'
        ],
        'SIGNEE','SIGNE','VALIDEE','VALIDE','GENEREE','GENERE','PRET','PRETE' => [
            'class'=>'success','icon'=>'bi-patch-check-fill','title'=>'Document authentique',
            'label'=>$raw,'text'=>'Ce document existe dans STAGIA-RDC et son jeton de vérification est valide.'
        ],
        default => [
            'class'=>'info','icon'=>'bi-info-circle-fill','title'=>'Document trouvé',
            'label'=>$raw?:'VALIDE','text'=>'Ce document existe dans STAGIA-RDC. Vérifiez son statut auprès de l’établissement émetteur.'
        ],
    };
}

$status=$data?publicDocStatus($data['statut']??'VALIDE'):[
    'class'=>'danger','icon'=>'bi-x-circle-fill','title'=>'Document introuvable',
    'label'=>'INTROUVABLE','text'=>'Le jeton de vérification est invalide ou le document n’existe pas dans STAGIA-RDC.'
];

$brand=$data?certificateBrand($pdo,(int)($data['host_id']??$data['host_etablissement_id']??0)):[
    'name'=>'STAGIA-RDC','address'=>'','phone'=>'','email'=>'','website'=>'','logo'=>''
];

$base=rtrim((string)(defined('BASE_URL')?BASE_URL:''),'/');
$stagiaLogo=$base.'/assets/img/logo.png';
$brandLogo=trim((string)($brand['logo']??''))?:$stagiaLogo;
$period=$data?cert_date_fr($data['date_debut']??null).' au '.cert_date_fr($data['date_fin']??null):'—';
$session=$data?trim((string)(($data['campaign_code']??'').' — '.($data['campaign_title']??'')),' —'):'—';
$generated=$data?cert_date_fr($data['generated_at']??null):'—';
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vérification publique | STAGIA-RDC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
:root{--orange:#f97316;--ink:#0f172a;--muted:#64748b;--line:#e2e8f0;--soft:#f8fafc}
*{box-sizing:border-box}
body{min-height:100vh;margin:0;background:
radial-gradient(circle at top left,rgba(249,115,22,.14),transparent 32rem),
linear-gradient(135deg,#f8fafc,#eef2f7);color:var(--ink);font-family:Arial,Helvetica,sans-serif}
.verify-wrap{max-width:960px;margin:0 auto;padding:28px 14px 38px}
.verify-card{background:#fff;border:1px solid rgba(226,232,240,.9);border-radius:24px;box-shadow:0 18px 55px rgba(15,23,42,.10);overflow:hidden}
.verify-head{display:flex;align-items:center;gap:14px;padding:18px 22px;border-bottom:1px solid var(--line);background:rgba(255,255,255,.86)}
.logo-box{width:72px;height:58px;border:1px solid #e5eaf0;border-radius:16px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;flex:none}
.logo-box img{width:100%;height:100%;object-fit:contain;padding:6px}
.brand-title{margin:0;font-size:18px;font-weight:900;text-transform:uppercase;line-height:1.15}
.brand-sub{font-size:12px;color:var(--muted)}
.stagia-mini{margin-left:auto;text-align:right;font-size:12px;color:var(--muted)}
.stagia-mini img{width:42px;height:42px;object-fit:contain;display:block;margin-left:auto;margin-bottom:2px}
.status-area{padding:30px 22px 20px;text-align:center}
.status-icon{width:86px;height:86px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:42px;margin-bottom:14px}
.status-success{background:#dcfce7;color:#16a34a}
.status-warning{background:#fff7ed;color:#f97316}
.status-danger{background:#fee2e2;color:#dc2626}
.status-info{background:#e0f2fe;color:#0284c7}
.status-title{font-size:28px;font-weight:900;margin:0 0 7px}
.status-text{color:var(--muted);max-width:660px;margin:0 auto}
.ref-pill{display:inline-flex;gap:8px;align-items:center;border:1px solid var(--line);background:var(--soft);border-radius:999px;padding:8px 13px;margin-top:15px;font-size:13px;font-weight:800}
.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:0 22px 22px}
.info-box{border:1px solid var(--line);border-radius:16px;background:#fff;padding:14px}
.info-box small{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.35px;margin-bottom:4px}
.info-box strong{display:block;font-size:14px;line-height:1.35}
.notice{margin:0 22px 22px;padding:14px 16px;border-radius:16px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:13px}
.foot{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:15px 22px;border-top:1px solid var(--line);background:#fbfdff;color:var(--muted);font-size:12px}
.badge-soft{display:inline-block;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800}
.badge-success{background:#dcfce7;color:#166534}
.badge-warning{background:#ffedd5;color:#9a3412}
.badge-danger{background:#fee2e2;color:#991b1b}
.badge-info{background:#e0f2fe;color:#075985}
@media(max-width:720px){
    .verify-wrap{padding:14px 10px 24px}
    .verify-card{border-radius:18px}
    .verify-head{align-items:flex-start;padding:14px}
    .logo-box{width:58px;height:48px;border-radius:12px}
    .brand-title{font-size:15px}
    .stagia-mini{display:none}
    .status-area{padding:22px 14px 16px}
    .status-title{font-size:22px}
    .info-grid{grid-template-columns:1fr;padding:0 14px 14px}
    .notice{margin:0 14px 14px}
    .foot{padding:14px}
}
</style>
</head>
<body>
<main class="verify-wrap">
    <section class="verify-card">
        <header class="verify-head">
            <div class="logo-box"><img src="<?=vhtml($brandLogo)?>" alt="Logo établissement"></div>
            <div>
                <h1 class="brand-title"><?=vhtml($brand['name']??'STAGIA-RDC')?></h1>
                <div class="brand-sub"><?=vhtml($brand['address']??'')?></div>
                <div class="brand-sub"><?=vhtml(trim((string)($brand['phone']??'').(!empty($brand['email'])?' · '.$brand['email']:'')))?></div>
            </div>
            <div class="stagia-mini">
                <img src="<?=vhtml($stagiaLogo)?>" alt="STAGIA-RDC">
                Vérification publique
            </div>
        </header>

        <div class="status-area">
            <div class="status-icon status-<?=vhtml($status['class'])?>"><i class="bi <?=vhtml($status['icon'])?>"></i></div>
            <h2 class="status-title"><?=vhtml($status['title'])?></h2>
            <p class="status-text"><?=vhtml($status['text'])?></p>
            <?php if($data): ?>
                <div class="ref-pill"><i class="bi bi-file-earmark-check"></i> Référence : <?=vhtml($data['reference']??'—')?></div>
            <?php endif; ?>
        </div>

        <?php if($data): ?>
        <div class="info-grid">
            <div class="info-box">
                <small>Statut du document</small>
                <strong><span class="badge-soft badge-<?=vhtml($status['class'])?>"><?=vhtml($status['label'])?></span></strong>
            </div>
            <div class="info-box">
                <small>Type</small>
                <strong><?=vhtml($data['type_document']??'Attestation de stage')?></strong>
            </div>
            <div class="info-box">
                <small>Stagiaire</small>
                <strong><?=vhtml(publicMaskName((string)($data['student_name']??'—')))?></strong>
            </div>
            <div class="info-box">
                <small>Matricule</small>
                <strong><?=vhtml(publicMaskCode((string)($data['matricule']??'—')))?></strong>
            </div>
            <div class="info-box">
                <small>Établissement d’accueil</small>
                <strong><?=vhtml($data['host_name']??'—')?></strong>
            </div>
            <div class="info-box">
                <small>Établissement de formation</small>
                <strong><?=vhtml($data['university_name']??'—')?></strong>
            </div>
            <div class="info-box">
                <small>Service / unité</small>
                <strong><?=vhtml($data['unit_name']??'—')?></strong>
            </div>
            <div class="info-box">
                <small>Promotion</small>
                <strong><?=vhtml($data['promotion_label']??'—')?></strong>
            </div>
            <div class="info-box">
                <small>Session</small>
                <strong><?=vhtml($session?:'—')?></strong>
            </div>
            <div class="info-box">
                <small>Période</small>
                <strong><?=vhtml($period)?></strong>
            </div>
            <div class="info-box">
                <small>Document généré le</small>
                <strong><?=vhtml($generated)?></strong>
            </div>
            <div class="info-box">
                <small>Contrôle</small>
                <strong><?=vhtml(date('d/m/Y H:i'))?></strong>
            </div>
        </div>

        <div class="notice">
            <i class="bi bi-shield-lock me-1"></i>
            Par sécurité, cette page publique masque une partie des informations personnelles. La version complète du document reste accessible uniquement aux comptes autorisés dans STAGIA-RDC.
        </div>
        <?php else: ?>
        <div class="notice">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Vérifiez que le QR code provient bien d’un document officiel généré par STAGIA-RDC.
        </div>
        <?php endif; ?>

        <footer class="foot">
            <span><strong>STAGIA-RDC</strong></span>
            <span>Vérification publique par QR code</span>
            <span><?=vhtml(date('d/m/Y H:i'))?></span>
        </footer>
    </section>
</main>
</body>
</html>

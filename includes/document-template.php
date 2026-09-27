<?php
require_once __DIR__.'/document-branding.php';

if(!function_exists('stagiaDocumentCss')){
/** Retourne la feuille CSS intégrée garantissant une impression A4 cohérente. */
function stagiaDocumentCss():string{return <<<'CSS'
:root{--orange:#f97316;--dark:#0f172a;--muted:#64748b;--line:#dbe3ea;--soft:#f1f5f9}*{box-sizing:border-box}body{margin:0;background:#e5e7eb;color:var(--dark);font-family:Georgia,'Times New Roman',serif}.doc-actions{position:sticky;top:0;z-index:20;background:#0f172a;color:#fff;padding:10px 18px;display:flex;gap:10px;justify-content:flex-end}.doc-actions a,.doc-actions button{border:0;border-radius:8px;padding:9px 14px;text-decoration:none;cursor:pointer;font-weight:700}.doc-actions a{background:#334155;color:#fff}.doc-actions button{background:var(--orange);color:#fff}.paper{position:relative;width:210mm;min-height:297mm;margin:16px auto;background:#fff;padding:12mm 10mm 11mm;box-shadow:0 8px 30px rgba(15,23,42,.14);overflow:hidden}.doc-letterhead{display:grid;grid-template-columns:42mm 1fr 38mm;gap:8mm;align-items:center;border-bottom:2px solid var(--orange);padding-bottom:4mm}.doc-logo{max-width:38mm;max-height:27mm;object-fit:contain}.doc-center{text-align:center}.doc-center h2{font-size:18px;line-height:1.1;margin:0 0 2mm;text-transform:uppercase;letter-spacing:.4px}.doc-center p{margin:0;color:#334155;font-size:11px}.doc-center h3{margin:2mm 0 1mm;font-size:13px;text-transform:uppercase}.doc-contact{display:flex;gap:5mm;justify-content:center;flex-wrap:wrap;margin-top:3mm;font-family:Arial,sans-serif;font-size:9px;color:#334155}.doc-motto{border-left:1px solid #0f172a;padding-left:5mm;font-style:italic;color:#334155;font-size:12px;line-height:1.5}.doc-motto:after{content:'';display:block;width:22mm;height:2px;background:var(--orange);margin-top:3mm}.doc-title{width:max-content;max-width:100%;margin:8mm auto 5mm;padding:3mm 15mm;border-radius:4mm;background:linear-gradient(90deg,#edf1f5,#f8fafc,#edf1f5);text-align:center;font-size:22px;font-weight:800;text-transform:uppercase;letter-spacing:.2px}.watermark{position:absolute;inset:62mm 18mm auto;display:flex;justify-content:center;align-items:center;opacity:.055;z-index:0;pointer-events:none}.watermark img{width:135mm;max-height:135mm;object-fit:contain}.doc-content{position:relative;z-index:2;font-size:14px;line-height:1.45}.band{display:flex;gap:10mm;background:#eef2f6;padding:1.5mm 5mm;margin:4mm 0 3mm;font-weight:800;text-transform:uppercase}.subband{background:#fff4e8;padding:1.5mm 6mm;margin:3mm 0 2mm 10mm;border-radius:2mm;font-weight:800}.field-row{display:grid;grid-template-columns:58mm 1fr;gap:4mm;margin:1.4mm 0}.dots{border-bottom:1px dotted #111;min-height:5mm}.score-row{display:grid;grid-template-columns:8mm 62mm 38mm;gap:2mm;margin:1.2mm 0 18mm}.score-row{margin:1.2mm 0}.score{border-bottom:1px dotted #111;text-align:right;padding-right:2mm}.totals{margin:4mm 0 4mm 78mm;font-size:16px}.totals div{margin:1.5mm 0}.remarks .dots{height:7mm}.signature{margin-top:7mm;display:flex;justify-content:flex-end}.signature>div{width:82mm}.presence-fields{margin:4mm 0 6mm}.presence-table{width:100%;border-collapse:collapse;font-size:13px}.presence-table th{background:#eef2f6;font-size:15px;padding:3mm;border:1px solid #334155}.presence-table td{height:9.5mm;border:1px solid #334155}.letter-top{display:flex;justify-content:space-between;margin:10mm 0 8mm}.letter-address{margin-left:auto;width:82mm;line-height:1.7}.letter-body{font-size:15px;text-align:justify;line-height:1.7;margin-top:8mm}.letter-body p{margin:0 0 6mm}.inline-dot{display:inline-block;border-bottom:1px dotted #111;min-width:42mm;height:4mm}.footer{position:absolute;left:10mm;right:10mm;bottom:7mm;border-top:2px solid var(--orange);padding-top:3mm;font-family:Arial,sans-serif;font-size:9px;letter-spacing:4px;display:grid;grid-template-columns:1fr 2fr 1fr;gap:6mm;align-items:center;color:#0f172a}.footer div:nth-child(2){text-align:center}.footer div:nth-child(3){text-align:right;letter-spacing:1px}@media print{body{background:#fff}.doc-actions{display:none}.paper{margin:0;width:210mm;min-height:297mm;box-shadow:none;page-break-after:always}}
CSS;}}

if(!function_exists('stagiaDocumentHeader')){
/** Construit l'en-tête institutionnel à partir du branding préparé. */
function stagiaDocumentHeader(array $brand):string{
    $contact=[];
    if($brand['phone'])$contact[]='☎ '.stagiaDocEsc($brand['phone']);
    if($brand['email'])$contact[]='✉ '.stagiaDocEsc($brand['email']);
    if($brand['website'])$contact[]='🌐 '.stagiaDocEsc($brand['website']);
    if(!$contact)$contact[]='☎ +243 ...  ·  ✉ contact@etablissement.cd';
    $motto=implode('<br>',array_map('stagiaDocEsc',preg_split('/\s+/',$brand['motto'])));
    return '<div class="doc-letterhead">
        <div><img class="doc-logo" src="'.stagiaDocEsc($brand['logo_url']).'" alt="Logo établissement"></div>
        <div class="doc-center"><h2>'.stagiaDocEsc($brand['name']).'</h2><p>'.stagiaDocEsc($brand['address']).'</p><h3>'.stagiaDocEsc($brand['subtitle']).'</h3><div class="doc-contact">'.implode(' &nbsp; ',$contact).'</div></div>
        <div class="doc-motto">'.$motto.'</div>
    </div>';
}
}

if(!function_exists('stagiaDocumentFooter')){
/** Construit le pied de page standard des documents STAGIA. */
function stagiaDocumentFooter(array $brand):string{
    $web=$brand['website']?:'www.stagia-rdc.cd';
    return '<div class="footer"><div>STAGIA-RDC</div><div>STAGES POUR UN AVENIR MEILLEUR</div><div>'.stagiaDocEsc($web).'</div></div>';
}
}

if(!function_exists('stagiaDocumentPageOpen')){
/** Ouvre le squelette HTML d'une feuille imprimable. */
function stagiaDocumentPageOpen(array $brand,string $title):string{
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.stagiaDocEsc($title).' | STAGIA-RDC</title><style>'.stagiaDocumentCss().'</style></head><body><div class="doc-actions"><a href="javascript:history.back()">Retour</a><button onclick="window.print()">Imprimer / PDF</button></div><section class="paper">'.stagiaDocumentHeader($brand).'<div class="watermark"><img src="'.stagiaDocEsc($brand['stagia_logo_url']).'" alt="STAGIA"></div><div class="doc-title">'.stagiaDocEsc($title).'</div><div class="doc-content">';
}
}

if(!function_exists('stagiaDocumentPageClose')){
/** Ferme le contenu, ajoute le pied de page et termine le document HTML. */
function stagiaDocumentPageClose(array $brand):string{
    return '</div>'.stagiaDocumentFooter($brand).'</section></body></html>';
}
}

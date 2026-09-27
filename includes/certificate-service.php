<?php
/** Services de lecture, rendu et stockage des attestations vérifiables. */
if(!defined('BASE_URL')){
    $cfg=__DIR__.'/../config/config.php';
    if(is_file($cfg)) require_once $cfg;
}

if(!function_exists('cert_col')){
/** Vérifie une colonne de certificat avec cache par requête. */
function cert_col(PDO $pdo,string $table,string $column):bool{
    static $cache=[];$k=$table.'.'.$column;
    if(array_key_exists($k,$cache))return $cache[$k];
    try{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $s->execute([$table,$column]);return $cache[$k]=(bool)$s->fetchColumn();
    }catch(Throwable $e){return $cache[$k]=false;}
}}

if(!function_exists('cert_table')){
/** Vérifie qu'une table nécessaire au certificat existe. */
function cert_table(PDO $pdo,string $table):bool{
    static $cache=[];if(array_key_exists($table,$cache))return $cache[$table];
    try{
        $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->execute([$table]);return $cache[$table]=(bool)$s->fetchColumn();
    }catch(Throwable $e){return $cache[$table]=false;}
}}

if(!function_exists('cert_expr')){
/** Produit une expression SQL compatible avec plusieurs noms possibles de colonnes. */
function cert_expr(PDO $pdo,string $table,string $alias,array $cols,string $fallback='NULL'):string{
    $x=[];foreach($cols as $c)if(cert_col($pdo,$table,$c))$x[]="NULLIF($alias.`$c`,'')";
    return $x?'COALESCE('.implode(',',$x).','.$fallback.')':$fallback;
}}

if(!function_exists('cert_date_fr')){
/** Convertit une date technique en format français court. */
function cert_date_fr($v):string{
    if(!$v)return '—';$t=strtotime((string)$v);return $t?date('d/m/Y',$t):(string)$v;
}}

if(!function_exists('cert_html')){
/** Échappe une valeur de certificat avant insertion dans le template HTML. */
function cert_html(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
}

if(!function_exists('certificateData')){
/** Charge les données publiques nécessaires à la vérification d'une attestation par son UUID. */
function certificateData(PDO $pdo,string $token):?array{
    $token=trim($token);if($token==='')return null;

    $promoExpr=cert_table($pdo,'promotions')
        ?cert_expr($pdo,'promotions','p',['niveau','code','nom','libelle'],'NULL')
        :'NULL';
    $matExpr="COALESCE(".
        cert_expr($pdo,'student_profiles','sp1',['matricule_academique','matricule','stagia_code','code'],'NULL').",".
        cert_expr($pdo,'student_profiles','sp2',['matricule_academique','matricule','stagia_code','code'],'NULL').",".
        cert_expr($pdo,'student_enrollments','se',['matricule_academique','matricule','stagia_code','code'],'NULL').",'—')";

    $promoJoin=cert_table($pdo,'promotions')?"LEFT JOIN promotions p ON p.id=ae.promotion_id":"";

    $sql="
        SELECT
            cert.id certificate_id,cert.uuid,cert.reference,cert.type_document,cert.statut,cert.fichier,cert.generated_at,
            cert.student_id,cert.host_etablissement_id host_id,
            comp.id completion_id,comp.assignment_id,comp.campaign_id,comp.statut completion_status,
            comp.note_finale,comp.taux_presence,
            a.date_debut,a.date_fin,
            c.code campaign_code,c.titre campaign_title,c.owner_etablissement_id university_id,
            u.nom university_name,
            h.nom host_name,h.id host_etablissement_id,
            hu.nom unit_name,
            COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp1.nom,sp1.postnom,sp1.prenom)),''),NULLIF(TRIM(CONCAT_WS(' ',sp2.nom,sp2.postnom,sp2.prenom)),''),CONCAT('Étudiant #',cert.student_id)) student_name,
            $matExpr matricule,
            $promoExpr promotion_label
        FROM stage_certificates cert
        LEFT JOIN stage_completions comp ON comp.id=cert.completion_id
        LEFT JOIN stage_assignments a ON a.id=comp.assignment_id
        LEFT JOIN stage_campaigns c ON c.id=comp.campaign_id
        LEFT JOIN etablissements u ON u.id=c.owner_etablissement_id
        LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
        LEFT JOIN stage_applications app ON app.id=sr.application_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp1 ON sp1.id=se.student_id
        LEFT JOIN student_profiles sp2 ON sp2.id=cert.student_id
        $promoJoin
        LEFT JOIN etablissements h ON h.id=cert.host_etablissement_id
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id
        WHERE cert.uuid=?
        LIMIT 1";
    $s=$pdo->prepare($sql);$s->execute([$token]);$d=$s->fetch(PDO::FETCH_ASSOC);
    if(!$d)return null;

    /* Secours promotion : lecture directe si le libellé reste vide. */
    if((empty($d['promotion_label'])||preg_match('/^Promotion #/i',(string)$d['promotion_label'])) && cert_table($pdo,'stage_campaign_promotions')){
        try{
            $s=$pdo->prepare("SELECT COALESCE(NULLIF(p.niveau,''),NULLIF(p.code,''),NULLIF(p.nom,''),NULLIF(p.libelle,'')) FROM stage_campaign_promotions cp LEFT JOIN promotions p ON p.id=cp.promotion_id WHERE cp.campaign_id=? LIMIT 1");
            $s->execute([(int)($d['campaign_id']??0)]);$v=$s->fetchColumn();if($v)$d['promotion_label']=$v;
        }catch(Throwable $e){}
    }

    foreach(['student_name','matricule','promotion_label','campaign_code','campaign_title','host_name','university_name','unit_name'] as $k)
        if(!isset($d[$k])||$d[$k]==='')$d[$k]='—';

    $d['note_finale']=$d['note_finale']!==null?(float)$d['note_finale']:null;
    $d['taux_presence']=$d['taux_presence']!==null?(float)$d['taux_presence']:null;
    return $d;
}}

if(!function_exists('certificateBrand')){
/** Prépare le branding de la structure émettrice de l'attestation. */
function certificateBrand(PDO $pdo,int $eid):array{
    $d=['name'=>'STAGIA-RDC','address'=>'','phone'=>'','email'=>'','website'=>'','logo'=>''];
    if(!$eid)return $d;
    try{
        $cols=['nom'];foreach(['adresse','ville','province','telephone','phone','email','site_web','website','logo_path','logo'] as $c)if(cert_col($pdo,'etablissements',$c))$cols[]=$c;
        $s=$pdo->prepare('SELECT '.implode(',',$cols).' FROM etablissements WHERE id=? LIMIT 1');$s->execute([$eid]);$e=$s->fetch(PDO::FETCH_ASSOC)?:[];
        $addr=trim(implode(', ',array_filter([$e['adresse']??'', $e['ville']??'', $e['province']??''])));
        $logo=$e['logo_path']??($e['logo']??'');
        if($logo && defined('BASE_URL') && !preg_match('~^https?://~',$logo))$logo=rtrim(BASE_URL,'/').'/'.ltrim($logo,'/');
        return ['name'=>$e['nom']??$d['name'],'address'=>$addr,'phone'=>$e['telephone']??($e['phone']??''),'email'=>$e['email']??'','website'=>$e['site_web']??($e['website']??''),'logo'=>$logo];
    }catch(Throwable $e){return $d;}
}}

if(!function_exists('certificateRenderHtml')){
/** Génère la feuille HTML imprimable d'une attestation avec lien et QR de vérification. */
function certificateRenderHtml(PDO $pdo,array $d):string{
    $brand=certificateBrand($pdo,(int)($d['host_id']??$d['host_etablissement_id']??0));
    $verify=(defined('BASE_URL')?rtrim(BASE_URL,'/'):'').'/views/public/verify-certificate.php?token='.rawurlencode($d['uuid']);
    $qrSrc='https://api.qrserver.com/v1/create-qr-code/?size=130x130&margin=1&data='.rawurlencode($verify);
    $note=$d['note_finale']!==null?number_format((float)$d['note_finale'],2,',',' ').' / 20':'—';
    $pres=$d['taux_presence']!==null?number_format((float)$d['taux_presence'],2,',',' ').' %':'—';
    $periode=cert_date_fr($d['date_debut']??null).' au '.cert_date_fr($d['date_fin']??null);
    $session=trim(($d['campaign_code']??'').' — '.($d['campaign_title']??''),' —');
    $logo=$brand['logo']?'<img src="'.cert_html($brand['logo']).'" class="brand-logo" alt="Logo établissement">':'<div class="brand-logo-placeholder">Logo<br>établissement</div>';
    $contact=trim(($brand['phone']?:'').($brand['email']?' · '.$brand['email']:'').($brand['website']?' · '.$brand['website']:''));

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.cert_html($d['reference']).'</title><style>
        :root{--orange:#f97316;--ink:#0f172a;--muted:#64748b;--line:#dbe3ea;--soft:#f8fafc}
        *{box-sizing:border-box}html,body{margin:0;padding:0}body{font-family:DejaVu Sans,Arial,sans-serif;background:#e5e7eb;color:var(--ink);font-size:12px;line-height:1.55}.printbar{position:fixed;top:18px;right:22px;z-index:20}.printbar button{border:0;border-radius:10px;background:var(--orange);color:#fff;padding:10px 15px;font-weight:800;box-shadow:0 8px 22px rgba(249,115,22,.25);cursor:pointer}.sheet{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:18mm 18mm 18mm;position:relative;box-shadow:0 10px 35px rgba(15,23,42,.18);overflow:hidden}.head{display:grid;grid-template-columns:94px 1fr 155px;gap:16px;align-items:center;border-bottom:3px solid var(--orange);padding-bottom:13px}.brand-logo,.brand-logo-placeholder{width:86px;height:70px;object-fit:contain;border:1px solid #e8edf3;border-radius:12px;background:#fff}.brand-logo-placeholder{display:flex;align-items:center;justify-content:center;text-align:center;color:#64748b;font-size:10px;line-height:1.15}.brand h1{margin:0 0 3px;font-size:18px;line-height:1.05;text-transform:uppercase;letter-spacing:.2px}.brand .addr,.brand .contact{font-size:10.5px;color:#475569}.motto{text-align:right;font-size:10px;color:#475569;font-style:italic;line-height:1.25;border-left:1px solid #cbd5e1;padding-left:12px}.motto:after{content:"";display:block;width:56px;height:3px;background:var(--orange);margin:8px 0 0 auto;border-radius:3px}.title{text-align:center;margin:28px 0 24px}.title span{display:inline-block;border:1px solid var(--line);border-radius:11px;background:var(--soft);padding:11px 28px;font-size:21px;font-weight:900;letter-spacing:.7px;text-transform:uppercase}.ref-row{display:flex;justify-content:space-between;gap:18px;margin:0 0 24px;font-size:11px}.body-text{font-size:13px;text-align:justify;line-height:1.95;margin:0 0 22px}.body-text strong{font-weight:900}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:20px 0 20px}.info-box{border:1px solid var(--line);border-radius:10px;padding:10px 12px;background:#fff}.info-box small{display:block;color:var(--muted);font-size:8.5px;text-transform:uppercase;letter-spacing:.35px;margin-bottom:3px}.info-box strong{display:block;font-size:12px;line-height:1.35}.verify-sign{display:grid;grid-template-columns:300px 1fr;gap:34px;align-items:start;margin-top:34px;margin-bottom:44px}.verify{border:1px solid var(--line);border-radius:10px;padding:10px;background:#fff;font-size:8.8px;line-height:1.25;overflow-wrap:anywhere}.verify strong{font-size:12px}.verify small{color:var(--muted)}.qr-layout{display:grid;grid-template-columns:82px 1fr;gap:10px;align-items:center}.qr-layout img{width:78px;height:78px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:4px}.qr-link{font-size:8px;color:#334155;line-height:1.2;word-break:break-all}.qr-title{font-weight:900;font-size:11px;color:#0f172a;margin-bottom:3px}.sign{text-align:right;padding-top:42px}.sign .line{display:inline-block;width:265px;border-top:1px dotted #111;padding-top:8px}.sign strong{font-size:12px}.sign small{font-size:9px}.watermark{position:absolute;left:0;right:0;top:112mm;text-align:center;font-size:82px;font-weight:900;color:#0f172a;opacity:.035;z-index:0;pointer-events:none;letter-spacing:3px}.sheet>*:not(.watermark){position:relative;z-index:1}.foot{position:static;margin-top:66px;border-top:3px solid var(--orange);padding-top:9px;color:#475569;font-size:8.5px;display:flex;justify-content:space-between;gap:14px;letter-spacing:.2px;clear:both}.muted{color:var(--muted)}@page{size:A4;margin:0}@media print{body{background:#fff}.printbar{display:none}.sheet{width:210mm;min-height:297mm;margin:0;box-shadow:none;padding:18mm 18mm 18mm}.head{break-inside:avoid}.info-grid,.verify-sign{break-inside:avoid}.foot{margin-top:66px}}@media screen and (max-width:900px){.sheet{width:calc(100vw - 24px);min-height:auto;padding:24px}.head{grid-template-columns:76px 1fr}.motto{grid-column:1/-1;text-align:left;border-left:0;border-top:1px solid #e2e8f0;padding:10px 0 0}.motto:after{margin-left:0}.info-grid,.verify-sign{grid-template-columns:1fr}.qr-layout{grid-template-columns:72px 1fr}.qr-layout img{width:68px;height:68px}.sign{text-align:left;padding-top:10px}.foot{position:static;margin-top:40px;flex-wrap:wrap}}
    </style></head><body><div class="printbar"><button onclick="window.print()">Imprimer / PDF</button></div><main class="sheet"><div class="watermark">STAGIA</div><header class="head">'.$logo.'<div class="brand"><h1>'.cert_html($brand['name']).'</h1><div class="addr">'.cert_html($brand['address']?:'Adresse de l’établissement').'</div><div class="contact">'.cert_html($contact?:'Téléphone · Email · Site web').'</div></div><div class="motto">Former aujourd’hui<br>pour un Congo meilleur demain</div></header><section class="title"><span>Attestation de stage</span></section><div class="ref-row"><div><strong>Référence :</strong> '.cert_html($d['reference']).'</div><div><strong>Date :</strong> '.date('d/m/Y').'</div></div><p class="body-text">Nous attestons que <strong>'.cert_html($d['student_name']).'</strong>, matricule <strong>'.cert_html($d['matricule']).'</strong>, promotion <strong>'.cert_html($d['promotion_label']).'</strong>, a effectué un stage au sein de <strong>'.cert_html($d['host_name']).'</strong>, dans le service <strong>'.cert_html($d['unit_name']).'</strong>, pour la période allant du <strong>'.$periode.'</strong>.</p><p class="body-text">Cette attestation est délivrée après validation du parcours de stage dans la plateforme <strong>STAGIA-RDC</strong>.</p><section class="info-grid"><div class="info-box"><small>Session / campagne</small><strong>'.cert_html($session?:'—').'</strong></div><div class="info-box"><small>Période</small><strong>'.$periode.'</strong></div><div class="info-box"><small>Taux de présence</small><strong>'.$pres.'</strong></div><div class="info-box"><small>Note finale</small><strong>'.$note.'</strong></div><div class="info-box"><small>Document</small><strong>Attestation officielle validée</strong></div><div class="info-box"><small>Authenticité</small><strong>Document vérifiable publiquement</strong></div></section><section class="verify-sign"><div class="verify"><div class="qr-layout"><img src="'.cert_html($qrSrc).'" alt="QR vérification"><div><div class="qr-title">Vérification publique</div><div class="qr-link">'.cert_html($verify).'</div><small>Scanner le QR code pour vérifier l’authenticité.</small></div></div></div><div class="sign"><div class="line"><strong>Responsable habilité</strong><br><small>Nom, cachet et signature</small></div></div></section><footer class="foot"><span>STAGIA-RDC</span><span>STAGES POUR UN AVENIR MEILLEUR</span><span>DOCUMENT VÉRIFIABLE</span></footer></main></body></html>';
}}

if(!function_exists('certificateEnsurePdf')){
/** Écrit le rendu d'attestation dans le stockage local pour une livraison ultérieure. */
function certificateEnsurePdf(PDO $pdo,array $data):array{
    $dir=__DIR__.'/../storage/certificates';
    if(!is_dir($dir))@mkdir($dir,0777,true);
    $path=$dir.'/'.$data['uuid'].'.html';
    file_put_contents($path,certificateRenderHtml($pdo,$data));
    return ['path'=>$path,'mime'=>'text/html'];
}}

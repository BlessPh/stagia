<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/auth.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/stage-statistics-export.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

try{
    $data=stageStatsExportData($pdo);

    $logo='';
    $logoPath=__DIR__.'/../../../assets/img/logo.png';
    if(is_file($logoPath)){
        $mime=mime_content_type($logoPath)?:'image/png';
        $logo='data:'.$mime.';base64,'.base64_encode(file_get_contents($logoPath));
    }

    $filters='';
    foreach($data['filters']['labels'] as $label=>$value)
        $filters.='<tr><td>'.stageStatsEsc($label).'</td><td>'.stageStatsEsc((string)$value).'</td></tr>';

    $kpis='';
    foreach($data['kpi'] as $label=>$value)
        $kpis.='<div class="kpi"><span>'.stageStatsEsc($label).'</span><strong>'.stageStatsEsc((string)$value).'</strong></div>';

    $students='';
    foreach(array_slice($data['students'],0,100) as $x){
        $students.='<tr>'.
            '<td>'.stageStatsEsc($x['stagia_code']??'').'</td>'.
            '<td>'.stageStatsEsc($x['student_name']??'').'</td>'.
            '<td>'.stageStatsEsc($x['campaign_title']??'').'</td>'.
            '<td>'.stageStatsEsc($x['host_name']??'').'</td>'.
            '<td>'.stageStatsEsc((string)($x['attendance_rate']??0)).'%</td>'.
            '<td>'.stageStatsEsc((string)($x['task_progress']??0)).'%</td>'.
            '<td>'.stageStatsEsc($x['evaluation_status']??'—').'</td>'.
            '<td>'.stageStatsEsc($x['convention_status']??'—').'</td>'.
            '<td>'.stageStatsEsc($x['statut']??'').'</td>'.
        '</tr>';
    }
    if($students==='')$students='<tr><td colspan="9" class="empty">Aucune donnée.</td></tr>';

    $campaigns='';
    foreach(array_slice($data['campaigns'],0,25) as $x){
        $campaigns.='<tr>'.
            '<td>'.stageStatsEsc($x['code']??'').'</td>'.
            '<td>'.stageStatsEsc($x['titre']??'').'</td>'.
            '<td>'.stageStatsEsc($x['stage_type']??'').'</td>'.
            '<td>'.(int)($x['students']??0).'</td>'.
            '<td>'.(int)($x['confirmed']??0).'</td>'.
            '<td>'.(int)($x['finished']??0).'</td>'.
        '</tr>';
    }
    if($campaigns==='')$campaigns='<tr><td colspan="6" class="empty">Aucune donnée.</td></tr>';

    $hosts='';
    foreach(array_slice($data['hosts'],0,25) as $x){
        $hosts.='<tr>'.
            '<td>'.stageStatsEsc($x['code']??'').'</td>'.
            '<td>'.stageStatsEsc($x['nom']??'').'</td>'.
            '<td>'.(int)($x['students']??0).'</td>'.
            '<td>'.(int)($x['campaigns']??0).'</td>'.
            '<td>'.(int)($x['confirmed']??0).'</td>'.
            '<td>'.(int)($x['finished']??0).'</td>'.
        '</tr>';
    }
    if($hosts==='')$hosts='<tr><td colspan="6" class="empty">Aucune donnée.</td></tr>';

    $title='Rapport statistique des stages';
    $generated=date('d/m/Y H:i');

    $html='<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>
    @page{margin:20px 22px}
    body{font-family:DejaVu Sans,Arial,sans-serif;color:#172033;font-size:9px}
    .head{border-bottom:3px solid #ff751f;padding-bottom:10px;margin-bottom:12px}
    .head table{width:100%;border-collapse:collapse}.logo{width:115px}
    h1{font-size:19px;margin:0 0 4px}.muted{color:#64748b}
    .filters{width:100%;border-collapse:collapse;margin:8px 0 12px}.filters td{padding:4px 7px;border:1px solid #e5e7eb}.filters td:first-child{width:32%;font-weight:bold;background:#f8fafc}
    .kpis{margin:0 -4px 12px}.kpi{display:inline-block;width:22%;vertical-align:top;margin:4px;padding:8px;border:1px solid #e5e7eb;border-radius:6px}.kpi span{display:block;color:#64748b;font-size:8px;text-transform:uppercase}.kpi strong{display:block;font-size:15px;margin-top:4px}
    h2{font-size:13px;margin:15px 0 6px;border-left:4px solid #ff751f;padding-left:7px}
    table.data{width:100%;border-collapse:collapse;page-break-inside:auto}.data th{background:#172033;color:#fff;padding:5px 4px;font-size:7.5px}.data td{border:1px solid #e5e7eb;padding:4px;font-size:7.5px;vertical-align:top}.data tr{page-break-inside:avoid}.empty{text-align:center;color:#64748b}
    .footer{margin-top:14px;border-top:1px solid #e5e7eb;padding-top:6px;color:#64748b;font-size:7px;text-align:right}
    </style></head><body>
    <div class="head"><table><tr><td>'.($logo?'<img src="'.$logo.'" class="logo">':'<strong>STAGIA-RDC</strong>').'</td><td style="text-align:right"><h1>'.$title.'</h1><div class="muted">'.stageStatsEsc($data['actor']['scopeLabel']).'<br>Généré le '.$generated.'</div></td></tr></table></div>
    <table class="filters">'.$filters.'</table>
    <div class="kpis">'.$kpis.'</div>

    <h2>Suivi par stagiaire</h2>
    <table class="data"><thead><tr><th>Code</th><th>Stagiaire</th><th>Campagne</th><th>Accueil</th><th>Prés.</th><th>Prog.</th><th>Éval.</th><th>Convention</th><th>Statut</th></tr></thead><tbody>'.$students.'</tbody></table>

    <h2>Par campagne</h2>
    <table class="data"><thead><tr><th>Code</th><th>Campagne</th><th>Type</th><th>Stagiaires</th><th>Confirmés</th><th>Terminés</th></tr></thead><tbody>'.$campaigns.'</tbody></table>

    <h2>Par établissement d’accueil</h2>
    <table class="data"><thead><tr><th>Code</th><th>Établissement</th><th>Stagiaires</th><th>Campagnes</th><th>Confirmés</th><th>Terminés</th></tr></thead><tbody>'.$hosts.'</tbody></table>

    <div class="footer">STAGIA-RDC — Rapport généré électroniquement</div>
    </body></html>';

    $options=new Options();
    $options->set('isRemoteEnabled',false);
    $options->set('defaultFont','DejaVu Sans');

    $dompdf=new Dompdf($options);
    $dompdf->loadHtml($html,'UTF-8');
    $dompdf->setPaper('A4','landscape');
    $dompdf->render();

    $filename='stagia-rapport-stages-'.stageStatsExportFileSuffix($data).'.pdf';
    while(ob_get_level())ob_end_clean();

    $dompdf->stream($filename,[
        'Attachment'=>($_GET['download']??'1')!=='0'
    ]);
    exit;
}catch(Throwable $e){
    http_response_code(500);
    exit('Export PDF impossible : '.$e->getMessage());
}

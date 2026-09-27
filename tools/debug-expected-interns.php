<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

requireRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','SUPER_ADMIN']);
$hostId=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));
header('Content-Type:text/html; charset=utf-8');
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

echo '<h2>Debug stagiaires attendus</h2><p>hostId = '.h($hostId).'</p>';
if(!$hostId){echo '<p>Aucun établissement associé.</p>';exit;}

$sql="
SELECT ad.id admission_id,ad.statut admission_status,ad.reservation_id,ad.host_etablissement_id,
       sr.statut reservation_status,app.id application_id,app.host_etablissement_id app_host_id,
       c.code campaign_code,c.titre campaign_title,u.nom university_name,
       COALESCE(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom),CONCAT('Étudiant #',se.student_id)) student_name,
       inv.reference invoice_reference,inv.statut invoice_status
FROM stage_admissions ad
LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
LEFT JOIN stage_applications app ON app.id=sr.application_id
LEFT JOIN stage_campaigns c ON c.id=app.campaign_id
LEFT JOIN etablissements u ON u.id=c.owner_etablissement_id
LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
LEFT JOIN student_profiles sp ON sp.id=se.student_id
LEFT JOIN stage_invoices inv ON inv.reservation_id=sr.id AND inv.statut<>'ANNULEE'
WHERE ad.host_etablissement_id=? AND ad.statut<>'ANNULE'
ORDER BY ad.id DESC
LIMIT 50";
$s=$pdo->prepare($sql);$s->execute([$hostId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);

echo '<p>Total brut trouvé : <b>'.count($rows).'</b></p>';
echo '<table border="1" cellpadding="6" cellspacing="0"><tr>';
foreach(['admission_id','admission_status','reservation_id','reservation_status','application_id','app_host_id','campaign_code','campaign_title','university_name','student_name','invoice_reference','invoice_status'] as $c)echo '<th>'.h($c).'</th>';
echo '</tr>';
foreach($rows as $r){echo '<tr>';foreach(['admission_id','admission_status','reservation_id','reservation_status','application_id','app_host_id','campaign_code','campaign_title','university_name','student_name','invoice_reference','invoice_status'] as $c)echo '<td>'.h($r[$c]??'').'</td>';echo '</tr>';}
echo '</table>';

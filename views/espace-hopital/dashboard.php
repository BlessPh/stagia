<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/chef-service-scope.php';

requireRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','ENCADREUR','EVALUATEUR_CLINIQUE','POINTEUR','AUTORITE_HOSPITALIERE','GESTIONNAIRE_FINANCIER_HOSPITALIER']);

$eid=(int)currentEtablissementId($pdo);$uid=(int)($_SESSION['user_id']??0);
if(!$eid||!$uid)exit('Session invalide.');

$s=$pdo->prepare("SELECT e.id,e.code,e.nom,e.ville,e.province,e.statut,e.type_etablissement FROM etablissements e LEFT JOIN establishment_types et ON et.code=e.type_etablissement WHERE e.id=? AND (e.type_etablissement='HOPITAL' OR et.host_enabled=1) LIMIT 1");
$s->execute([$eid]);$hopital=$s->fetch(PDO::FETCH_ASSOC);
if(!$hopital)exit('Structure d’accueil introuvable.');

$role=strtoupper(trim((string)($_SESSION['role_code']??'')));
$isAdmin=$role==='ADMIN_ACCUEIL';$isCoord=$role==='COORDINATEUR_STAGES';$isChef=$role==='CHEF_SERVICE'||preg_match('/ROLE_CHEF_SERVICE$/',$role);$isEnc=in_array($role,['ENCADREUR','EVALUATEUR_CLINIQUE'],true);$isPoint=$role==='POINTEUR';$isFin=$role==='GESTIONNAIRE_FINANCIER_HOSPITALIER';$isAuthority=$role==='AUTORITE_HOSPITALIERE';$isOps=$isAdmin||$isCoord||$isAuthority;

function dCount(PDO $pdo,string $sql,array $p=[]):int{try{$s=$pdo->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Throwable $e){error_log('[HOSP DASH COUNT] '.$e->getMessage());return 0;}}
function dRows(PDO $pdo,string $sql,array $p=[]):array{try{$s=$pdo->prepare($sql);$s->execute($p);return$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){error_log('[HOSP DASH ROWS] '.$e->getMessage());return[];}}
function dHasTable(PDO $pdo,string $t):bool{try{$s=$pdo->prepare("SHOW TABLES LIKE ?");$s->execute([$t]);return(bool)$s->fetchColumn();}catch(Throwable $e){return false;}}
function dDate(?string $v):string{if(!$v)return '—';$p=explode('-',substr($v,0,10));return count($p)===3?$p[2].'/'.$p[1].'/'.$p[0]:$v;}

$hasResults=dHasTable($pdo,'stage_result_transmissions')&&dHasTable($pdo,'stage_result_items');
$resultsReady=$hasResults?dCount($pdo,"
    SELECT COUNT(DISTINCT c.id)
    FROM stage_evaluations ev
    JOIN stage_rotations r ON r.id=ev.rotation_id
    JOIN stage_assignments a ON a.id=r.assignment_id
    JOIN stage_admissions ad ON ad.id=a.admission_id
    JOIN stage_reservations sr ON sr.id=ad.reservation_id
    JOIN stage_applications app ON app.id=sr.application_id
    JOIN stage_campaigns c ON c.id=app.campaign_id
    WHERE r.host_etablissement_id=?
      AND ev.statut IN('VALIDEE','FINALISEE')
      AND NOT EXISTS(
          SELECT 1 FROM stage_result_transmissions t
          WHERE t.campaign_id=c.id AND t.host_etablissement_id=?
            AND t.university_etablissement_id=c.owner_etablissement_id
            AND t.statut IN('ENVOYE','RECU','VALIDE','ARCHIVE')
      )",[$eid,$eid]):0;
$resultsSent=$hasResults?dCount($pdo,"SELECT COUNT(*) FROM stage_result_transmissions WHERE host_etablissement_id=? AND statut IN('ENVOYE','RECU','VALIDE','ARCHIVE')",[$eid]):0;

$kpis=[];$quick=[];$latest=[];$scopeInfo=null;$title='Tableau de bord Hôpital';

if($isChef){
    $title='Tableau de bord Chef de service';$scopeInfo=chefServiceScope($pdo,$eid,$uid);
    $p=[$eid];$w=chefServiceWhere('a',$scopeInfo,$p);
    $p2=[$eid];$w2=chefServiceWhere('a',$scopeInfo,$p2);
    $p3=[$eid];$w3=chefServiceWhere('a',$scopeInfo,$p3);
    $p4=[$eid];$w4=chefServiceWhere('a',$scopeInfo,$p4);
    $services=!empty($scopeInfo['all'])?dCount($pdo,"SELECT COUNT(*) FROM host_units WHERE host_etablissement_id=? AND actif=1 AND UPPER(type) IN('SERVICE','UNITE','UNITÉ')",[$eid]):count($scopeInfo['ids']??[]);
    $evals=dCount($pdo,"SELECT COUNT(DISTINCT e.id) FROM stage_evaluations e JOIN stage_rotations r ON r.id=e.rotation_id JOIN stage_assignments a ON a.id=r.assignment_id WHERE a.host_etablissement_id=? $w4 AND e.statut='SOUMISE'",$p4);
    $kpis=[
        ['SERVICES',$services,'Périmètre couvert','bi-diagram-3','kpi-blue'],
        ['STAGIAIRES',dCount($pdo,"SELECT COUNT(DISTINCT a.id) FROM stage_assignments a WHERE a.host_etablissement_id=? $w AND a.statut<>'ANNULEE'",$p),'Reçus dans le service','bi-people','kpi-purple'],
        ['ROTATIONS',dCount($pdo,"SELECT COUNT(DISTINCT r.id) FROM stage_rotations r JOIN stage_assignments a ON a.id=r.assignment_id WHERE a.host_etablissement_id=? $w2 AND r.statut<>'ANNULEE'",$p2),'Rotations du service','bi-arrow-repeat','kpi-green'],
        [$evals?'ÉVALUATIONS':'ENCADREURS',$evals?:dCount($pdo,"SELECT COUNT(DISTINCT rs.user_id) FROM stage_rotation_supervisors rs JOIN stage_rotations r ON r.id=rs.rotation_id JOIN stage_assignments a ON a.id=r.assignment_id WHERE a.host_etablissement_id=? $w3 AND rs.actif=1 AND r.statut<>'ANNULEE'",$p3),$evals?'À valider':'Désignés','bi-clipboard-check','kpi-orange']
    ];
    $quick=[
        [BASE_URL.'/views/espace-hopital/chef-service-stagiaires.php','bi-person-check','Stagiaires reçus','Suivre les stagiaires de votre service.'],
        [BASE_URL.'/views/espace-hopital/rotations.php','bi-arrow-repeat','Rotations du service','Organiser les rotations et les encadreurs.'],
        [BASE_URL.'/views/espace-hopital/evaluations.php','bi-clipboard-check','Évaluations','Valider ou retourner les évaluations.'],
        [BASE_URL.'/views/espace-hopital/journaux-stage.php','bi-journal-medical','Journaux','Contrôler les journaux soumis.'],
        [BASE_URL.'/views/espace-hopital/presences.php','bi-calendar-check','Présences','Suivre les présences.'],
        [BASE_URL.'/views/espace-hopital/taches-progression.php','bi-list-check','Tâches','Voir la progression.']
    ];
}elseif($isEnc){
    $title='Tableau de bord Encadreur / Maître de stage';
    $x=dRows($pdo,"SELECT COUNT(DISTINCT r.id) rotations,COUNT(DISTINCT CASE WHEN r.statut='ACTIVE' THEN r.id END) actives,COUNT(DISTINCT r.assignment_id) stagiaires FROM stage_rotations r JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE r.host_etablissement_id=? AND r.statut<>'ANNULEE'",[$uid,$eid])[0]??[];
    $journaux=dCount($pdo,"SELECT COUNT(*) FROM stage_logbook_entries e JOIN stage_rotation_supervisors rs ON rs.rotation_id=e.rotation_id AND rs.user_id=? AND rs.actif=1 WHERE e.host_etablissement_id=? AND e.statut='SOUMIS'",[$uid,$eid]);
    $evals=dCount($pdo,"SELECT COUNT(DISTINCT e.id) FROM stage_evaluations e JOIN stage_rotations r ON r.id=e.rotation_id JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id AND rs.user_id=? AND rs.actif=1 WHERE r.host_etablissement_id=? AND e.evaluator_user_id=? AND e.statut='BROUILLON'",[$uid,$eid,$uid]);
    $kpis=[['ROTATIONS',(int)($x['rotations']??0),'Rotations confiées','bi-arrow-repeat','kpi-blue'],['ACTIVES',(int)($x['actives']??0),'En cours','bi-play-circle','kpi-green'],['STAGIAIRES',(int)($x['stagiaires']??0),'À suivre','bi-people','kpi-purple'],[$evals?'ÉVALUATIONS':'JOURNAUX',$evals?:$journaux,$evals?'À corriger':'À traiter','bi-journal-check','kpi-orange']];
    $quick=[[BASE_URL.'/views/espace-hopital/mes-rotations.php','bi-person-lines-fill','Mes stagiaires / rotations','Voir vos rotations.'],[BASE_URL.'/views/espace-hopital/journaux-stage.php','bi-journal-medical','Journaux','Valider les journaux.'],[BASE_URL.'/views/espace-hopital/presences.php','bi-calendar-check','Présences','Pointer les présences.'],[BASE_URL.'/views/espace-hopital/taches-progression.php','bi-list-check','Tâches','Suivre la progression.'],[BASE_URL.'/views/espace-hopital/feedbacks.php','bi-chat-left-text','Feedback','Ajouter des observations.'],[BASE_URL.'/views/espace-hopital/evaluations.php','bi-clipboard-check','Évaluations','Préparer puis soumettre.']];
}elseif($isFin){
    $title='Tableau de bord Finance';
    $kpis=[['PAIEMENTS',dCount($pdo,"SELECT COUNT(*) FROM stage_payments p JOIN stage_invoices i ON i.id=p.invoice_id WHERE i.host_etablissement_id=?",[$eid]),'Opérations reçues','bi-cash-coin','kpi-green'],['FACTURES',dCount($pdo,"SELECT COUNT(*) FROM stage_invoices WHERE host_etablissement_id=?",[$eid]),'Émises','bi-receipt','kpi-blue'],['PAYÉES',dCount($pdo,"SELECT COUNT(*) FROM stage_invoices WHERE host_etablissement_id=? AND statut='PAYEE'",[$eid]),'Soldées','bi-check-circle','kpi-purple'],['EN ATTENTE',dCount($pdo,"SELECT COUNT(*) FROM stage_invoices WHERE host_etablissement_id=? AND statut<>'PAYEE'",[$eid]),'Non soldées','bi-hourglass-split','kpi-orange']];
    $quick=[[BASE_URL.'/views/espace-hopital/paiements-recus.php','bi-credit-card','Paiements reçus','Consulter les paiements.']];
}else{
    $title='Tableau de bord Hôpital';
    $kpis=[
        ['SOLLICITATIONS',dCount($pdo,"SELECT COUNT(*) FROM stage_campaign_participations WHERE host_etablissement_id=? AND statut='SOLLICITEE'",[$eid]),'Demandes à traiter','bi-bell','kpi-orange'],
        ['STAGIAIRES ATTENDUS',dCount($pdo,"SELECT COUNT(*) FROM stage_admissions WHERE host_etablissement_id=? AND statut='ATTENDU'",[$eid]),'À admettre','bi-people','kpi-blue'],
        ['ROTATIONS',dCount($pdo,"SELECT COUNT(*) FROM stage_rotations WHERE host_etablissement_id=? AND statut<>'ANNULEE'",[$eid]),'Organisées','bi-arrow-repeat','kpi-purple'],
        ['RÉSULTATS PRÊTS',$resultsReady,'À transmettre','bi-send-check','kpi-green']
    ];
    $quick=[
        [BASE_URL.'/views/espace-hopital/sollicitations-d4.php','bi-bell-fill','Sollicitations','Répondre aux universités.'],
        [BASE_URL.'/views/espace-hopital/stagiaires-attendus.php','bi-people','Stagiaires attendus','Admettre les stagiaires.'],
        [BASE_URL.'/views/espace-hopital/affectations.php','bi-geo-alt','Affectations','Affecter aux services.'],
        [BASE_URL.'/views/espace-hopital/rotations.php','bi-arrow-repeat','Rotations','Organiser les rotations.'],
        [BASE_URL.'/views/espace-hopital/evaluations.php','bi-clipboard-check','Évaluations','Consulter ou finaliser.'],
        [BASE_URL.'/views/espace-hopital/resultats-stage.php','bi-send-check','Résultats de stage','Transmettre à l’université.']
    ];
    $latest=dRows($pdo,"SELECT t.id,t.statut,t.transmitted_at,c.code campaign_code,c.titre campaign_title,u.nom university_name,COUNT(i.id) total_students,ROUND(AVG(i.final_score),2) avg_score FROM stage_result_transmissions t JOIN stage_campaigns c ON c.id=t.campaign_id JOIN etablissements u ON u.id=t.university_etablissement_id LEFT JOIN stage_result_items i ON i.transmission_id=t.id WHERE t.host_etablissement_id=? GROUP BY t.id ORDER BY t.created_at DESC,t.id DESC LIMIT 5",[$eid]);
}

$pageTitle=$title;$activePage='hopital-dashboard';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1><?=htmlspecialchars($title)?></h1><p><?=htmlspecialchars($hopital['nom'])?><?=!empty($hopital['code'])?' • '.htmlspecialchars($hopital['code']):''?></p></div><span class="badge bg-success px-3 py-2"><i class="bi bi-check-circle me-1"></i><?=htmlspecialchars($hopital['statut'])?></span></div>
<?php if($isChef&&$scopeInfo): ?><div class="alert alert-light border mb-4"><i class="bi bi-diagram-3 text-primary me-1"></i><strong>Périmètre Chef de service :</strong> <?=htmlspecialchars(implode(' / ',$scopeInfo['labels']??[]))?></div><?php endif; ?>
<div class="stagia-kpi-grid mb-4"><?php foreach($kpis as $k): ?><div class="stagia-kpi-card"><div><span><?=htmlspecialchars((string)$k[0])?></span><strong><?=htmlspecialchars((string)$k[1])?></strong><small><?=htmlspecialchars((string)$k[2])?></small></div><div class="stagia-kpi-icon <?=htmlspecialchars($k[4])?>"><i class="bi <?=htmlspecialchars($k[3])?>"></i></div></div><?php endforeach; ?></div>
<?php if($quick): ?><div class="stagia-list-card mb-4"><div class="stagia-list-toolbar"><div><h5 class="mb-1">Accès rapides</h5><small class="text-muted">Liens de travail liés à votre rôle.</small></div></div><div class="p-4"><div class="row g-3"><?php foreach($quick as $q): ?><div class="col-md-6 col-xl-4"><a href="<?=htmlspecialchars($q[0])?>" class="text-decoration-none"><div class="border rounded-3 p-4 h-100 bg-white"><div class="stagia-kpi-icon kpi-blue mb-3"><i class="bi <?=htmlspecialchars($q[1])?>"></i></div><h6 class="text-dark mb-2"><?=htmlspecialchars($q[2])?></h6><p class="small text-muted mb-0"><?=htmlspecialchars($q[3])?></p></div></a></div><?php endforeach; ?></div></div></div><?php endif; ?>
<?php if($latest): ?><div class="stagia-list-card"><div class="stagia-list-toolbar"><div><h5 class="mb-1">Dernières transmissions</h5><small class="text-muted">Résultats envoyés aux universités.</small></div><a class="btn btn-sm btn-primary-stagia" href="<?=BASE_URL?>/views/espace-hopital/resultats-stage.php"><i class="bi bi-send-check me-1"></i>Résultats</a></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>SESSION</th><th>UNIVERSITÉ</th><th>STAGIAIRES</th><th>NOTE</th><th>STATUT</th></tr></thead><tbody><?php foreach($latest as $x): ?><tr><td><strong><?=htmlspecialchars($x['campaign_title']??'—')?></strong><small class="d-block text-muted"><?=htmlspecialchars($x['campaign_code']??'')?></small></td><td><?=htmlspecialchars($x['university_name']??'—')?></td><td><?= (int)($x['total_students']??0) ?></td><td><strong><?= number_format((float)($x['avg_score']??0),2,',',' ') ?>%</strong></td><td><span class="badge bg-light text-dark border"><?=htmlspecialchars($x['statut']??'—')?></span></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</main>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

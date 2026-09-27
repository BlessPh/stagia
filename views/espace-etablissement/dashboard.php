<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
$eid=(int)currentEtablissementId($pdo);if(!$eid)exit('Aucun établissement associé à ce compte.');
$s=$pdo->prepare("SELECT * FROM etablissements WHERE id=? AND statut='VALIDE' LIMIT 1");$s->execute([$eid]);$etablissement=$s->fetch(PDO::FETCH_ASSOC);if(!$etablissement)exit('Établissement introuvable ou non actif.');

function uCount(PDO $pdo,string $sql,array $p=[]):int{try{$s=$pdo->prepare($sql);$s->execute($p);return(int)$s->fetchColumn();}catch(Throwable $e){error_log('[UNI DASH COUNT] '.$e->getMessage());return 0;}}
function uRows(PDO $pdo,string $sql,array $p=[]):array{try{$s=$pdo->prepare($sql);$s->execute($p);return$s->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){error_log('[UNI DASH ROWS] '.$e->getMessage());return[];}}
function uHasTable(PDO $pdo,string $t):bool{try{$s=$pdo->prepare("SHOW TABLES LIKE ?");$s->execute([$t]);return(bool)$s->fetchColumn();}catch(Throwable $e){return false;}}
function uHasCol(PDO $pdo,string $t,string $c):bool{try{$s=$pdo->prepare("SHOW COLUMNS FROM `$t` LIKE ?");$s->execute([$c]);return(bool)$s->fetchColumn();}catch(Throwable $e){return false;}}
function uDate(?string $v):string{if(!$v)return '—';$p=explode('-',substr($v,0,10));return count($p)===3?$p[2].'/'.$p[1].'/'.$p[0]:$v;}

$hasResults=uHasTable($pdo,'stage_result_transmissions')&&uHasTable($pdo,'stage_result_items');
$filieresWhere=uHasCol($pdo,'filieres','etablissement_id')?'WHERE etablissement_id=?':'';
$filieresParams=$filieresWhere?[$eid]:[];

$kpiStudents=uCount($pdo,"SELECT COUNT(*) FROM student_enrollments WHERE etablissement_id=?",[$eid]);
$kpiPrograms=uCount($pdo,"SELECT COUNT(*) FROM filieres $filieresWhere",$filieresParams);
$kpiCampaigns=uCount($pdo,"SELECT COUNT(*) FROM stage_campaigns WHERE owner_etablissement_id=? AND type_campagne='UNIVERSITAIRE'",[$eid]);
$kpiActive=uCount($pdo,"SELECT COUNT(DISTINCT a.id) FROM stage_assignments a JOIN stage_admissions ad ON ad.id=a.admission_id JOIN stage_reservations sr ON sr.id=ad.reservation_id JOIN stage_applications app ON app.id=sr.application_id JOIN stage_campaigns c ON c.id=app.campaign_id WHERE c.owner_etablissement_id=? AND a.statut IN('PLANIFIEE','ACTIVE')",[$eid]);
$kpiApps=uCount($pdo,"SELECT COUNT(*) FROM stage_applications app JOIN stage_campaigns c ON c.id=app.campaign_id WHERE c.owner_etablissement_id=? AND app.statut='SOUMISE'",[$eid]);
$kpiResults=$hasResults?uCount($pdo,"SELECT COUNT(*) FROM stage_result_transmissions WHERE university_etablissement_id=? AND statut='ENVOYE'",[$eid]):0;
$kpiValidated=$hasResults?uCount($pdo,"SELECT COUNT(*) FROM stage_result_transmissions WHERE university_etablissement_id=? AND statut IN('VALIDE','ARCHIVE')",[$eid]):0;

$latestResults=$hasResults?uRows($pdo,"
    SELECT t.id,t.statut,t.transmitted_at,t.received_at,t.validated_at,
           c.code campaign_code,c.titre campaign_title,h.nom hospital_name,
           COUNT(i.id) students,ROUND(AVG(i.final_score),2) avg_score,ROUND(AVG(i.presence_rate),2) presence_rate
    FROM stage_result_transmissions t
    JOIN stage_campaigns c ON c.id=t.campaign_id
    JOIN etablissements h ON h.id=t.host_etablissement_id
    LEFT JOIN stage_result_items i ON i.transmission_id=t.id
    WHERE t.university_etablissement_id=?
    GROUP BY t.id
    ORDER BY COALESCE(t.transmitted_at,t.created_at) DESC,t.id DESC
    LIMIT 6",[$eid]):[];

$latestApps=uRows($pdo,"
    SELECT app.id,app.statut,app.submitted_at,c.code campaign_code,c.titre campaign_title,h.nom hospital_name,sp.stagia_code,sp.nom,sp.postnom,sp.prenom
    FROM stage_applications app
    JOIN stage_campaigns c ON c.id=app.campaign_id
    LEFT JOIN etablissements h ON h.id=app.host_etablissement_id
    LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
    LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
    LEFT JOIN student_profiles sp ON sp.id=se.student_id
    WHERE c.owner_etablissement_id=?
    ORDER BY COALESCE(app.submitted_at,app.created_at) DESC,app.id DESC
    LIMIT 5",[$eid]);

$pageTitle='Tableau de bord';$activePage='dashboard';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">
<div class="stagia-page-head"><div><h1><?=htmlspecialchars($etablissement['nom'])?></h1><p>Espace d'administration de votre établissement.</p></div><span class="badge bg-success px-3 py-2"><i class="bi bi-patch-check me-1"></i> Établissement validé</span></div>
<div class="welcome-establishment mb-4"><div><span>BIENVENUE DANS VOTRE ESPACE</span><h2>Gérez votre établissement sur STAGIA-RDC</h2><p>Suivez vos étudiants, sessions, candidatures et résultats transmis par les hôpitaux.</p></div><div class="welcome-building"><i class="bi bi-building"></i></div></div>
<div class="stagia-kpi-grid mb-4">
    <div class="stagia-kpi-card"><div><span>ÉTUDIANTS</span><strong><?=$kpiStudents?></strong><small>Inscriptions enregistrées</small></div><div class="stagia-kpi-icon kpi-blue"><i class="bi bi-people"></i></div></div>
    <div class="stagia-kpi-card"><div><span>SESSIONS</span><strong><?=$kpiCampaigns?></strong><small>Campagnes universitaires</small></div><div class="stagia-kpi-icon kpi-purple"><i class="bi bi-calendar3"></i></div></div>
    <div class="stagia-kpi-card"><div><span>CANDIDATURES</span><strong><?=$kpiApps?></strong><small>À traiter</small></div><div class="stagia-kpi-icon kpi-orange"><i class="bi bi-file-earmark-person"></i></div></div>
    <div class="stagia-kpi-card"><div><span>RÉSULTATS</span><strong><?=$kpiResults?></strong><small>Envoyés par les hôpitaux</small></div><div class="stagia-kpi-icon kpi-green"><i class="bi bi-send-check"></i></div></div>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="<?=BASE_URL?>/views/etudiants/index.php"><div class="stagia-list-card p-4 h-100"><div class="stagia-kpi-icon kpi-blue mb-3"><i class="bi bi-person-plus"></i></div><h6>Étudiants</h6><p class="small text-muted mb-0">Liste, inscription et importation.</p></div></a></div>
    <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="<?=BASE_URL?>/views/stages/campagnes.php"><div class="stagia-list-card p-4 h-100"><div class="stagia-kpi-icon kpi-purple mb-3"><i class="bi bi-calendar-event"></i></div><h6>Sessions de stage</h6><p class="small text-muted mb-0">Créer et publier les sessions.</p></div></a></div>
    <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="<?=BASE_URL?>/views/stages/candidatures.php"><div class="stagia-list-card p-4 h-100"><div class="stagia-kpi-icon kpi-orange mb-3"><i class="bi bi-file-earmark-person"></i></div><h6>Candidatures</h6><p class="small text-muted mb-0">Traiter les choix des étudiants.</p></div></a></div>
    <div class="col-md-6 col-xl-3"><a class="text-decoration-none" href="<?=BASE_URL?>/views/espace-etablissement/resultats-stage.php"><div class="stagia-list-card p-4 h-100"><div class="stagia-kpi-icon kpi-green mb-3"><i class="bi bi-send-check"></i></div><h6>Résultats reçus</h6><p class="small text-muted mb-0">Valider les résultats transmis.</p></div></a></div>
</div>
<div class="row g-3">
<div class="col-xl-7"><div class="stagia-list-card"><div class="stagia-list-toolbar"><div><h5 class="mb-1">Derniers résultats reçus</h5><small class="text-muted">Résultats transmis par les structures d’accueil.</small></div><a class="btn btn-sm btn-primary-stagia" href="<?=BASE_URL?>/views/espace-etablissement/resultats-stage.php">Voir tout</a></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>SESSION</th><th>HÔPITAL</th><th>STAGIAIRES</th><th>NOTE</th><th>STATUT</th></tr></thead><tbody><?php if($latestResults):foreach($latestResults as $x): ?><tr><td><strong><?=htmlspecialchars($x['campaign_title']??'—')?></strong><small class="d-block text-muted"><?=htmlspecialchars($x['campaign_code']??'')?></small></td><td><?=htmlspecialchars($x['hospital_name']??'—')?></td><td><?= (int)($x['students']??0) ?></td><td><strong><?=number_format((float)($x['avg_score']??0),2,',',' ')?>%</strong><small class="d-block text-muted">Présence <?=number_format((float)($x['presence_rate']??0),2,',',' ')?>%</small></td><td><span class="badge bg-light text-dark border"><?=htmlspecialchars($x['statut']??'—')?></span></td></tr><?php endforeach;else: ?><tr><td colspan="5" class="text-center py-4 text-muted">Aucun résultat reçu pour le moment.</td></tr><?php endif; ?></tbody></table></div></div></div>
<div class="col-xl-5"><div class="stagia-list-card"><div class="stagia-list-toolbar"><div><h5 class="mb-1">Dernières candidatures</h5><small class="text-muted">Choix de stage récents.</small></div><a class="btn btn-sm btn-light border" href="<?=BASE_URL?>/views/stages/candidatures.php">Ouvrir</a></div><div class="table-responsive"><table class="table stagia-modern-table align-middle mb-0"><thead><tr><th>ÉTUDIANT</th><th>HÔPITAL</th><th>STATUT</th></tr></thead><tbody><?php if($latestApps):foreach($latestApps as $x): ?><tr><td><strong><?=htmlspecialchars(trim(($x['nom']??'').' '.($x['postnom']??'').' '.($x['prenom']??''))?:'Étudiant')?></strong><small class="d-block text-muted"><?=htmlspecialchars($x['stagia_code']??'')?></small></td><td><?=htmlspecialchars($x['hospital_name']??'—')?></td><td><span class="badge bg-light text-dark border"><?=htmlspecialchars($x['statut']??'—')?></span></td></tr><?php endforeach;else: ?><tr><td colspan="3" class="text-center py-4 text-muted">Aucune candidature récente.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
</main>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

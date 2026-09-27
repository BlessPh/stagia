<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);

$search=trim($_GET['q']??'');
$statut=trim($_GET['statut']??'');

$where=["t.academic_enabled=1","e.statut IN('VALIDE','ACTIF')"];
$params=[];

if($search!==''){
    $where[]="(e.nom LIKE ? OR e.code LIKE ? OR t.libelle LIKE ?)";
    $like="%$search%";
    array_push($params,$like,$like,$like);
}

if(in_array($statut,['A_CONFIGURER','EN_COURS','TERMINEE','NON_INITIALISEE'],true)){
    if($statut==='NON_INITIALISEE') $where[]="s.id IS NULL";
    else{
        $where[]="s.configuration_statut=?";
        $params[]=$statut;
    }
}

$sql="SELECT e.id,e.code,e.nom,e.type_etablissement,e.statut,t.libelle type_libelle,
    s.id settings_id,s.configuration_statut,s.source_template_version,
    m.nom modele_nom,m.code modele_code,
    (SELECT COUNT(*) FROM facultes u WHERE u.etablissement_id=e.id) nb_unites,
    (SELECT COUNT(*) FROM departements d WHERE d.etablissement_id=e.id) nb_departements,
    (SELECT COUNT(*) FROM filieres f WHERE f.etablissement_id=e.id) nb_filieres,
    (SELECT COUNT(*) FROM options_specialites o WHERE o.etablissement_id=e.id) nb_options,
    (SELECT COUNT(*) FROM promotions p WHERE p.etablissement_id=e.id) nb_promotions
    FROM etablissements e
    JOIN establishment_types t ON t.code=e.type_etablissement
    LEFT JOIN etablissement_academic_settings s ON s.etablissement_id=e.id
    LEFT JOIN academic_structure_templates m ON m.id=s.source_template_id
    WHERE ".implode(' AND ',$where)."
    ORDER BY
        CASE WHEN s.id IS NULL THEN 0
             WHEN s.configuration_statut='A_CONFIGURER' THEN 1
             WHEN s.configuration_statut='EN_COURS' THEN 2
             ELSE 3 END,
        e.nom";

$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$items=$stmt->fetchAll(PDO::FETCH_ASSOC);

$stats=[
    'total'=>count($items),
    'a_configurer'=>0,
    'en_cours'=>0,
    'terminees'=>0
];
foreach($items as $i){
    if(!$i['settings_id']) $stats['a_configurer']++;
    elseif($i['configuration_statut']==='A_CONFIGURER') $stats['a_configurer']++;
    elseif($i['configuration_statut']==='EN_COURS') $stats['en_cours']++;
    elseif($i['configuration_statut']==='TERMINEE') $stats['terminees']++;
}

$pageTitle='Configurations académiques';
$activePage='etablissements-config-academique';
require_once __DIR__.'/../../includes/app-header.php';

function badgeConfig(?string $status):array{
    return match($status){
        'A_CONFIGURER'=>['À configurer','secondary'],
        'EN_COURS'=>['En cours','warning text-dark'],
        'TERMINEE'=>['Terminée','success'],
        default=>['Non initialisée','danger']
    };
}
?>
<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <h1>Configurations académiques</h1>
        <p>Préparez la structure académique des établissements avant leur exploitation de STAGIA-RDC.</p>
    </div>
    <a href="<?= BASE_URL ?>/views/etablissements/create.php" class="btn btn-primary-stagia px-4">
        <i class="bi bi-plus-lg me-1"></i> Nouvel établissement
    </a>
</div>

<div class="stagia-kpi-grid">
    <div class="stagia-kpi-card">
        <div><span>ÉTABLISSEMENTS</span><strong><?= $stats['total'] ?></strong><small>Structures académiques</small></div>
        <div class="stagia-kpi-icon kpi-blue"><i class="bi bi-buildings"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>À CONFIGURER</span><strong><?= $stats['a_configurer'] ?></strong><small>Configuration à démarrer</small></div>
        <div class="stagia-kpi-icon kpi-orange"><i class="bi bi-hourglass-split"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>EN COURS</span><strong><?= $stats['en_cours'] ?></strong><small>Configurations commencées</small></div>
        <div class="stagia-kpi-icon kpi-purple"><i class="bi bi-sliders"></i></div>
    </div>
    <div class="stagia-kpi-card">
        <div><span>TERMINÉES</span><strong><?= $stats['terminees'] ?></strong><small>Structures prêtes</small></div>
        <div class="stagia-kpi-icon kpi-green"><i class="bi bi-check-circle"></i></div>
    </div>
</div>

<div class="stagia-list-card">

<form method="GET" class="stagia-list-toolbar">
    <div class="stagia-list-filters w-100">
        <div class="input-group stagia-table-search flex-grow-1">
            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
            <input type="search" name="q" class="form-control border-start-0"
                   value="<?= htmlspecialchars($search) ?>"
                   placeholder="Rechercher par établissement, code ou type...">
        </div>

        <select name="statut" class="form-select">
            <option value="">Tous les statuts</option>
            <option value="A_CONFIGURER" <?= $statut==='A_CONFIGURER'?'selected':'' ?>>À configurer</option>
            <option value="EN_COURS" <?= $statut==='EN_COURS'?'selected':'' ?>>En cours</option>
            <option value="TERMINEE" <?= $statut==='TERMINEE'?'selected':'' ?>>Terminée</option>
            <option value="NON_INITIALISEE" <?= $statut==='NON_INITIALISEE'?'selected':'' ?>>Non initialisée</option>
        </select>

        <button class="btn btn-primary-stagia px-4" type="submit">
            <i class="bi bi-funnel me-1"></i> Filtrer
        </button>

        <?php if($search!==''||$statut!==''): ?>
        <a href="<?= BASE_URL ?>/views/etablissements/configurations-academiques.php" class="btn btn-light border">
            <i class="bi bi-arrow-clockwise"></i>
        </a>
        <?php endif; ?>
    </div>
</form>

<div class="table-responsive">
<table class="table stagia-modern-table align-middle mb-0">
<thead>
<tr>
    <th>ÉTABLISSEMENT</th>
    <th>TYPE</th>
    <th>MODÈLE</th>
    <th>STRUCTURE CONFIGURÉE</th>
    <th>STATUT</th>
    <th class="text-center">ACTION</th>
</tr>
</thead>
<tbody>
<?php if(!$items): ?>
<tr>
    <td colspan="6" class="text-center py-5 text-muted">
        <i class="bi bi-search fs-2 d-block mb-2"></i>
        Aucun établissement académique trouvé.
    </td>
</tr>
<?php else: ?>
<?php foreach($items as $item):
    [$label,$color]=badgeConfig($item['configuration_statut']);
?>
<tr>
    <td>
        <strong class="table-main-text"><?= htmlspecialchars($item['nom']) ?></strong>
        <small class="d-block text-muted"><?= htmlspecialchars($item['code']) ?></small>
    </td>

    <td><?= htmlspecialchars($item['type_libelle']?:$item['type_etablissement']) ?></td>

    <td>
        <?php if($item['settings_id']): ?>
            <strong><?= htmlspecialchars($item['modele_nom']?:'—') ?></strong>
            <small class="d-block text-muted">
                <?= htmlspecialchars($item['modele_code']?:'') ?>
                <?= $item['source_template_version']?' · v'.(int)$item['source_template_version']:'' ?>
            </small>
        <?php else: ?>
            <span class="text-muted">Aucun modèle appliqué</span>
        <?php endif; ?>
    </td>

    <td>
        <small class="d-block">
            <strong><?= (int)$item['nb_unites'] ?></strong> unité(s) ·
            <strong><?= (int)$item['nb_departements'] ?></strong> département(s)
        </small>
        <small class="d-block text-muted">
            <?= (int)$item['nb_filieres'] ?> filière(s) ·
            <?= (int)$item['nb_options'] ?> option(s) ·
            <?= (int)$item['nb_promotions'] ?> promotion(s)
        </small>
    </td>

    <td><span class="badge bg-<?= $color ?>"><?= $label ?></span></td>

    <td class="text-center">
        <?php if($item['settings_id']): ?>
        <a href="<?= BASE_URL ?>/views/etablissements/configuration-academique.php?id=<?= (int)$item['id'] ?>"
           class="btn btn-sm btn-outline-primary" title="Configurer">
            <i class="bi bi-sliders2 me-1"></i>
            <?= $item['configuration_statut']==='TERMINEE'?'Voir / modifier':'Configurer' ?>
        </a>
        <?php else: ?>
        <button class="btn btn-sm btn-outline-secondary" disabled title="Le modèle académique doit d'abord être initialisé">
            <i class="bi bi-exclamation-circle me-1"></i> À initialiser
        </button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="stagia-list-footer">
    <span><?= count($items) ?> établissement(s) affiché(s)</span>
</div>

</div>
</main>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

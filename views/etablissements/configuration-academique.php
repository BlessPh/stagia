<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

$eid=(int)($_GET['id']??0);
if(!$eid){http_response_code(422);exit('Établissement invalide.');}

$stmt=$pdo->prepare("SELECT e.id,e.code,e.nom,e.type_etablissement,e.statut,
    t.libelle type_libelle,s.*,m.nom modele_nom,m.code modele_code
    FROM etablissements e
    LEFT JOIN establishment_types t ON t.code=e.type_etablissement
    JOIN etablissement_academic_settings s ON s.etablissement_id=e.id
    LEFT JOIN academic_structure_templates m ON m.id=s.source_template_id
    WHERE e.id=? LIMIT 1");
$stmt->execute([$eid]); $etab=$stmt->fetch(PDO::FETCH_ASSOC);

if(!$etab){http_response_code(404);exit('Configuration académique introuvable.');}

$counts=[];
foreach([
    'unites'=>"SELECT COUNT(*) FROM facultes WHERE etablissement_id=?",
    'departements'=>"SELECT COUNT(*) FROM departements WHERE etablissement_id=?",
    'filieres'=>"SELECT COUNT(*) FROM filieres WHERE etablissement_id=?",
    'options'=>"SELECT COUNT(*) FROM options_specialites WHERE etablissement_id=?",
    'promotions'=>"SELECT COUNT(*) FROM promotions WHERE etablissement_id=?"
] as $k=>$sql){
    $s=$pdo->prepare($sql); $s->execute([$eid]); $counts[$k]=(int)$s->fetchColumn();
}

$types=$pdo->prepare("SELECT type_unite,COALESCE(NULLIF(libelle,''),type_unite) libelle
    FROM etablissement_academic_unit_types
    WHERE etablissement_id=? AND actif=1 ORDER BY ordre,libelle");
$types->execute([$eid]); $types=$types->fetchAll(PDO::FETCH_ASSOC);

$pageTitle='Configuration académique';
$activePage='etablissements';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <a href="<?= BASE_URL ?>/views/etablissements/index.php" class="detail-back"><i class="bi bi-arrow-left"></i> Établissements</a>
        <h1>Configuration académique</h1>
        <p><strong><?= htmlspecialchars($etab['nom']) ?></strong> · <?= htmlspecialchars($etab['type_libelle']?:$etab['type_etablissement']) ?> · <?= htmlspecialchars($etab['code']) ?></p>
    </div>
    <span class="badge fs-6 bg-<?= $etab['configuration_statut']==='TERMINEE'?'success':($etab['configuration_statut']==='EN_COURS'?'warning text-dark':'secondary') ?>">
        <?= str_replace('_',' ',$etab['configuration_statut']) ?>
    </span>
</div>

<div class="alert alert-light border">
    <i class="bi bi-diagram-3 me-2 text-primary"></i>
    Modèle appliqué : <strong><?= htmlspecialchars($etab['modele_nom']?:'—') ?></strong>
    <span class="text-muted">· <?= htmlspecialchars($etab['modele_code']?:'') ?></span>
</div>

<div class="row g-3 mb-4">
<?php
$steps=[
    ['Unités académiques',$counts['unites'],'bi-buildings','primary',(int)$etab['unite_academique_active']],
    ['Départements',$counts['departements'],'bi-diagram-2','info',(int)$etab['departement_active']],
    ['Filières / Programmes',$counts['filieres'],'bi-mortarboard','success',(int)$etab['filiere_active']],
    ['Options / Spécialités',$counts['options'],'bi-signpost-split','warning',(int)$etab['option_specialite_active']],
    ['Promotions',$counts['promotions'],'bi-layers','secondary',(int)$etab['promotion_active']]
];
foreach($steps as $i=>$step): ?>
<div class="col-xl col-md-4 col-sm-6">
    <div class="card border-0 shadow-sm h-100 <?= !$step[4]?'opacity-50':'' ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <small class="text-muted">ÉTAPE <?= $i+1 ?></small>
                    <h6 class="mt-1 mb-2"><?= $step[0] ?></h6>
                    <strong class="fs-4"><?= $step[4]?$step[1]:'—' ?></strong>
                    <?php if(!$step[4]): ?><small class="d-block text-muted">Non activée par le modèle</small><?php endif; ?>
                </div>
                <i class="bi <?= $step[2] ?> fs-4 text-<?= $step[3] ?>"></i>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if((int)$etab['unite_academique_active']): ?>
<div class="stagia-list-card mb-4">
    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">Étape 1 — Unités académiques</h5>
            <p class="text-muted mb-0">Facultés, instituts/ISTM, écoles, sections ou centres autorisés par le modèle.</p>
        </div>
        <button class="btn btn-primary-stagia" onclick="nouvelleUnite()"><i class="bi bi-plus-lg me-1"></i> Nouvelle unité</button>
    </div>
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>CODE</th><th>TYPE</th><th>UNITÉ</th><th>PARENTE</th><th>DÉPART.</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
            <tbody id="unitesBody"><tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if((int)$etab['departement_active']): ?>
<div class="stagia-list-card mb-4">
    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">Étape 2 — Départements</h5>
            <p class="text-muted mb-0">
                Définissez les départements de l’établissement.
                <?php if(!(int)$etab['departement_obligatoire']): ?>
                    <span class="badge bg-light text-dark border ms-1">Facultatif selon le modèle</span>
                <?php endif; ?>
            </p>
        </div>
        <button class="btn btn-primary-stagia" onclick="nouveauDepartement()"><i class="bi bi-plus-lg me-1"></i> Nouveau département</button>
    </div>
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>CODE</th><th>DÉPARTEMENT</th><th>UNITÉ ACADÉMIQUE</th><th>FILIÈRES</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
            <tbody id="departementsBody"><tr><td colspan="6" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<?php if((int)$etab['filiere_active']): ?>
<div class="stagia-list-card mb-4">
    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">Étape 3 — Filières / Programmes</h5>
            <p class="text-muted mb-0">
                Chaque filière représente un parcours académique et prépare son futur référentiel de stage.
                <?php if((int)$etab['filiere_obligatoire']): ?><span class="badge bg-light text-dark border ms-1">Obligatoire</span><?php endif; ?>
            </p>
        </div>
        <button class="btn btn-primary-stagia" onclick="nouvelleFiliere()"><i class="bi bi-plus-lg me-1"></i> Nouvelle filière</button>
    </div>
    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>CODE</th><th>FILIÈRE / PROGRAMME</th><th>RATTACHEMENT</th><th>DURÉE</th><th>OPTIONS</th><th>PROMOTIONS</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
            <tbody id="filieresBody"><tr><td colspan="8" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<?php if((int)$etab['option_specialite_active']): ?>
<div class="stagia-list-card mb-4">
    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">Étape 4 — Options / Spécialités</h5>
            <p class="text-muted mb-0">
                Ajoutez uniquement les options ou spécialités réellement utilisées par les filières de cet établissement.
                <?php if(!(int)$etab['option_specialite_obligatoire']): ?>
                    <span class="badge bg-light text-dark border ms-1">Facultatif selon le modèle</span>
                <?php else: ?>
                    <span class="badge bg-light text-dark border ms-1">Obligatoire</span>
                <?php endif; ?>
            </p>
        </div>
        <button class="btn btn-primary-stagia" onclick="nouvelleOption()" <?= $counts['filieres']<1?'disabled':'' ?>>
            <i class="bi bi-plus-lg me-1"></i> Nouvelle option / spécialité
        </button>
    </div>

    <?php if($counts['filieres']<1): ?>
    <div class="alert alert-warning m-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i> Créez d’abord au moins une filière / programme.</div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr><th>CODE</th><th>OPTION / SPÉCIALITÉ</th><th>FILIÈRE</th><th>PROMOTIONS</th><th>RÉFÉRENTIELS</th><th>STATUT</th><th class="text-center">ACTIONS</th></tr></thead>
            <tbody id="optionsBody"><tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<?php if((int)$etab['promotion_active']): ?>
<div class="stagia-list-card mb-4">
    <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-1">Étape 5 — Promotions / Niveaux</h5>
            <p class="text-muted mb-0">
                Définissez les niveaux réellement utilisés dans chaque filière, par exemple L1, L2, L3, D1, D2, D3, D4 ou B1, B2, B3 selon le cursus.
                <?php if((int)$etab['promotion_obligatoire']): ?>
                    <span class="badge bg-light text-dark border ms-1">Obligatoire</span>
                <?php endif; ?>
            </p>
        </div>
        <button class="btn btn-primary-stagia" onclick="nouvellePromotion()" <?= $counts['filieres']<1?'disabled':'' ?>>
            <i class="bi bi-plus-lg me-1"></i> Nouvelle promotion
        </button>
    </div>

    <?php if($counts['filieres']<1): ?>
    <div class="alert alert-warning m-3 mb-0">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Créez d’abord au moins une filière / programme.
    </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table stagia-modern-table align-middle mb-0">
            <thead><tr>
                <th>CODE</th>
                <th>PROMOTION</th>
                <th>NIVEAU</th>
                <th>FILIÈRE</th>
                <th>OPTION / SPÉCIALITÉ</th>
                <th>CONFIG. STAGE</th>
                <th>ÉTUDIANTS EN COURS</th>
                <th>STATUT</th>
                <th class="text-center">ACTIONS</th>
            </tr></thead>
            <tbody id="promotionsBody">
                <tr><td colspan="9" class="text-center py-5"><div class="spinner-border spinner-border-sm me-2"></div>Chargement...</td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-warning d-flex gap-2">
    <i class="bi bi-lock"></i>
    <div><strong>Configuration encore en cours.</strong><br>
        L’accès de l’établissement ne sera finalisé qu’après Filières/Programmes → Options/Spécialités → Promotions → paramètres de stage par promotion.</div>
</div>

</main>

<?php if((int)$etab['unite_academique_active']): ?>
<div class="modal fade" id="uniteModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="uniteForm">
<div class="modal-header">
    <div><h5 class="modal-title" id="uniteTitle">Nouvelle unité académique</h5><small class="text-muted">Configuration réalisée par l’administration nationale.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="etablissement_id" value="<?= $eid ?>">
<input type="hidden" name="id" id="uniteId">
<div class="mb-3"><label class="form-label">Type *</label>
<select name="type_unite" id="uniteType" class="form-select" required>
<option value="">Sélectionner...</option>
<?php foreach($types as $t): ?><option value="<?= htmlspecialchars($t['type_unite']) ?>"><?= htmlspecialchars($t['libelle']) ?></option><?php endforeach; ?>
</select></div>
<div class="mb-3"><label class="form-label">Nom *</label><input name="nom" id="uniteNom" class="form-control" placeholder="Ex. Faculté de Médecine" required></div>
<div class="mb-0"><label class="form-label">Unité parente <span class="text-muted">(facultatif)</span></label>
<select name="parent_id" id="uniteParent" class="form-select"><option value="">Aucune</option></select></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="uniteSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>
<?php endif; ?>

<?php if((int)$etab['departement_active']): ?>
<div class="modal fade" id="departementModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="departementForm">
<div class="modal-header">
    <div><h5 class="modal-title" id="departementTitle">Nouveau département</h5><small class="text-muted">Rattachement à une unité académique si l’organisation réelle le prévoit.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="etablissement_id" value="<?= $eid ?>">
<input type="hidden" name="id" id="departementId">
<div class="mb-3"><label class="form-label">Nom du département *</label><input name="nom" id="departementNom" class="form-control" placeholder="Ex. Sciences cliniques" required></div>
<div class="mb-0"><label class="form-label">Unité académique <span class="text-muted">(facultatif)</span></label>
<select name="faculte_id" id="departementUnite" class="form-select"><option value="">Aucune / directement sous l’établissement</option></select>
<small class="text-muted">Le rattachement reste facultatif pour respecter les structures réelles des établissements.</small></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="departementSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>
<?php endif; ?>


<?php if((int)$etab['filiere_active']): ?>
<div class="modal fade" id="filiereModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="filiereForm">
<div class="modal-header">
    <div><h5 class="modal-title" id="filiereTitle">Nouvelle filière / programme</h5><small class="text-muted">Le rattachement dépend de l’organisation réelle autorisée par le modèle.</small></div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="etablissement_id" value="<?= $eid ?>">
<input type="hidden" name="id" id="filiereId">

<div class="row g-3">
    <div class="col-md-8"><label class="form-label">Nom de la filière / programme *</label><input name="nom" id="filiereNom" class="form-control" placeholder="Ex. Médecine générale" required></div>
    <div class="col-md-4"><label class="form-label">Durée du cursus <span class="text-muted">(années)</span></label><input type="number" min="1" max="20" name="duree_annees" id="filiereDuree" class="form-control" placeholder="Ex. 7"></div>

    <div class="col-md-5"><label class="form-label">Type de rattachement *</label>
        <select name="rattachement_type" id="filiereMode" class="form-select" required></select>
    </div>
    <div class="col-md-7"><label class="form-label">Rattachement *</label>
        <select name="parent_id" id="filiereParent" class="form-select"></select>
    </div>

    <div class="col-12"><label class="form-label">Description</label><textarea name="description" id="filiereDescription" class="form-control" rows="2" placeholder="Informations complémentaires sur le parcours."></textarea></div>
</div>

<div class="alert alert-light border mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Une filière pourra ensuite recevoir ses <strong>Options / Spécialités</strong>, ses <strong>Promotions</strong> et son <strong>référentiel de stage</strong>.
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="filiereSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>
<?php endif; ?>



<?php if((int)$etab['option_specialite_active']): ?>
<div class="modal fade" id="optionModal" tabindex="-1">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="optionForm">
<div class="modal-header">
    <div>
        <h5 class="modal-title" id="optionTitle">Nouvelle option / spécialité</h5>
        <small class="text-muted">Une option / spécialité appartient toujours à une filière.</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="etablissement_id" value="<?= $eid ?>">
<input type="hidden" name="id" id="optionId">

<div class="mb-3">
    <label class="form-label">Filière / Programme *</label>
    <select name="filiere_id" id="optionFiliere" class="form-select" required></select>
</div>

<div class="mb-3">
    <label class="form-label">Nom de l’option / spécialité *</label>
    <input name="nom" id="optionNom" class="form-control" placeholder="Ex. Laboratoire, Kinésithérapie, Anesthésie-Réanimation" required>
</div>

<div class="mb-0">
    <label class="form-label">Description</label>
    <textarea name="description" id="optionDescription" class="form-control" rows="2"></textarea>
</div>

<div class="alert alert-light border mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Certaines filières n’ont aucune option. Dans ce cas, cette étape peut rester vide lorsque le modèle la définit comme facultative.
</div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="optionSaveBtn"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
</div>
</form>
</div></div>
</div>
<?php endif; ?>



<?php if((int)$etab['promotion_active']): ?>
<div class="modal fade" id="promotionModal" tabindex="-1">
<div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content border-0 shadow">
<form id="promotionForm">
<div class="modal-header">
    <div>
        <h5 class="modal-title" id="promotionTitle">Nouvelle promotion</h5>
        <small class="text-muted">Une promotion correspond au niveau académique d’un parcours.</small>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">
<input type="hidden" name="etablissement_id" value="<?= $eid ?>">
<input type="hidden" name="id" id="promotionId">

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">Filière / Programme *</label>
        <select name="filiere_id" id="promotionFiliere" class="form-select" required></select>
    </div>

    <div class="col-md-6" id="promotionOptionWrap">
        <label class="form-label">Option / Spécialité <span class="text-muted" id="promotionOptionHint">(facultatif)</span></label>
        <select name="option_specialite_id" id="promotionOption" class="form-select"></select>
    </div>

    <div class="col-md-4">
        <label class="form-label">Niveau *</label>
        <input name="niveau" id="promotionNiveau" class="form-control" placeholder="Ex. L1, B1, D4" required>
    </div>

    <div class="col-md-8">
        <label class="form-label">Nom de la promotion *</label>
        <input name="nom" id="promotionNom" class="form-control" placeholder="Ex. Licence 1, Première année doctorat" required>
    </div>

    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" id="promotionDescription" class="form-control" rows="2"></textarea>
    </div>
</div>

<div class="alert alert-light border mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Les paramètres du stage — type, durée, période, heures minimales, crédits, rapport et soutenance — seront configurés à l’étape suivante pour chaque promotion et année académique.
</div>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Annuler</button>
    <button type="submit" class="btn btn-primary-stagia" id="promotionSaveBtn">
        <i class="bi bi-check-lg me-1"></i> Enregistrer
    </button>
</div>
</form>
</div></div>
</div>
<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',EID=<?= $eid ?>,$=id=>document.getElementById(id);
let unites=[],uniteTypes={},departements=[],filieres=[],filiereCfg={},options=[],promotions=[],promotionCfg={};

<?php if((int)$etab['unite_academique_active']): ?>
const uniteModal=new bootstrap.Modal($('uniteModal')),uniteForm=$('uniteForm');

function fillUniteParents(current=0,selected=''){
    $('uniteParent').innerHTML='<option value="">Aucune</option>'+unites
        .filter(x=>Number(x.actif)===1&&Number(x.id)!==Number(current))
        .map(x=>`<option value="${x.id}" ${String(x.id)===String(selected)?'selected':''}>${STAGIA.escape(x.nom)}</option>`).join('');
}

async function chargerUnites(){
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/etablissements/academique/unite-list.php?etablissement_id=${EID}`);
        unites=r.data.items||[];
        uniteTypes=Object.fromEntries((r.data.types||[]).map(t=>[t.type_unite,t.libelle]));
        $('unitesBody').innerHTML=unites.length?unites.map(u=>`<tr>
            <td><strong>${STAGIA.escape(u.code||'-')}</strong></td>
            <td><span class="badge bg-light text-dark border">${STAGIA.escape(uniteTypes[u.type_unite]||u.type_unite)}</span></td>
            <td><strong>${STAGIA.escape(u.nom)}</strong>${Number(u.nb_enfants||0)?`<small class="d-block text-muted">${u.nb_enfants} sous-unité(s)</small>`:''}</td>
            <td>${STAGIA.escape(u.parent_nom||'—')}</td><td>${Number(u.nb_departements||0)}</td>
            <td><span class="badge bg-${Number(u.actif)===1?'success':'secondary'}">${Number(u.actif)===1?'Active':'Inactive'}</span></td>
            <td class="text-center"><button class="btn btn-sm btn-outline-primary edit-unite" data-id="${u.id}"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-primary status-unite" data-id="${u.id}" data-actif="${u.actif}"><i class="bi bi-${Number(u.actif)===1?'pause-circle':'play-circle'}"></i></button></td>
        </tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune unité académique configurée.</td></tr>';

        document.querySelectorAll('.edit-unite').forEach(b=>b.onclick=()=>editerUnite(unites.find(x=>Number(x.id)===Number(b.dataset.id))));
        document.querySelectorAll('.status-unite').forEach(b=>b.onclick=()=>changerStatutUnite(Number(b.dataset.id),Number(b.dataset.actif)));
    }catch(e){
        $('unitesBody').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

window.nouvelleUnite=()=>{
    uniteForm.reset();$('uniteId').value='';$('uniteTitle').textContent='Nouvelle unité académique';fillUniteParents();uniteModal.show();
};

function editerUnite(u){
    if(!u)return;
    uniteForm.reset();$('uniteId').value=u.id;$('uniteType').value=u.type_unite;$('uniteNom').value=u.nom;
    $('uniteTitle').textContent='Modifier l’unité';fillUniteParents(u.id,u.parent_id||'');uniteModal.show();
}

uniteForm.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('uniteSaveBtn'),true);
    const url=BASE_URL+'/actions/etablissements/academique/'+($('uniteId').value?'unite-update.php':'unite-store.php');
    try{
        const r=await STAGIA.post(url,uniteForm);uniteModal.hide();STAGIA.toast(r.message);await chargerUnites();
        <?php if((int)$etab['departement_active']): ?>await chargerDepartements();<?php endif; ?>
        <?php if((int)$etab['filiere_active']): ?>await chargerFilieres();<?php endif; ?>
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('uniteSaveBtn'),false);}
};

async function changerStatutUnite(id,actif){
    if(!STAGIA.confirm(actif?'Désactiver cette unité ?':'Activer cette unité ?'))return;
    const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('etablissement_id',EID);d.append('id',id);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/etablissements/academique/unite-status.php',d);
        STAGIA.toast(r.message);await chargerUnites();
        <?php if((int)$etab['departement_active']): ?>await chargerDepartements();<?php endif; ?>
    }catch(e){STAGIA.toast(e.message,'danger');}
}
<?php endif; ?>

<?php if((int)$etab['departement_active']): ?>
const departementModal=new bootstrap.Modal($('departementModal')),departementForm=$('departementForm');

function fillDepartementUnits(selected=''){
    $('departementUnite').innerHTML='<option value="">Aucune / directement sous l’établissement</option>'+unites
        .filter(x=>Number(x.actif)===1)
        .map(x=>`<option value="${x.id}" ${String(x.id)===String(selected)?'selected':''}>${STAGIA.escape(x.nom)} · ${STAGIA.escape(uniteTypes[x.type_unite]||x.type_unite)}</option>`).join('');
}

async function chargerDepartements(){
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/etablissements/academique/departement-list.php?etablissement_id=${EID}`);
        departements=r.data.items||[];

        /* Si les unités n'ont pas encore été chargées ou sont désactivées */
        if(!unites.length){
            unites=(r.data.units||[]).map(u=>({...u}));
            (r.data.units||[]).forEach(u=>{if(!uniteTypes[u.type_unite])uniteTypes[u.type_unite]=u.type_unite;});
        }

        $('departementsBody').innerHTML=departements.length?departements.map(d=>`<tr>
            <td><strong>${STAGIA.escape(d.code||'-')}</strong></td>
            <td><strong>${STAGIA.escape(d.nom)}</strong></td>
            <td>${STAGIA.escape(d.unite_nom||'Directement sous l’établissement')}</td>
            <td><span class="badge bg-light text-dark border">${Number(d.nb_filieres||0)}</span></td>
            <td><span class="badge bg-${Number(d.actif)===1?'success':'secondary'}">${Number(d.actif)===1?'Actif':'Inactif'}</span></td>
            <td class="text-center"><button class="btn btn-sm btn-outline-primary edit-dep" data-id="${d.id}"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-primary status-dep" data-id="${d.id}" data-actif="${d.actif}"><i class="bi bi-${Number(d.actif)===1?'pause-circle':'play-circle'}"></i></button></td>
        </tr>`).join(''):'<tr><td colspan="6" class="text-center py-5 text-muted">Aucun département configuré.</td></tr>';

        document.querySelectorAll('.edit-dep').forEach(b=>b.onclick=()=>editerDepartement(departements.find(x=>Number(x.id)===Number(b.dataset.id))));
        document.querySelectorAll('.status-dep').forEach(b=>b.onclick=()=>changerStatutDepartement(Number(b.dataset.id),Number(b.dataset.actif)));
    }catch(e){
        $('departementsBody').innerHTML=`<tr><td colspan="6" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

window.nouveauDepartement=()=>{
    departementForm.reset();$('departementId').value='';$('departementTitle').textContent='Nouveau département';fillDepartementUnits();departementModal.show();
};

function editerDepartement(d){
    if(!d)return;
    departementForm.reset();$('departementId').value=d.id;$('departementNom').value=d.nom;
    $('departementTitle').textContent='Modifier le département';fillDepartementUnits(d.faculte_id||'');departementModal.show();
}

departementForm.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('departementSaveBtn'),true);
    const url=BASE_URL+'/actions/etablissements/academique/'+($('departementId').value?'departement-update.php':'departement-store.php');
    try{
        const r=await STAGIA.post(url,departementForm);departementModal.hide();STAGIA.toast(r.message);
        await chargerDepartements();
        <?php if((int)$etab['unite_academique_active']): ?>await chargerUnites();<?php endif; ?>
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('departementSaveBtn'),false);}
};

async function changerStatutDepartement(id,actif){
    if(!STAGIA.confirm(actif?'Désactiver ce département ?':'Activer ce département ?'))return;
    const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('etablissement_id',EID);d.append('id',id);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/etablissements/academique/departement-status.php',d);
        STAGIA.toast(r.message);await chargerDepartements();
    }catch(e){STAGIA.toast(e.message,'danger');}
}
<?php endif; ?>


<?php if((int)$etab['filiere_active']): ?>
const filiereModal=new bootstrap.Modal($('filiereModal')),filiereForm=$('filiereForm');

function filiereModeOptions(selected=''){
    const opts=[];
    if(filiereCfg.allow_department) opts.push(['DEPARTEMENT','Département']);
    if(filiereCfg.allow_unit) opts.push(['UNITE','Unité académique']);
    if(filiereCfg.allow_establishment) opts.push(['ETABLISSEMENT','Directement sous l’établissement']);
    $('filiereMode').innerHTML='<option value="">Sélectionner...</option>'+opts
        .map(([v,l])=>`<option value="${v}" ${v===selected?'selected':''}>${l}</option>`).join('');
}
function fillFiliereParents(mode,selected=''){
    const el=$('filiereParent');
    if(mode==='DEPARTEMENT'){
        el.disabled=false;
        el.innerHTML='<option value="">Sélectionner un département...</option>'+departements
            .filter(d=>Number(d.actif)===1)
            .map(d=>`<option value="${d.id}" ${String(d.id)===String(selected)?'selected':''}>${STAGIA.escape(d.nom)}${d.unite_nom?' · '+STAGIA.escape(d.unite_nom):''}</option>`).join('');
    }else if(mode==='UNITE'){
        el.disabled=false;
        el.innerHTML='<option value="">Sélectionner une unité...</option>'+unites
            .filter(u=>Number(u.actif)===1)
            .map(u=>`<option value="${u.id}" ${String(u.id)===String(selected)?'selected':''}>${STAGIA.escape(u.nom)} · ${STAGIA.escape(uniteTypes[u.type_unite]||u.type_unite)}</option>`).join('');
    }else{
        el.innerHTML='<option value="">Établissement</option>';el.disabled=true;
    }
}

async function chargerFilieres(){
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/etablissements/academique/filiere-list.php?etablissement_id=${EID}`);
        filieres=r.data.items||[];filiereCfg=r.data||{};
        if(!unites.length)unites=r.data.units||[];
        if(!departements.length)departements=r.data.departments||[];

        $('filieresBody').innerHTML=filieres.length?filieres.map(f=>{
            const parent=f.rattachement_type==='DEPARTEMENT'
                ?`Département · ${f.departement_nom||'—'}`
                :f.rattachement_type==='UNITE'
                    ?`Unité · ${f.unite_nom||'—'}`
                    :'Établissement';
            return `<tr>
                <td><strong>${STAGIA.escape(f.code||'-')}</strong></td>
                <td><strong>${STAGIA.escape(f.nom)}</strong>${f.description?`<small class="d-block text-muted">${STAGIA.escape(f.description)}</small>`:''}</td>
                <td><span class="badge bg-light text-dark border">${STAGIA.escape(parent)}</span></td>
                <td>${f.duree_annees?`${Number(f.duree_annees)} an(s)`:'—'}</td>
                <td>${Number(f.nb_options||0)}</td><td>${Number(f.nb_promotions||0)}</td>
                <td><span class="badge bg-${Number(f.actif)===1?'success':'secondary'}">${Number(f.actif)===1?'Active':'Inactive'}</span></td>
                <td class="text-center"><button class="btn btn-sm btn-outline-primary edit-fil" data-id="${f.id}"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-primary status-fil" data-id="${f.id}" data-actif="${f.actif}"><i class="bi bi-${Number(f.actif)===1?'pause-circle':'play-circle'}"></i></button></td>
            </tr>`;
        }).join(''):'<tr><td colspan="8" class="text-center py-5 text-muted">Aucune filière / programme configuré.</td></tr>';

        document.querySelectorAll('.edit-fil').forEach(b=>b.onclick=()=>editerFiliere(filieres.find(x=>Number(x.id)===Number(b.dataset.id))));
        document.querySelectorAll('.status-fil').forEach(b=>b.onclick=()=>changerStatutFiliere(Number(b.dataset.id),Number(b.dataset.actif)));
    }catch(e){
        $('filieresBody').innerHTML=`<tr><td colspan="8" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

window.nouvelleFiliere=()=>{
    filiereForm.reset();$('filiereId').value='';$('filiereTitle').textContent='Nouvelle filière / programme';
    filiereModeOptions();fillFiliereParents('');filiereModal.show();
};

function editerFiliere(f){
    if(!f)return;
    filiereForm.reset();$('filiereId').value=f.id;$('filiereNom').value=f.nom||'';$('filiereDuree').value=f.duree_annees||'';
    $('filiereDescription').value=f.description||'';$('filiereTitle').textContent='Modifier la filière / programme';
    filiereModeOptions(f.rattachement_type);
    const parent=f.rattachement_type==='DEPARTEMENT'?f.departement_id:(f.rattachement_type==='UNITE'?f.faculte_id:'');
    fillFiliereParents(f.rattachement_type,parent||'');filiereModal.show();
}

$('filiereMode').onchange=e=>fillFiliereParents(e.target.value);

filiereForm.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('filiereSaveBtn'),true);
    const data=new FormData(filiereForm);
    if($('filiereParent').disabled)data.set('parent_id','');
    const url=BASE_URL+'/actions/etablissements/academique/'+($('filiereId').value?'filiere-update.php':'filiere-store.php');
    try{
        const r=await STAGIA.post(url,data);filiereModal.hide();STAGIA.toast(r.message);await chargerFilieres();
        <?php if((int)$etab['departement_active']): ?>await chargerDepartements();<?php endif; ?>
        <?php if((int)$etab['option_specialite_active']): ?>await chargerOptions();<?php endif; ?>
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('filiereSaveBtn'),false);}
};

async function changerStatutFiliere(id,actif){
    if(!STAGIA.confirm(actif?'Désactiver cette filière ?':'Activer cette filière ?'))return;
    const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('etablissement_id',EID);d.append('id',id);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/etablissements/academique/filiere-status.php',d);
        STAGIA.toast(r.message);await chargerFilieres();
    }catch(e){STAGIA.toast(e.message,'danger');}
}
<?php endif; ?>



<?php if((int)$etab['option_specialite_active']): ?>
const optionModal=new bootstrap.Modal($('optionModal')),optionForm=$('optionForm');

function fillOptionFilieres(selected=''){
    $('optionFiliere').innerHTML='<option value="">Sélectionner une filière...</option>'+filieres
        .filter(f=>Number(f.actif)===1)
        .map(f=>`<option value="${f.id}" ${String(f.id)===String(selected)?'selected':''}>${STAGIA.escape(f.nom)}</option>`).join('');
}

async function chargerOptions(){
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/etablissements/academique/option-list.php?etablissement_id=${EID}`);
        options=r.data.items||[];

        if(!filieres.length){
            filieres=(r.data.filieres||[]).map(f=>({...f}));
        }

        $('optionsBody').innerHTML=options.length?options.map(o=>`<tr>
            <td><strong>${STAGIA.escape(o.code||'-')}</strong></td>
            <td><strong>${STAGIA.escape(o.nom)}</strong>${o.description?`<small class="d-block text-muted">${STAGIA.escape(o.description)}</small>`:''}</td>
            <td>${STAGIA.escape(o.filiere_nom||'—')}</td>
            <td><span class="badge bg-light text-dark border">${Number(o.nb_promotions||0)}</span></td>
            <td><span class="badge bg-light text-dark border">${Number(o.nb_referentiels||0)}</span></td>
            <td><span class="badge bg-${Number(o.actif)===1?'success':'secondary'}">${Number(o.actif)===1?'Active':'Inactive'}</span></td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-primary edit-option" data-id="${o.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-primary status-option" data-id="${o.id}" data-actif="${o.actif}" title="${Number(o.actif)===1?'Désactiver':'Activer'}"><i class="bi bi-${Number(o.actif)===1?'pause-circle':'play-circle'}"></i></button>
            </td>
        </tr>`).join(''):'<tr><td colspan="7" class="text-center py-5 text-muted">Aucune option / spécialité configurée.</td></tr>';

        document.querySelectorAll('.edit-option').forEach(b=>b.onclick=()=>editerOption(options.find(x=>Number(x.id)===Number(b.dataset.id))));
        document.querySelectorAll('.status-option').forEach(b=>b.onclick=()=>changerStatutOption(Number(b.dataset.id),Number(b.dataset.actif)));
    }catch(e){
        $('optionsBody').innerHTML=`<tr><td colspan="7" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

window.nouvelleOption=()=>{
    optionForm.reset();$('optionId').value='';$('optionTitle').textContent='Nouvelle option / spécialité';fillOptionFilieres();optionModal.show();
};

function editerOption(o){
    if(!o)return;
    optionForm.reset();$('optionId').value=o.id;$('optionNom').value=o.nom||'';$('optionDescription').value=o.description||'';
    $('optionTitle').textContent='Modifier l’option / spécialité';fillOptionFilieres(o.filiere_id||'');optionModal.show();
}

optionForm.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('optionSaveBtn'),true);
    const url=BASE_URL+'/actions/etablissements/academique/'+($('optionId').value?'option-update.php':'option-store.php');
    try{
        const r=await STAGIA.post(url,optionForm);optionModal.hide();STAGIA.toast(r.message);await chargerOptions();
        <?php if((int)$etab['filiere_active']): ?>await chargerFilieres();<?php endif; ?>
        <?php if((int)$etab['promotion_active']): ?>await chargerPromotions();<?php endif; ?>
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('optionSaveBtn'),false);}
};

async function changerStatutOption(id,actif){
    if(!STAGIA.confirm(actif?'Désactiver cette option / spécialité ?':'Activer cette option / spécialité ?'))return;
    const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('etablissement_id',EID);d.append('id',id);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/etablissements/academique/option-status.php',d);
        STAGIA.toast(r.message);await chargerOptions();
    }catch(e){STAGIA.toast(e.message,'danger');}
}
<?php endif; ?>



<?php if((int)$etab['promotion_active']): ?>
const promotionModal=new bootstrap.Modal($('promotionModal')),promotionForm=$('promotionForm');

function fillPromotionFilieres(selected=''){
    $('promotionFiliere').innerHTML='<option value="">Sélectionner une filière...</option>'+filieres
        .filter(f=>Number(f.actif)===1)
        .map(f=>`<option value="${f.id}" ${String(f.id)===String(selected)?'selected':''}>${STAGIA.escape(f.nom)}</option>`).join('');
}

function fillPromotionOptions(filiereId,selected=''){
    const wrap=$('promotionOptionWrap'),el=$('promotionOption');
    const enabled=!!promotionCfg.option_enabled,required=!!promotionCfg.option_required;

    wrap.classList.toggle('d-none',!enabled);
    el.required=enabled&&required;

    if(!enabled){
        el.innerHTML='<option value="">Aucune</option>';
        return;
    }

    const a=options.filter(o=>Number(o.actif)===1&&Number(o.filiere_id)===Number(filiereId));
    el.innerHTML='<option value="">'+(required?'Sélectionner...':'Aucune / sans option')+'</option>'+a
        .map(o=>`<option value="${o.id}" ${String(o.id)===String(selected)?'selected':''}>${STAGIA.escape(o.nom)}</option>`).join('');

    $('promotionOptionHint').textContent=required?'(obligatoire)':'(facultatif)';
}

async function chargerPromotions(){
    try{
        const r=await STAGIA.request(`${BASE_URL}/actions/etablissements/academique/promotion-list.php?etablissement_id=${EID}`);
        promotions=r.data.items||[];promotionCfg=r.data||{};

        if(!filieres.length)filieres=r.data.filieres||[];
        if(!options.length)options=r.data.options||[];

        $('promotionsBody').innerHTML=promotions.length?promotions.map(p=>`<tr>
            <td><strong>${STAGIA.escape(p.code||'-')}</strong></td>
            <td><strong>${STAGIA.escape(p.nom)}</strong>${p.description?`<small class="d-block text-muted">${STAGIA.escape(p.description)}</small>`:''}</td>
            <td><span class="badge bg-light text-dark border">${STAGIA.escape(p.niveau||'—')}</span></td>
            <td>${STAGIA.escape(p.filiere_nom||'—')}</td>
            <td>${STAGIA.escape(p.option_nom||'—')}</td>
            <td><span class="badge bg-light text-dark border">${Number(p.nb_stage_configs||0)}</span></td>
            <td><span class="badge bg-light text-dark border">${Number(p.nb_etudiants_en_cours||0)}</span></td>
            <td><span class="badge bg-${Number(p.actif)===1?'success':'secondary'}">${Number(p.actif)===1?'Active':'Inactive'}</span></td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-primary edit-promotion" data-id="${p.id}" title="Modifier"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-primary status-promotion" data-id="${p.id}" data-actif="${p.actif}" title="${Number(p.actif)===1?'Désactiver':'Activer'}"><i class="bi bi-${Number(p.actif)===1?'pause-circle':'play-circle'}"></i></button>
            </td>
        </tr>`).join(''):'<tr><td colspan="9" class="text-center py-5 text-muted">Aucune promotion configurée.</td></tr>';

        document.querySelectorAll('.edit-promotion').forEach(b=>b.onclick=()=>editerPromotion(promotions.find(x=>Number(x.id)===Number(b.dataset.id))));
        document.querySelectorAll('.status-promotion').forEach(b=>b.onclick=()=>changerStatutPromotion(Number(b.dataset.id),Number(b.dataset.actif)));
    }catch(e){
        $('promotionsBody').innerHTML=`<tr><td colspan="9" class="text-center py-5 text-danger">${STAGIA.escape(e.message)}</td></tr>`;
        STAGIA.toast(e.message,'danger');
    }
}

window.nouvellePromotion=()=>{
    promotionForm.reset();$('promotionId').value='';$('promotionTitle').textContent='Nouvelle promotion';
    fillPromotionFilieres();fillPromotionOptions('');promotionModal.show();
};

function editerPromotion(p){
    if(!p)return;
    promotionForm.reset();
    $('promotionId').value=p.id;
    $('promotionNiveau').value=p.niveau||'';
    $('promotionNom').value=p.nom||'';
    $('promotionDescription').value=p.description||'';
    $('promotionTitle').textContent='Modifier la promotion';
    fillPromotionFilieres(p.filiere_id);
    fillPromotionOptions(p.filiere_id,p.option_specialite_id||'');
    promotionModal.show();
}

$('promotionFiliere').onchange=e=>fillPromotionOptions(e.target.value);

promotionForm.onsubmit=async e=>{
    e.preventDefault();STAGIA.loading($('promotionSaveBtn'),true);
    const url=BASE_URL+'/actions/etablissements/academique/'+($('promotionId').value?'promotion-update.php':'promotion-store.php');
    try{
        const r=await STAGIA.post(url,promotionForm);
        promotionModal.hide();STAGIA.toast(r.message);await chargerPromotions();
        <?php if((int)$etab['option_specialite_active']): ?>await chargerOptions();<?php endif; ?>
    }catch(e){STAGIA.toast(e.message,'danger');}
    finally{STAGIA.loading($('promotionSaveBtn'),false);}
};

async function changerStatutPromotion(id,actif){
    if(!STAGIA.confirm(actif?'Désactiver cette promotion ?':'Activer cette promotion ?'))return;
    const d=new FormData();d.append('csrf','<?= $_SESSION['csrf'] ?>');d.append('etablissement_id',EID);d.append('id',id);
    try{
        const r=await STAGIA.post(BASE_URL+'/actions/etablissements/academique/promotion-status.php',d);
        STAGIA.toast(r.message);await chargerPromotions();
    }catch(e){STAGIA.toast(e.message,'danger');}
}
<?php endif; ?>


(async()=>{
    <?php if((int)$etab['unite_academique_active']): ?>await chargerUnites();<?php endif; ?>
    <?php if((int)$etab['departement_active']): ?>await chargerDepartements();<?php endif; ?>
    <?php if((int)$etab['filiere_active']): ?>await chargerFilieres();<?php endif; ?>
    <?php if((int)$etab['option_specialite_active']): ?>await chargerOptions();<?php endif; ?>
    <?php if((int)$etab['promotion_active']): ?>await chargerPromotions();<?php endif; ?>
})();
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

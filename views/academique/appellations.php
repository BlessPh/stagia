<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/academic-structure.php';
require_once __DIR__.'/../../includes/academic-labels.php';

requirePermission($pdo,'academic.manage');
$eid=currentEtablissementId($pdo);
if(!$eid)exit('Aucun établissement associé.');
if(!contextAcademicEnabled())exit("Cet établissement n'a pas de structure académique.");
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));

$cfg=academicSettings($pdo,$eid);
$labels=academicLabels($pdo,$eid);
$defaults=academicDefaultLabels();
$rows=[];

try{
    foreach(academicUnitTypes($pdo,$eid) as $t){
        $code=strtoupper(trim((string)($t['type_unite']??'')));
        if($code==='')continue;
        $defaultSingular=(string)($t['libelle']??$code);
        $rows[$code]=[
            'code'=>$code,'category'=>'Unité académique',
            'default_singular'=>$defaults[$code]['singular']??$defaultSingular,
            'default_plural'=>$defaults[$code]['plural']??($defaultSingular.'s')
        ];
    }
}catch(Throwable $e){}

if(!empty($cfg['departement_active']))$rows['DEPARTMENT']=['code'=>'DEPARTMENT','category'=>'Structure','default_singular'=>'Département','default_plural'=>'Départements'];
if(!empty($cfg['filiere_active']))$rows['PROGRAM']=['code'=>'PROGRAM','category'=>'Structure','default_singular'=>'Filière / Programme','default_plural'=>'Filières / Programmes'];
if(!empty($cfg['option_specialite_active']))$rows['OPTION']=['code'=>'OPTION','category'=>'Structure','default_singular'=>'Option / Spécialité','default_plural'=>'Options / Spécialités'];
if(!empty($cfg['promotion_active']))$rows['PROMOTION']=['code'=>'PROMOTION','category'=>'Structure','default_singular'=>'Promotion','default_plural'=>'Promotions'];

$pageTitle='Configurer ma structure';
$activePage='academic-labels';
require_once __DIR__.'/../../includes/app-header.php';
?>
<style>
.naming-grid{display:grid;gap:14px}.naming-card{background:#fff;border:1px solid #e7ebf0;border-radius:14px;padding:18px;transition:.2s}.naming-card.is-off{opacity:.68;background:#f8fafc}.naming-code{font-size:11px;font-weight:700;letter-spacing:.04em;color:#64748b}.naming-preview{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:10px 12px}.parent-box{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px}.structure-state{min-width:92px;text-align:center}
</style>
<main class="dashboard-content">
<div class="stagia-page-head">
    <div>
        <h1>Configurer ma structure</h1>
        <p>Renommez, retirez les niveaux inutilisés et définissez librement s'ils ont un parent.</p>
    </div>
</div>
<div class="alert alert-info border-0 mb-4">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Important :</strong> « Retirer » masque le niveau de votre organisation et du menu ; aucune donnée existante n'est supprimée.
    Lors de l'ajout d'un élément, l'utilisateur pourra aussi choisir <strong>Avec parent</strong> ou <strong>Sans parent</strong>.
</div>
<div class="naming-grid">
<?php foreach($rows as $r):
    $code=$r['code'];
    $currentSingular=academicLabelFromMap($labels,$code,false,$r['default_singular']);
    $currentPlural=academicLabelFromMap($labels,$code,true,$r['default_plural']);
    $active=academicEntityEnabled($labels,$code,true);
    $parentEnabled=academicParentEnabled($labels,$code,false);
    $parentCode=academicParentEntity($labels,$code)??'';
    $custom=!empty($labels[$code]['custom']);
?>
<div class="naming-card <?= $active?'':'is-off' ?>" data-code="<?= htmlspecialchars($code) ?>" data-active="<?= $active?'1':'0' ?>">
    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
        <div>
            <div class="naming-code"><?= htmlspecialchars($code) ?> · <?= htmlspecialchars($r['category']) ?></div>
            <h5 class="mb-1"><?= htmlspecialchars($currentPlural) ?></h5>
            <small class="text-muted">Base STAGIA : <?= htmlspecialchars($r['default_singular']) ?> / <?= htmlspecialchars($r['default_plural']) ?></small>
        </div>
        <span class="badge structure-state <?= $active?'bg-success-subtle text-success':'bg-secondary-subtle text-secondary' ?>"><?= $active?'Utilisé':'Retiré' ?></span>
    </div>

    <div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label">Nom au singulier *</label><input class="form-control singular" maxlength="100" value="<?= htmlspecialchars($currentSingular) ?>" <?= $active?'':'disabled' ?>></div>
        <div class="col-md-4"><label class="form-label">Nom au pluriel *</label><input class="form-control plural" maxlength="100" value="<?= htmlspecialchars($currentPlural) ?>" <?= $active?'':'disabled' ?>></div>
        <div class="col-md-4"><div class="naming-preview"><small class="text-muted d-block">Aperçu dans le menu</small><strong class="preview"><?= htmlspecialchars($currentPlural) ?></strong></div></div>
    </div>

    <div class="parent-box mt-3 <?= $active?'':'d-none' ?>">
        <div class="form-check form-switch mb-2">
            <input class="form-check-input parent-check" type="checkbox" <?= $parentEnabled?'checked':'' ?>>
            <label class="form-check-label"><strong>Ce niveau utilise normalement un parent</strong></label>
        </div>
        <div class="parent-choice <?= $parentEnabled?'':'d-none' ?>">
            <label class="form-label mb-1">Type de parent conseillé</label>
            <select class="form-select parent-code">
                <option value="">Choisir...</option>
                <?php foreach($rows as $pr): if($pr['code']===$code)continue; ?>
                <option value="<?= htmlspecialchars($pr['code']) ?>" <?= $parentCode===$pr['code']?'selected':'' ?>>
                    <?= htmlspecialchars(academicLabelFromMap($labels,$pr['code'],false,$pr['default_singular'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted">C'est un réglage par défaut. Au moment d'ajouter l'élément, l'utilisateur pourra toujours cocher ou décocher « A un parent ».</small>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button type="button" class="btn btn-primary-stagia save-label <?= $active?'':'d-none' ?>"><i class="bi bi-check-lg me-1"></i> Enregistrer</button>
        <button type="button" class="btn <?= $active?'btn-outline-danger':'btn-outline-success' ?> toggle-level">
            <i class="bi <?= $active?'bi-dash-circle':'bi-plus-circle' ?> me-1"></i><?= $active?'Retirer':'Réactiver' ?>
        </button>
        <button type="button" class="btn btn-light border reset-label"><i class="bi bi-arrow-counterclockwise me-1"></i> Restaurer STAGIA</button>
    </div>
</div>
<?php endforeach; ?>
</div>
</main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
const BASE_URL='<?= BASE_URL ?>',csrf='<?= htmlspecialchars($_SESSION['csrf']) ?>';

document.querySelectorAll('.naming-card[data-code]').forEach(card=>{
    const singular=card.querySelector('.singular'),plural=card.querySelector('.plural'),preview=card.querySelector('.preview'),save=card.querySelector('.save-label'),reset=card.querySelector('.reset-label'),toggle=card.querySelector('.toggle-level'),parentCheck=card.querySelector('.parent-check'),parentChoice=card.querySelector('.parent-choice'),parentCode=card.querySelector('.parent-code'),code=card.dataset.code;

    plural?.addEventListener('input',()=>preview.textContent=plural.value||'—');
    parentCheck?.addEventListener('change',()=>parentChoice.classList.toggle('d-none',!parentCheck.checked));

    save?.addEventListener('click',async()=>{
        if(parentCheck.checked&&!parentCode.value){STAGIA.toast('Choisissez le type de parent.','warning');return;}
        const fd=new FormData();
        fd.append('csrf',csrf);fd.append('entity_code',code);
        fd.append('label_singular',singular.value.trim());fd.append('label_plural',plural.value.trim());
        fd.append('is_active','1');fd.append('parent_enabled',parentCheck.checked?'1':'0');fd.append('parent_entity_code',parentCheck.checked?parentCode.value:'');
        STAGIA.loading(save,true);
        try{const r=await STAGIA.post(BASE_URL+'/actions/academique/appellations-save.php',fd);STAGIA.toast(r.message);setTimeout(()=>location.reload(),300);}catch(e){STAGIA.toast(e.message,'danger');}finally{STAGIA.loading(save,false);}
    });

    toggle.addEventListener('click',async()=>{
        const active=card.dataset.active==='1',message=active
            ?'Retirer ce niveau de la structure de votre établissement ? Les données existantes seront conservées.'
            :'Réactiver ce niveau dans votre structure ?';
        if(!STAGIA.confirm(message))return;
        const fd=new FormData();fd.append('csrf',csrf);fd.append('entity_code',code);fd.append('toggle_active','1');fd.append('is_active',active?'0':'1');
        try{const r=await STAGIA.post(BASE_URL+'/actions/academique/appellations-save.php',fd);STAGIA.toast(r.message);setTimeout(()=>location.reload(),300);}catch(e){STAGIA.toast(e.message,'danger');}
    });

    reset.addEventListener('click',async()=>{
        if(!STAGIA.confirm('Restaurer le nom et la configuration STAGIA de ce niveau ?'))return;
        const fd=new FormData();fd.append('csrf',csrf);fd.append('entity_code',code);fd.append('reset','1');
        try{const r=await STAGIA.post(BASE_URL+'/actions/academique/appellations-save.php',fd);STAGIA.toast(r.message);setTimeout(()=>location.reload(),300);}catch(e){STAGIA.toast(e.message,'danger');}
    });
});
});
</script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

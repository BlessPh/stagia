<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../config/reference.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['SUPER_ADMIN']);
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

$errors=$_SESSION['form_errors']??[];
$old=$_SESSION['old']??[];
unset($_SESSION['form_errors'],$_SESSION['old']);

$stmt=$pdo->query("
    SELECT t.code,t.libelle,t.categorie,t.academic_enabled,t.host_enabled,
           (SELECT COUNT(*) FROM academic_structure_templates m
            WHERE m.type_etablissement=t.code AND m.actif=1) modeles_count,
           (SELECT m.nom FROM academic_structure_templates m
            WHERE m.type_etablissement=t.code AND m.is_default=1 AND m.actif=1
            ORDER BY m.version_no DESC,m.id DESC LIMIT 1) modele_defaut
    FROM establishment_types t
    WHERE t.actif=1
    ORDER BY t.ordre,t.libelle
");
$types=$stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt=$pdo->query("
    SELECT id,type_etablissement,nom,description,version_no,is_default
    FROM academic_structure_templates
    WHERE actif=1
    ORDER BY type_etablissement,is_default DESC,version_no DESC,id DESC
");
$modeles=$stmt->fetchAll(PDO::FETCH_ASSOC);

function old(string $key):string{
    global $old;
    return htmlspecialchars((string)($old[$key]??''),ENT_QUOTES,'UTF-8');
}
function selected(string $key,string $value):string{
    global $old;
    return (($old[$key]??'')===$value)?'selected':'';
}

$pageTitle='Nouvel établissement';
$activePage='etablissements';
require_once __DIR__.'/../../includes/app-header.php';
?>
<main class="dashboard-content">

<div class="stagia-page-head">
    <div>
        <a href="<?= BASE_URL ?>/views/etablissements/index.php" class="detail-back">
            <i class="bi bi-arrow-left"></i> Établissements
        </a>
        <h1>Nouvel établissement</h1>
        <p>Créez directement l’établissement, son administrateur principal et sa configuration STAGIA.</p>
    </div>
</div>

<?php if($errors): ?>
<div class="alert alert-danger">
    <strong>Veuillez corriger les informations suivantes :</strong>
    <ul class="mb-0 mt-2">
        <?php foreach($errors as $error): ?>
            <li><?= htmlspecialchars($error,ENT_QUOTES,'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if(!$types): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Aucun type d’établissement actif n’est configuré.
</div>
<?php endif; ?>

<form action="<?= BASE_URL ?>/actions/etablissements/store.php" method="POST" enctype="multipart/form-data" id="etablissementForm">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>">

<div class="row g-3">

<div class="col-xl-8">

<div class="stagia-list-card mb-3">
    <div class="p-4 border-bottom">
        <div class="d-flex gap-3 align-items-start">
            <div class="stagia-kpi-icon kpi-blue"><i class="bi bi-building"></i></div>
            <div><h5 class="mb-1">Établissement</h5><p class="text-muted mb-0">Informations officielles de la structure.</p></div>
        </div>
    </div>

    <div class="p-4">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Nom de l’établissement *</label>
                <input name="nom_etablissement" class="form-control" value="<?= old('nom_etablissement') ?>" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Type *</label>
                <select name="type_etablissement" id="typeEtablissement" class="form-select" required <?= !$types?'disabled':'' ?>>
                    <option value="">Sélectionner...</option>
                    <?php foreach($types as $type):
                        $missing=(int)$type['academic_enabled']===1 && (int)$type['modeles_count']===0; ?>
                        <option
                            value="<?= htmlspecialchars($type['code'],ENT_QUOTES,'UTF-8') ?>"
                            data-academic="<?= (int)$type['academic_enabled'] ?>"
                            data-host="<?= (int)$type['host_enabled'] ?>"
                            data-model-count="<?= (int)$type['modeles_count'] ?>"
                            <?= selected('type_etablissement',$type['code']) ?>
                            <?= $missing?'disabled':'' ?>
                        >
                            <?= htmlspecialchars($type['libelle'],ENT_QUOTES,'UTF-8') ?>
                            <?= $missing?' — aucun modèle académique actif':'' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <div id="typeInfo" class="alert alert-light border py-2 px-3 mb-0 d-none"></div>
            </div>

            <div class="col-12 d-none" id="academicModelWrap">
                <label class="form-label">Modèle académique STAGIA *</label>
                <select name="academic_template_id" id="academicTemplateId" class="form-select">
                    <option value="">Sélectionner le modèle applicable...</option>
                </select>
                <div id="academicModelInfo" class="form-text">
                    Le Super Admin choisit la structure officielle de cet établissement.
                </div>
            </div>

            <div class="col-md-6">
                <label class="form-label">N° d’agrément</label>
                <input name="numero_agrement" class="form-control" value="<?= old('numero_agrement') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">E-mail institutionnel</label>
                <input type="email" name="email_etablissement" class="form-control" value="<?= old('email_etablissement') ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">Téléphone</label>
                <input name="telephone_etablissement" class="form-control" value="<?= old('telephone_etablissement') ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">Province</label>
                <select name="province" class="form-select">
                    <option value="">Sélectionner...</option>
                    <?php foreach($PROVINCES_RDC as $province): ?>
                        <option value="<?= htmlspecialchars($province,ENT_QUOTES,'UTF-8') ?>" <?= selected('province',$province) ?>>
                            <?= htmlspecialchars($province,ENT_QUOTES,'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Ville / Territoire</label>
                <input name="ville" class="form-control" value="<?= old('ville') ?>">
            </div>

            <div class="col-12">
                <label class="form-label">Adresse</label>
                <textarea name="adresse" class="form-control" rows="2"><?= old('adresse') ?></textarea>
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Logo de l’établissement</label>
                <div class="border rounded-3 p-3 bg-light">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div id="logoPreviewWrap" class="d-none">
                            <img id="logoPreview" alt="Aperçu du logo" style="width:84px;height:84px;object-fit:contain;border:1px solid #dee2e6;border-radius:12px;background:#fff;padding:6px">
                        </div>
                        <div class="flex-grow-1">
                            <input type="file" name="logo" id="logo" class="form-control" accept=".png,.jpg,.jpeg,.webp">
                            <small class="text-muted d-block mt-2">PNG, JPG/JPEG ou WEBP — 2 Mo maximum. Facultatif.</small>
                            <div id="logoInfo" class="small text-success mt-2 d-none"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Pièce justificative *</label>
                <div class="border rounded-3 p-3 bg-light">
                    <input type="file" name="piece_justificative" id="pieceJustificative" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                    <small class="text-muted d-block mt-2">Agrément, RCCM, arrêté, autorisation de fonctionnement ou document équivalent — PDF/JPG/PNG, 5 Mo maximum.</small>
                    <div id="pieceInfo" class="small text-success mt-2 d-none"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="stagia-list-card">
    <div class="p-4 border-bottom">
        <div class="d-flex gap-3 align-items-start">
            <div class="stagia-kpi-icon kpi-purple"><i class="bi bi-person-vcard"></i></div>
            <div><h5 class="mb-1">Administrateur principal</h5><p class="text-muted mb-0">Le compte sera créé puis activé par invitation e-mail.</p></div>
        </div>
    </div>

    <div class="p-4">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Nom *</label>
                <input name="responsable_nom" class="form-control" value="<?= old('responsable_nom') ?>" required>
            </div>

            <div class="col-md-4">
                <label class="form-label">Post-nom</label>
                <input name="responsable_postnom" class="form-control" value="<?= old('responsable_postnom') ?>">
            </div>

            <div class="col-md-4">
                <label class="form-label">Prénom</label>
                <input name="responsable_prenom" class="form-control" value="<?= old('responsable_prenom') ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">Fonction</label>
                <input name="responsable_fonction" class="form-control" value="<?= old('responsable_fonction') ?>" placeholder="Ex. Secrétaire général académique">
            </div>

            <div class="col-md-6">
                <label class="form-label">Téléphone *</label>
                <input name="responsable_telephone" class="form-control" value="<?= old('responsable_telephone') ?>" required>
            </div>

            <div class="col-12">
                <label class="form-label">Adresse e-mail *</label>
                <input type="email" name="responsable_email" class="form-control" value="<?= old('responsable_email') ?>" required>
            </div>
        </div>
    </div>
</div>

</div>

<div class="col-xl-4">
<div class="stagia-list-card position-sticky" style="top:90px">
    <div class="p-4">
        <h5><i class="bi bi-lightning-charge text-primary me-2"></i>Création directe</h5>
        <p class="text-muted">Contrairement à une demande d’adhésion, cette opération est effectuée directement par le Super Admin.</p>
        <hr>
        <div class="d-flex gap-2 mb-3"><i class="bi bi-check-circle text-success"></i><small>L’établissement est créé immédiatement avec le statut validé.</small></div>
        <div class="d-flex gap-2 mb-3"><i class="bi bi-person-check text-success"></i><small>Un administrateur principal est créé avec un compte à activer.</small></div>
        <div class="d-flex gap-2 mb-3"><i class="bi bi-diagram-3 text-success"></i><small>Si le type est académique, le Super Admin choisit le modèle STAGIA correspondant à la structure officielle de cet établissement.</small></div>
        <div class="d-flex gap-2 mb-3"><i class="bi bi-envelope text-success"></i><small>L’invitation d’activation est envoyée après validation de la transaction.</small></div>

        <button type="submit" class="btn btn-primary-stagia w-100 mt-2" id="submitBtn" <?= !$types?'disabled':'' ?>>
            <i class="bi bi-check-lg me-1"></i> Créer l’établissement
        </button>
        <a href="<?= BASE_URL ?>/views/etablissements/index.php" class="btn btn-light border w-100 mt-2">Annuler</a>
    </div>
</div>
</div>

</div>
</form>
</main>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    const type=document.getElementById('typeEtablissement'),
          info=document.getElementById('typeInfo'),
          modelWrap=document.getElementById('academicModelWrap'),
          modelSelect=document.getElementById('academicTemplateId'),
          modelInfo=document.getElementById('academicModelInfo'),
          piece=document.getElementById('pieceJustificative'),
          fileInfo=document.getElementById('pieceInfo'),
          logo=document.getElementById('logo'),
          logoInfo=document.getElementById('logoInfo'),
          logoPreview=document.getElementById('logoPreview'),
          logoPreviewWrap=document.getElementById('logoPreviewWrap'),
          form=document.getElementById('etablissementForm');

    const MODELS=<?= json_encode($modeles,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
          OLD_MODEL=<?= json_encode((string)($old['academic_template_id']??''),JSON_UNESCAPED_UNICODE) ?>;

    function modelesPour(typeCode){
        return MODELS.filter(m=>String(m.type_etablissement)===String(typeCode));
    }

    function renderModels(typeCode){
        const models=modelesPour(typeCode);
        modelSelect.innerHTML='<option value="">Sélectionner le modèle applicable...</option>'+
            models.map(m=>`<option value="${m.id}" ${String(m.id)===String(OLD_MODEL)?'selected':''}>
                ${STAGIA.escape(m.nom)} · v${Number(m.version_no||1)}${Number(m.is_default)===1?' · Par défaut':''}
            </option>`).join('');

        if(models.length===1 && !OLD_MODEL) modelSelect.value=String(models[0].id);

        modelSelect.required=models.length>0;
        updateModelInfo();
    }

    function updateModelInfo(){
        const m=MODELS.find(x=>String(x.id)===String(modelSelect.value));
        if(!m){
            modelInfo.innerHTML='<i class="bi bi-info-circle me-1"></i>Choisissez le modèle correspondant à la structure officielle de cet établissement.';
            return;
        }

        const desc=String(m.description||'').trim();
        modelInfo.innerHTML=`<i class="bi bi-shield-check text-success me-1"></i>
            <strong>${STAGIA.escape(m.nom)}</strong> · version ${Number(m.version_no||1)}
            ${Number(m.is_default)===1?' · modèle par défaut du type':''}
            ${desc?`<br>${STAGIA.escape(desc)}`:''}`;
    }

    function showType(){
        const o=type.options[type.selectedIndex];

        if(!o||!o.value){
            info.classList.add('d-none');
            modelWrap.classList.add('d-none');
            modelSelect.required=false;
            modelSelect.innerHTML='<option value="">Sélectionner le modèle applicable...</option>';
            return;
        }

        const academic=o.dataset.academic==='1',
              host=o.dataset.host==='1',
              count=Number(o.dataset.modelCount||0);

        if(academic){
            info.innerHTML=`<i class="bi bi-mortarboard me-1"></i>
                Type académique — <strong>${count}</strong> modèle(s) STAGIA actif(s) disponible(s).
                ${host?'<span class="ms-2 badge bg-light text-dark border">Structure d’accueil</span>':''}`;
            modelWrap.classList.remove('d-none');
            renderModels(o.value);
        }else{
            info.innerHTML=`<i class="bi bi-briefcase me-1"></i>
                Type non académique — aucune structure académique ne sera créée.
                ${host?'<span class="ms-2 badge bg-light text-dark border">Structure d’accueil</span>':''}`;
            modelWrap.classList.add('d-none');
            modelSelect.required=false;
            modelSelect.value='';
        }

        info.classList.remove('d-none');
    }

    type?.addEventListener('change',()=>{
        /* Lors d'un nouveau choix de type, ne pas conserver l'ancien modèle. */
        modelSelect.dataset.userChanged='1';
        showType();
        if(type.value && modelesPour(type.value).length>1) modelSelect.value='';
        updateModelInfo();
    });

    modelSelect?.addEventListener('change',updateModelInfo);

    form?.addEventListener('submit',e=>{
        const o=type.options[type.selectedIndex];
        if(o?.dataset.academic==='1' && !modelSelect.value){
            e.preventDefault();
            modelWrap.classList.remove('d-none');
            modelSelect.focus();
            if(window.STAGIA?.toast)STAGIA.toast('Choisissez le modèle académique applicable à cet établissement.','warning');
            else alert('Choisissez le modèle académique applicable à cet établissement.');
        }
    });

    /* Au premier affichage, restituer les anciennes valeurs après erreur serveur. */
    showType();

    logo?.addEventListener('change',function(){
        const f=this.files[0];

        if(!f){
            logoInfo.classList.add('d-none');
            logoPreviewWrap.classList.add('d-none');
            logoPreview.removeAttribute('src');
            return;
        }

        const ext=f.name.split('.').pop().toLowerCase();

        if(!['png','jpg','jpeg','webp'].includes(ext)){
            alert('Format du logo non autorisé. Utilisez PNG, JPG, JPEG ou WEBP.');
            this.value='';
            logoInfo.classList.add('d-none');
            logoPreviewWrap.classList.add('d-none');
            return;
        }

        if(f.size>2*1024*1024){
            alert('Le logo ne doit pas dépasser 2 Mo.');
            this.value='';
            logoInfo.classList.add('d-none');
            logoPreviewWrap.classList.add('d-none');
            return;
        }

        const url=URL.createObjectURL(f);
        logoPreview.src=url;
        logoPreview.onload=()=>URL.revokeObjectURL(url);
        logoPreviewWrap.classList.remove('d-none');
        logoInfo.innerHTML='<i class="bi bi-check-circle-fill me-1"></i>'+
            STAGIA.escape(f.name)+' — '+(f.size/1024/1024).toFixed(2)+' Mo';
        logoInfo.classList.remove('d-none');
    });

    piece?.addEventListener('change',function(){
        const f=this.files[0];

        if(!f){
            fileInfo.classList.add('d-none');
            return;
        }

        const ext=f.name.split('.').pop().toLowerCase();

        if(!['pdf','jpg','jpeg','png'].includes(ext)){
            alert('Format non autorisé. Utilisez PDF, JPG, JPEG ou PNG.');
            this.value='';
            fileInfo.classList.add('d-none');
            return;
        }

        if(f.size>5*1024*1024){
            alert('La pièce justificative ne doit pas dépasser 5 Mo.');
            this.value='';
            fileInfo.classList.add('d-none');
            return;
        }

        fileInfo.innerHTML='<i class="bi bi-check-circle-fill me-1"></i>'+
            STAGIA.escape(f.name)+' — '+(f.size/1024/1024).toFixed(2)+' Mo';

        fileInfo.classList.remove('d-none');
    });
});
</script>

<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

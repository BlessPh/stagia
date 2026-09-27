<?php
/**
 * Formulaire public de demande d'adhésion d'un établissement à STAGIA-RDC.
 *
 * Cette page prépare le formulaire et ses valeurs de retour. La création
 * effective de la demande est traitée par actions/adhesion/store.php.
 */

/* La session conserve le jeton CSRF et les éventuelles erreurs après redirection. */
if(session_status()!==PHP_SESSION_ACTIVE) session_start();

/* Configuration, connexion PDO et liste de référence des provinces. */
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/config/reference.php';

/* Jeton anti-CSRF envoyé avec le formulaire puis vérifié par l'action de stockage. */
if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));

/* Récupération temporaire des erreurs et valeurs saisies lors de la requête précédente. */
$errors=$_SESSION['adhesion_errors']??[];
$old=$_SESSION['adhesion_old']??[];
unset($_SESSION['adhesion_errors'],$_SESSION['adhesion_old']);

/* Types disponibles pour l'adhésion */
$stmt=$pdo->query("
    SELECT code,libelle,categorie
    FROM establishment_types
    WHERE actif=1 AND adhesion_enabled=1
    ORDER BY ordre,libelle
");
$types=$stmt->fetchAll(PDO::FETCH_ASSOC);

/** Réaffiche une ancienne valeur de formulaire en l'échappant pour le HTML. */
function old(string $key):string{
    global $old;
    return htmlspecialchars((string)($old[$key]??''),ENT_QUOTES,'UTF-8');
}
/** Réactive l'option précédemment sélectionnée dans une liste déroulante. */
function selected(string $key,string $value):string{
    global $old;
    return (($old[$key]??'')===$value)?'selected':'';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<!-- Dépendances visuelles ; filemtime renouvelle le cache du CSS après une modification. -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Demande d'adhésion | STAGIA-RDC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__.'/assets/css/style.css') ?>">
</head>
<body class="login-page">

<!-- En-tête public et retour vers le portail. -->
<header class="login-header adhesion-header">
<div class="container d-flex justify-content-between align-items-center">
    <a href="<?= BASE_URL ?>/index.php" class="login-brand adhesion-brand">
        <img src="<?= BASE_URL ?>/assets/img/logo.png" class="adhesion-logo" alt="Logo STAGIA-RDC">
    </a>
    <a href="<?= BASE_URL ?>/index.php" class="btn-back"><i class="bi bi-arrow-left me-1"></i>Retour au portail</a>
</div>
</header>

<!-- Contenu principal de la demande d'adhésion. -->
<main class="public-form-page">
<div class="container">

<div class="public-form-heading">
    <span><i class="bi bi-building-add"></i> ADHÉSION À STAGIA-RDC</span>
    <h1>Demander l'inscription de votre établissement</h1>
    <p>Soumettez les informations et les pièces justificatives de votre structure. Après vérification, l'administration STAGIA pourra activer votre espace.</p>
</div>

<?php if($errors): ?>
<!-- Les erreurs viennent de l'action de stockage ; elles sont échappées avant affichage. -->
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
<!-- Sans type disponible, la demande est volontairement bloquée côté interface. -->
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Aucun type d'établissement n'est actuellement disponible pour une demande d'adhésion.
</div>
<?php endif; ?>

<!-- POST multipart : transmet les champs texte, le logo optionnel et le justificatif obligatoire. -->
<form action="<?= BASE_URL ?>/actions/adhesion/store.php" method="POST" enctype="multipart/form-data">
<!-- Jeton associé à la session afin de distinguer cette soumission d'une requête externe. -->
<input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8') ?>">

<div class="row g-3">

<!-- Colonne principale : identité de l'établissement et de son responsable. -->
<div class="col-lg-8">

<div class="public-card">
<div class="form-section-title">
    <i class="bi bi-building"></i>
    <div><h5>Établissement</h5><p>Informations officielles de votre structure.</p></div>
</div>

<div class="row g-3 p-3">

<div class="col-md-8">
    <label class="form-label">Nom de l'établissement *</label>
    <input name="nom_etablissement" class="form-control" value="<?= old('nom_etablissement') ?>" required>
</div>

<div class="col-md-4">
    <label class="form-label">Type *</label>
    <select name="type_etablissement" class="form-select" required <?= !$types?'disabled':'' ?>>
        <option value="">Sélectionner...</option>
        <?php foreach($types as $type): ?>
            <option value="<?= htmlspecialchars($type['code'],ENT_QUOTES,'UTF-8') ?>" <?= selected('type_etablissement',$type['code']) ?>>
                <?= htmlspecialchars($type['libelle'],ENT_QUOTES,'UTF-8') ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="col-md-6">
    <label class="form-label">N° d'agrément</label>
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
    <label class="form-label fw-semibold">Logo de l'établissement</label>
    <div class="border rounded-3 p-3 bg-light">
        <div class="d-flex gap-3 align-items-start flex-wrap">
            <div id="logoPreviewWrap" class="d-none">
                <img id="logoPreview" alt="Aperçu du logo"
                     style="width:84px;height:84px;object-fit:contain;border:1px solid #dee2e6;border-radius:12px;background:#fff;padding:6px">
            </div>
            <div class="flex-grow-1">
                <!-- Logo facultatif : l'aperçu est construit côté navigateur avant l'envoi. -->
                <input type="file" name="logo" id="logoEtablissement"
                       class="form-control" accept=".png,.jpg,.jpeg,.webp">
                <small class="text-muted d-block mt-2">
                    Facultatif — PNG, JPG/JPEG ou WEBP — 2 Mo maximum.
                </small>
                <div id="logoInfo" class="small text-success mt-2 d-none"></div>
            </div>
        </div>
    </div>
</div>

<div class="col-12">
    <label class="form-label fw-semibold">Pièce justificative de l'établissement *</label>
    <div class="border rounded-3 p-3 bg-light">
        <div class="d-flex gap-3 align-items-start">
            <div class="fs-3"><i class="bi bi-file-earmark-arrow-up"></i></div>
            <div class="flex-grow-1">
                <!-- Justificatif obligatoire : il permet à l'administration de contrôler la demande. -->
                <input type="file" name="piece_justificative" id="pieceJustificative" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                <small class="text-muted d-block mt-2">Joignez un document officiel permettant de vérifier l'existence ou l'autorisation de fonctionnement de votre établissement.</small>
                <small class="text-muted d-block mt-1">Exemples : agrément, RCCM, arrêté ministériel, autorisation de fonctionnement ou document équivalent.</small>
                <small class="text-muted d-block mt-1">Formats : PDF, JPG, JPEG, PNG — 5 Mo maximum.</small>
                <div id="pieceInfo" class="small text-success mt-2 d-none"></div>
            </div>
        </div>
    </div>
</div>

</div>
</div>

<div class="public-card">
<div class="form-section-title">
    <!-- Informations de la personne qui recevra l'accès administrateur après validation. -->
    <i class="bi bi-person-vcard"></i>
    <div><h5>Responsable de l'établissement</h5><p>Cette personne deviendra administrateur après validation.</p></div>
</div>

<div class="row g-3 p-3">

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

<!-- Colonne d'aide : elle explique le processus sans soumettre de données supplémentaires. -->
<div class="col-lg-4">

<div class="public-card">
<div class="p-3">

<div class="registration-info">
    <i class="bi bi-shield-check"></i>
    <div>
        <strong>Validation obligatoire</strong>
        <p>La demande ne crée pas immédiatement un espace. L'administration nationale doit vérifier les informations et la pièce justificative avant validation.</p>
    </div>
</div>

<hr>

<div class="adhesion-step"><span>1</span><div><strong>Soumission</strong><small>Vous envoyez votre demande et vos documents.</small></div></div>
<div class="adhesion-step"><span>2</span><div><strong>Vérification</strong><small>STAGIA examine les informations et justificatifs.</small></div></div>
<div class="adhesion-step"><span>3</span><div><strong>Validation</strong><small>Votre établissement est validé.</small></div></div>
<div class="adhesion-step"><span>4</span><div><strong>Activation</strong><small>Le responsable reçoit son invitation par e-mail.</small></div></div>
<div class="adhesion-step"><span>5</span><div><strong>Accès</strong><small>Vous gérez votre espace STAGIA-RDC.</small></div></div>

<!-- Le bouton est désactivé si aucun type d'établissement n'est disponible. -->
<button class="btn btn-primary-stagia w-100 mt-3" type="submit" id="submitAdhesion" <?= !$types?'disabled':'' ?>>
    <i class="bi bi-send me-1"></i>Soumettre la demande
</button>

</div>
</div>

<div class="text-center mt-3">
    <small class="text-muted">Votre établissement possède déjà un espace ?</small><br>
    <a href="<?= BASE_URL ?>/login.php" class="small">Se connecter</a>
</div>

</div>
</div>
</form>

</div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Références aux champs fichier et aux zones d'information mises à jour par le navigateur.
const piece=document.getElementById('pieceJustificative'),
      info=document.getElementById('pieceInfo'),
      logo=document.getElementById('logoEtablissement'),
      logoInfo=document.getElementById('logoInfo'),
      logoPreview=document.getElementById('logoPreview'),
      logoPreviewWrap=document.getElementById('logoPreviewWrap');

// Vérification ergonomique du logo : le serveur doit refaire ces contrôles à la réception.
logo?.addEventListener('change',function(){
    const file=this.files[0];

    // Réinitialisation complète de l'aperçu lorsque l'utilisateur retire son fichier.
    if(!file){
        logoInfo.classList.add('d-none');
        logoPreviewWrap.classList.add('d-none');
        logoPreview.removeAttribute('src');
        return;
    }

    // L'extension est contrôlée avant de créer l'aperçu local.
    const ext=file.name.split('.').pop().toLowerCase();

    if(!['png','jpg','jpeg','webp'].includes(ext)){
        alert('Format du logo non autorisé. Utilisez PNG, JPG, JPEG ou WEBP.');
        this.value='';
        logoInfo.classList.add('d-none');
        logoPreviewWrap.classList.add('d-none');
        return;
    }

    // Limite annoncée dans le formulaire : 2 Mo pour le logo.
    if(file.size>2*1024*1024){
        alert('Le logo ne doit pas dépasser 2 Mo.');
        this.value='';
        logoInfo.classList.add('d-none');
        logoPreviewWrap.classList.add('d-none');
        return;
    }

    // URL temporaire locale : elle affiche le fichier sans l'envoyer ; elle est libérée après chargement.
    const url=URL.createObjectURL(file);
    logoPreview.src=url;
    logoPreview.onload=()=>URL.revokeObjectURL(url);
    logoPreviewWrap.classList.remove('d-none');

    logoInfo.innerHTML='<i class="bi bi-check-circle-fill me-1"></i>'+
        file.name+' — '+(file.size/1024/1024).toFixed(2)+' Mo';
    logoInfo.classList.remove('d-none');
});

// Vérification ergonomique du justificatif obligatoire avant la soumission multipart.
piece?.addEventListener('change',function(){
    const file=this.files[0];

    // Aucun fichier : l'information de confirmation est simplement masquée.
    if(!file){
        info.classList.add('d-none');
        return;
    }

    // Seuls les formats documentaires annoncés sont acceptés dans l'interface.
    const ext=file.name.split('.').pop().toLowerCase();

    if(!['pdf','jpg','jpeg','png'].includes(ext)){
        alert('Format non autorisé. Utilisez PDF, JPG, JPEG ou PNG.');
        this.value='';
        info.classList.add('d-none');
        return;
    }

    // Limite annoncée dans le formulaire : 5 Mo pour le justificatif.
    if(file.size>5*1024*1024){
        alert('La pièce justificative ne doit pas dépasser 5 Mo.');
        this.value='';
        info.classList.add('d-none');
        return;
    }

    info.innerHTML='<i class="bi bi-check-circle-fill me-1"></i>'+
        file.name+' — '+(file.size/1024/1024).toFixed(2)+' Mo';
    info.classList.remove('d-none');
});
</script>

</body>
</html>

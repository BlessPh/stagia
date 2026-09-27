<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/admin-scope.php';
require_once __DIR__.'/../../includes/geographie.php';
geoVerifierGestion();
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle='Référentiel géographique';$activePage='geographie';
require_once __DIR__.'/../../includes/app-header.php';
?>
<link rel="stylesheet" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8')?>/assets/css/geographie.css?v=1.0">
<main class="dashboard-content" id="geo-gestion" data-url="<?=htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8')?>/actions/geographie/gestion.php" data-csrf="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES,'UTF-8')?>">
 <header class="geo-hero"><span>ADMINISTRATION STAGIA</span><h1>Référentiel géographique</h1><p>Gérez les provinces et leurs subdivisions proposées aux établissements lors de l’adhésion.</p></header>
 <p>Les provinces sont préchargées. Ajoutez les villes, territoires, communes, secteurs et chefferies après vérification de leur source. Une désactivation retire la localité des nouvelles demandes et conserve son historique.</p>
 <p id="geo-status" role="status" aria-live="polite"></p>
 <button type="button" id="geo-refresh">Actualiser</button>
 <nav id="geo-chemin" aria-label="Parcours géographique"></nav>
 <div class="geo-toolbar"><label for="geo-recherche">Rechercher dans ce niveau<input id="geo-recherche" type="search" placeholder="Nom d’une localité…"></label><button id="geo-ajouter" class="geo-primary" type="button" disabled>Ajouter une localité</button></div>
 <div class="geo-table"><table><thead><tr><th scope="col">Localité</th><th scope="col">Type</th><th scope="col">État</th><th scope="col">Actions</th></tr></thead><tbody id="geo-liste"></tbody></table></div>
 <p id="geo-total"></p><p id="geo-incomplets"></p>
 <details><summary>Dernières modifications</summary><ul id="geo-journal"></ul></details>
 <dialog id="geo-dialog" aria-labelledby="geo-titre"><form id="geo-form">
  <h2 id="geo-titre">Localité</h2><p id="geo-parent-info"></p>
  <input type="hidden" name="id"><input type="hidden" name="parent_id"><input type="hidden" name="version">
  <label for="geo-type">Type<select name="type" id="geo-type" required></select></label>
  <label for="geo-nom">Nom<input name="nom" id="geo-nom" required maxlength="100" autocomplete="off"></label>
  <label for="geo-source">Source vérifiée<textarea name="source" id="geo-source" required maxlength="500" rows="3" placeholder="Référence du texte officiel ou URL, date de vérification"></textarea></label>
  <label for="geo-actif">Disponibilité<select name="actif" id="geo-actif"><option value="1">Active</option><option value="0">Désactivée</option></select></label>
  <p class="geo-note">Le parent et le type restent fixes après création afin de préserver les dossiers déjà rattachés.</p>
  <p id="geo-form-status" role="alert"></p><div class="geo-toolbar"><button type="button" id="geo-annuler">Annuler</button><button class="geo-primary" type="submit">Enregistrer</button></div>
 </form></dialog>
</main>
<script src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES,'UTF-8')?>/assets/js/geographie-gestion.js?v=1.0" defer></script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
$pageTitle='Bibliothèque numérique';$activePage='bibliotheque';
require_once __DIR__.'/../../includes/app-header.php';
?>
<link rel="stylesheet" href="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/css/bibliotheque.css?v=1.2">
<main class="dashboard-content" id="bibliotheque"
 data-base="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>" data-csrf="<?=htmlspecialchars($_SESSION['csrf'],ENT_QUOTES)?>">
 <header class="bib-hero"><div><span>RESSOURCES & CONNAISSANCES</span><h1>Bibliothèque numérique</h1>
 <p>Explorez les ressources partagées par la communauté STAGIA.</p></div>
 <button type="button" class="bib-primary" id="bib-deposer">+ Proposer un document</button></header>
 <nav class="bib-tabs" aria-label="Vues de la bibliothèque">
 <button data-tab="catalogue" aria-pressed="true">Catalogue partagé</button>
 <button type="button" id="bib-alertes">Notifications</button>
 <button data-tab="favoris" aria-pressed="false">Mes favoris</button>
 <button data-tab="depots" aria-pressed="false">Mes dépôts</button>
 <button data-tab="validation" aria-pressed="false" id="bib-validation" hidden>Espace de validation</button>
 <button data-tab="gestion" aria-pressed="false" id="bib-gestion" hidden>Gestion des publications</button>
 <button data-tab="archives" aria-pressed="false">Archives</button>
 <button data-tab="corbeille" aria-pressed="false">Corbeille</button></nav>
 <section id="bib-validation-info" class="bib-validation-info" hidden>
 <h2>Validation et diffusion</h2>
 <p>Ouvrez la fiche pour lire le document, puis validez sa publication ou demandez une correction avec un motif.
 Une publication interne rejoint le catalogue de son établissement ; une publication globale rejoint le catalogue STAGIA.</p>
 <div id="bib-compteurs" class="bib-compteurs"></div>
 </section>
 <form id="bib-filtres" class="bib-filtres">
 <label>Rechercher<input type="search" name="q" placeholder="Titre, auteur, mots-clés…" maxlength="200"></label>
 <label>Catégorie<select name="categorie" id="bib-categories"><option value="">Toutes les catégories</option></select></label>
 <label>Diffusion<select name="diffusion"><option value="">Toutes les diffusions</option><option value="globale">Tout STAGIA</option><option value="etablissement">Établissement</option></select></label>
 <label id="bib-etat-label" hidden>État<select name="etat" disabled><option value="">Tous les états</option><option value="soumis">À valider</option><option value="publie">Publié</option><option value="rejete">À corriger</option><option value="brouillon">Brouillon</option><option value="archive">Archivé</option><option value="corbeille">Corbeille</option></select></label>
 <label>Trier<select name="tri"><option value="recent">Les plus récents</option><option value="titre">Titre A–Z</option></select></label>
 <button class="bib-primary">Rechercher</button><button type="button" id="bib-reset">Effacer les filtres</button></form>
 <p id="bib-status" role="status" aria-live="polite"></p>
 <section id="bib-resultats" class="bib-grid" aria-label="Documents" aria-busy="true"></section>
 <footer class="bib-pagination"><button id="bib-prev">Précédent</button><span id="bib-page"></span><button id="bib-next">Suivant</button></footer>
 <dialog id="bib-dialog" aria-labelledby="bib-dialog-title">
 <header class="bib-dialog-header"><h2 id="bib-dialog-title"></h2><button type="button" id="bib-fermer" aria-label="Fermer">✕</button></header>
 <div id="bib-dialog-body"></div></dialog>
</main>
<script src="<?=htmlspecialchars(BASE_URL,ENT_QUOTES)?>/assets/js/bibliotheque.js?v=1.2" defer></script>
<?php require_once __DIR__.'/../../includes/app-footer.php'; ?>

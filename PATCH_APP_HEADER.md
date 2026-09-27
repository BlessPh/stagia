# STAGIA-RDC — Sidebar ENCADREUR

## Pourquoi ce correctif n'écrase pas `includes/app-header.php`

Le projet actuel a déjà beaucoup évolué depuis l'ancienne version du header.
Pour éviter de perdre les menus D4, capacités, paiements, administration, etc.,
ce pack ajoute un fichier autonome :

`includes/sidebar-encadreur.php`

Il faut seulement faire **deux petites insertions** dans ton `includes/app-header.php`.

---

## 1. Nom de l'espace

Dans le bloc `$spaceName`, ajoute le cas ENCADREUR.

Exemple :

```php
$spaceName=match($role){
    'SUPER_ADMIN'=>'Administration nationale',
    'ADMIN_ACCUEIL'=>'Espace accueil',
    'ENCADREUR'=>'Espace encadreur',
    'STAGIAIRE'=>'Espace stagiaire',
    default=>'Espace établissement'
};
```

Tu peux garder les autres valeurs actuelles de ton fichier.

---

## 2. Ajouter la branche ENCADREUR dans le sidebar

Dans `<nav class="sidebar-menu" id="sidebarMenu">`, repère la branche :

```php
<?php elseif($role==='STAGIAIRE'): ?>
```

Juste **avant**, ajoute :

```php
<?php elseif($role==='ENCADREUR'): ?>

    <?php require __DIR__.'/sidebar-encadreur.php'; ?>

```

Ne remplace pas les branches SUPER_ADMIN, université, ADMIN_ACCUEIL ou STAGIAIRE.

---

## Menu obtenu

Selon les permissions réellement actives :

- Tableau de bord
- MES STAGIAIRES
  - Suivi de mes stagiaires
- SUIVI
  - Présences
  - Journaux de stage
  - Évaluations

Un lien ne s'affiche que si le compte possède la permission correspondante.

### Liens volontairement absents

L'encadreur n'est pas administrateur de l'hôpital. Il ne voit donc pas :

- Sollicitations D4
- Campagnes d'accueil
- Capacités d'accueil
- Demandes étudiantes
- Stagiaires attendus globaux
- Services / unités en gestion
- Affectations administratives
- Planification globale des rotations
- Clôture administrative
- Paiements
- Administration / utilisateurs

---

## Sécurité du périmètre

La sidebar ne sécurise que l'affichage.

La page `suivi-encadreur.php` et ses endpoints D3.4 continuent à limiter
les données de l'ENCADREUR aux rotations où il existe dans
`stage_rotation_supervisors` avec `actif=1`.

ADMIN_ACCUEIL conserve la visibilité globale de son établissement.

---

## Installation

1. Importer :
   `migration/stagia_migration_30_sidebar_encadreur.sql`

2. Copier :
   `includes/sidebar-encadreur.php`

3. Remplacer la version D3.4 de :
   `views/espace-hopital/suivi-encadreur.php`

4. Faire les 2 petites insertions ci-dessus dans :
   `includes/app-header.php`

5. Se déconnecter/reconnecter avec un compte ENCADREUR.

6. `Ctrl + F5`.

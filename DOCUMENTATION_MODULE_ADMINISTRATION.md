# STAGIA-RDC — Administration multi-organisation

Version du lot : **1.2.0 — 9 septembre 2026**

## Objectif

Ce lot étend l’administration existante sans modifier l’architecture générale de STAGIA. Il apporte :

- des rôles système visibles dans toute la plateforme ;
- des rôles locaux appartenant à un seul établissement ;
- la création et la gestion des rôles locaux par l’administrateur principal de l’établissement ;
- l’affectation contrôlée des rôles et permissions aux utilisateurs de son établissement ;
- le rattachement explicite des organisations aux ministères ;
- un annuaire des responsables limité au périmètre autorisé ;
- des statistiques étudiants filtrables par année académique, établissement, faculté, département, promotion et province.
- un espace Ministère dédié aux organisations rattachées, à leurs responsables et au pilotage académique autorisé.

## Règles appliquées

### Rôles

1. Un rôle système a `roles.systeme = 1` et `roles.etablissement_id IS NULL`.
2. Seul le super administrateur principal crée ou modifie les rôles système.
3. Un rôle local a `roles.systeme = 0` et porte l’identifiant de son établissement dans `roles.etablissement_id`.
4. Un administrateur principal local ne voit que les rôles système et les rôles locaux de son établissement.
5. Il ne peut modifier, désactiver ou attribuer que les rôles locaux de son établissement et les rôles système autorisés par STAGIA.
6. Il ne peut pas transférer à un rôle local une permission qu’il ne possède pas lui-même.
7. L’administrateur principal est reconnu par une affectation active marquée `principal = 1` avec le rôle `ADMIN_ETABLISSEMENT`, `ADMIN_ACCUEIL` ou `ADMIN_PRINCIPAL`.

### Ministères et responsables

La table `organisations_ministeres` matérialise les rattachements. Le super administrateur configure ces liens depuis **Administration > Rattachements ministériels**.

- un administrateur principal local voit les responsables de son établissement ;
- un responsable `MINISTERE` voit les responsables des organisations actives rattachées à son ministère ;
- le super administrateur principal voit tous les responsables ;
- les comptes étudiants (`STAGIAIRE`) sont exclus de cet annuaire.

Lors de la création d’un utilisateur portant le rôle `MINISTERE`, le super administrateur principal doit sélectionner le ministère représenté. STAGIA vérifie côté serveur que l’organisation choisie possède réellement le type `MINISTERE`, puis enregistre atomiquement :

- le compte dans `users` ;
- son appartenance dans `etablissement_users` ;
- son rôle principal dans `role_assignments` avec la portée `ORGANIZATION` ;
- `scope_entity = ESTABLISHMENT` et l’identifiant du ministère comme périmètre.

Le rôle `SUPER_ADMIN` reste le seul rôle de ce parcours automatiquement affecté à toute la plateforme. Le même contrôle ministériel est appliqué dans l’écran avancé des affectations afin d’empêcher un contournement après la création du compte.

### Statistiques

Le tableau **Statistiques étudiants** utilise les inscriptions académiques existantes. Les filtres peuvent être combinés :

- année académique ;
- université ou établissement ;
- faculté ;
- département ;
- promotion ;
- province.

Le périmètre est toujours appliqué côté serveur. Un identifiant d’établissement ajouté manuellement à l’URL ne permet donc pas de consulter une organisation interdite.

## Installation

### 1. Configuration locale

Copier le modèle puis renseigner les paramètres propres à la machine :

```bash
cp .env.example .env
```

Le fichier `.env` n’est pas versionné.

### 2. Migration

Depuis la racine du projet :

```bash
mysql -h 127.0.0.1 -P 3306 -u VOTRE_UTILISATEUR -p VOTRE_BASE \
  < database/migrations/2026_09_09_000003_administration_multi_organisation.sql
```

Cette migration doit être exécutée **une seule fois**. Elle conserve les rôles existants comme rôles système et ajoute les nouvelles permissions aux profils administratifs concernés.

Activer ensuite l’espace Ministère :

```bash
mysql -h 127.0.0.1 -P 3306 -u VOTRE_UTILISATEUR -p VOTRE_BASE \
  < database/migrations/2026_09_09_000004_espace_ministere.sql
```

### 3. Rafraîchissement de session

Après la migration, se déconnecter puis se reconnecter pour recharger les permissions de session.

### 4. Démarrage local

```bash
php -S 127.0.0.1:8001
```

## Parcours de validation conseillé

1. Avec le super administrateur, créer ou modifier un rôle système.
2. Rattacher deux organisations à un établissement de type `MINISTERE`.
3. Avec l’administrateur principal de l’établissement A, créer un rôle local et lui attribuer des permissions.
4. Vérifier que l’administrateur de l’établissement B voit les rôles système, mais pas le rôle local de A.
5. Affecter le rôle local de A à un utilisateur de A, puis vérifier qu’une affectation à un utilisateur de B est refusée.
6. Avec le responsable ministériel, vérifier que l’annuaire ne retourne que les responsables des organisations rattachées.
7. Tester les statistiques avec plusieurs combinaisons de filtres et tenter un identifiant d’établissement hors périmètre.
8. Créer un responsable `MINISTERE`, vérifier que le formulaire ne propose que les ministères et refuse l’enregistrement sans sélection.
9. Contrôler en base que son affectation possède `scope_type='ORGANIZATION'` et que son appartenance ministérielle existe.

## Fichiers principaux du lot

- `database/migrations/2026_09_09_000003_administration_multi_organisation.sql`
- `includes/admin-scope.php`
- `actions/admin/roles/`
- `actions/admin/affectations/`
- `actions/admin/responsables/list.php`
- `actions/admin/ministeres/organisations.php`
- `actions/national/dashboard-data.php`
- `actions/national/export-etudiants.php`
- `views/admin/roles/index.php`
- `views/admin/affectations/index.php`
- `views/admin/responsables/index.php`
- `views/admin/ministeres/organisations.php`
- `views/national/dashboard.php`
- `database/verifications/2026_09_09_responsables_ministeriels.sql`

## Sécurité et exploitation

- Les contrôles de périmètre sont réalisés dans les actions PHP, pas uniquement dans les écrans.
- Les opérations d’écriture exigent l’authentification, la permission appropriée et un jeton CSRF valide.
- Les secrets MySQL et SMTP ne sont plus inscrits dans les fichiers PHP.
- Les erreurs sensibles sont journalisées côté serveur et ne doivent pas être exposées en production.
- Une sauvegarde de la base est recommandée avant toute migration de production.

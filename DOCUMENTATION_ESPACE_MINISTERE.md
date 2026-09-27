# STAGIA-RDC — Espace Ministère

Version : **1.2.0 — 9 septembre 2026**

## Fonctionnalités livrées

L’espace Ministère reprend l’architecture PHP, PDO, RBAC et les composants d’interface déjà employés par l’équipe. Il fournit :

- une redirection automatique vers l’espace Ministère après la connexion ;
- un tableau de bord propre au ministère représenté ;
- les indicateurs des organisations rattachées ;
- le nombre de responsables institutionnels actifs ;
- les effectifs étudiants agrégés du domaine santé ;
- la couverture par province et par année académique ;
- la répartition des organisations par type ;
- une liste recherchable des universités, hôpitaux et structures supervisées ;
- des filtres par type et par province ;
- l’accès aux responsables de chaque organisation ;
- l’accès aux statistiques détaillées par établissement, année, faculté, département, promotion et province ;
- l’export des statistiques lorsque la permission correspondante est attribuée.

## Périmètre de sécurité

La source de vérité reste :

- `etablissements` pour l’identité du ministère et des organisations ;
- `etablissement_users` pour le ministère représenté par l’utilisateur ;
- `role_assignments` pour le rôle `MINISTERE` et sa portée `ORGANIZATION` ;
- `organisations_ministeres` pour les organisations supervisées.

Toutes les API recalculent le périmètre depuis la base. Les identifiants reçus dans l’URL ne permettent pas d’accéder à une organisation non rattachée.

## Installation

Exécuter les migrations dans cet ordre :

```bash
mysql -h 127.0.0.1 -P 3306 -u VOTRE_UTILISATEUR -p VOTRE_BASE \
  < database/migrations/2026_09_09_000003_administration_multi_organisation.sql

mysql -h 127.0.0.1 -P 3306 -u VOTRE_UTILISATEUR -p VOTRE_BASE \
  < database/migrations/2026_09_09_000004_espace_ministere.sql
```

Ne pas rejouer la migration `000003` si elle a déjà été exécutée.

## Préparation par le super administrateur principal

1. Créer ou valider un établissement ayant `type_etablissement = MINISTERE`.
2. Créer le responsable avec le rôle système `MINISTERE`.
3. Sélectionner obligatoirement le ministère représenté.
4. Ouvrir **Administration > Rattachements ministériels**.
5. Sélectionner le ministère puis les organisations qu’il supervise.
6. Enregistrer les rattachements.
7. Demander au responsable de se déconnecter puis de se reconnecter.

## Routes principales

| Fonction | Vue | API |
|---|---|---|
| Tableau de bord | `views/espace-ministere/dashboard.php` | `actions/ministere/dashboard.php` |
| Organisations supervisées | `views/espace-ministere/organisations.php` | `actions/ministere/organisations.php` |
| Responsables | `views/admin/responsables/index.php` | `actions/admin/responsables/list.php` |
| Statistiques détaillées | `views/national/dashboard.php` | `actions/national/dashboard-data.php` |
| Export agrégé | — | `actions/national/export-etudiants.php` |

## Scénario de test

1. Rattacher l’université A et l’hôpital B au ministère M.
2. Ne pas rattacher l’université C.
3. Se connecter avec le responsable de M.
4. Vérifier que A et B apparaissent, mais jamais C.
5. Ouvrir les responsables de A puis les statistiques de A.
6. Essayer manuellement l’identifiant de C dans les paramètres d’URL : la requête doit être refusée.
7. Vérifier les filtres année, faculté, département, promotion et province.
8. Vérifier qu’un utilisateur sans rôle `MINISTERE` reçoit une réponse `403` sur les routes ministérielles.

## Migration depuis un ancien compte ministère

Le fichier suivant détecte sans modifier les comptes historiques encore affectés à la plateforme entière :

```bash
mysql -h 127.0.0.1 -P 3306 -u VOTRE_UTILISATEUR -p VOTRE_BASE \
  < database/verifications/2026_09_09_responsables_ministeriels.sql
```

Une anomalie détectée doit être corrigée en créant une nouvelle affectation `ORGANIZATION` vers le ministère concerné, puis en révoquant l’ancienne affectation `PLATFORM`.

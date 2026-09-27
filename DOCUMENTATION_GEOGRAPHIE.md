# STAGIA — Localisation des établissements, v1.1.0

## Objet et périmètre

L’adhésion utilise un référentiel géographique maintenu en base par le super administrateur principal de STAGIA. L’administration d’établissement et les responsables ministériels ne peuvent pas le modifier. Le rôle existant `SUPER_ADMIN`, actif et principal dans la session, reste la référence ; aucun compte ni rôle supplémentaire n’est créé.

L’interface propose province → ville/territoire → commune/secteur/chefferie. Pour Kinshasa, elle propose directement les communes sous la province. Un changement de parent remet les sélections dépendantes à zéro. Le serveur recontrôle le chemin et l’état actif de chaque parent : modifier les identifiants dans une requête ne permet pas de mélanger les provinces.

**Couverture livrée : 26 provinces, 33 villes (Kinshasa reste une ville-province), 145 territoires, 139 communes urbaines et 734 secteurs/chefferies.** Les rattachements proviennent du Référentiel Géographique Commun et des nomenclatures INS, puis sont recoupés avec la liste des villes et communes publiée par la CENI en 2023. Les 174 communes rurales ne sont pas toutes structurées faute d’une source nationale publiant sans ambiguïté leur parent direct ; le champ de précision reste disponible. Les groupements et villages ne sont pas des niveaux structurés dans cette version.

Sources : [Constitution, article 2, Journal officiel du 5 février 2011, page 6](https://faolex.fao.org/docs/pdf/cng128142.pdf) pour les provinces ; [Référentiel Géographique Commun](https://www.rgc.cd/index.php?Itemid=183&id=41&option=com_content&view=category) et annuaires INS pour les territoires, secteurs et chefferies ; [liste CENI 2023](https://www.ceni.cd/index.php/manuel-electoral/villes-et-communes-de-la-republique-democratique-du-congo-telles-que-reprises-dans) pour les villes et communes. Sources consultées le 14 septembre 2026. Les codes géographiques sont des identifiants internes STAGIA, pas des codes ISO.

## Parcours d’adhésion

1. Sélectionner une province active.
2. Choisir la ville/territoire (ou la commune de Kinshasa), puis la subdivision proposée.
3. Si la localité est absente, indiquer la ville/territoire en texte libre ou préciser la subdivision manquante. Ces informations n’ajoutent jamais automatiquement une localité au référentiel.
4. Soumettre la demande avec les champs et la pièce justificative habituels.
5. Le super administrateur consulte le chemin et les précisions dans la fiche d’adhésion. Une localisation non entièrement structurée est signalée « à compléter ».
6. Lors de la validation, la même localisation est rattachée au nouvel établissement dans la transaction existante.

La saisie complémentaire évite de bloquer une adhésion dans une province dont le référentiel est incomplet. Si les listes secondaires ne chargent pas, la saisie écrite reste utilisable. Sans JavaScript, province + ville écrite + précision restent possibles. Si la base ou la liste des provinces est indisponible, la demande ne peut pas être enregistrée.

## Administration

Menu **Référentiel géographique** ou `/views/geographie/index.php`.

- Parcourir les provinces et leurs subdivisions ; revenir par le fil de navigation.
- Rechercher un nom dans le niveau courant.
- Ajouter une localité avec un type compatible, un nom et une référence de source.
- Corriger son nom ou sa source, l’activer ou la désactiver.
- Consulter les dernières modifications (acteur, localité, date) et le nombre de demandes à compléter.

Le parent et le type d’une fiche existante sont immuables. Si le rattachement initial est faux, désactiver la fiche et créer la bonne localité sous le bon parent. Il faut désactiver les enfants actifs avant leur parent. Aucune suppression physique n’est proposée. Un numéro de version évite d’écraser une modification concurrente.

Les dossiers déjà soumis conservent leurs libellés historiques, même après une correction du référentiel. **Les anciens dossiers et les saisies libres ne sont pas rapprochés automatiquement** : aucune localité n’est devinée à partir du nom. L’ajout ultérieur d’un secteur ne transforme donc pas une ancienne saisie libre en lien structuré. Une reprise de ces dossiers pourra être faite séparément après vérification par l’équipe.

## Tables ajoutées

| Table | Fonction |
|---|---|
| `geographie_unites` | Arbre géographique, code interne, type, parent, nom, source, état, version. |
| `geographie_localisations` | Lien unique avec la demande, puis l’établissement ; unité sélectionnée, précisions, indicateur à compléter et copie du chemin à la soumission. |
| `geographie_journal` | Créations et modifications du référentiel, acteur, état avant/après et date. |
| `geographie_version` | Révision et verrou de maintenance partagé entre administration et soumissions. |

Les clés vers `users`, `demandes_adhesion` et `etablissements` respectent leurs identifiants `INT` signés. L’arbre utilise des `INT UNSIGNED`. Des clés étrangères, une unicité parent/type/nom, une unicité par demande/établissement et des index encadrent les relations. Les règles de compatibilité des types et de chemin sont vérifiées en PHP. Les écritures SQL directes contournent ces règles applicatives : utiliser l’espace de gestion.

Les colonnes existantes `province` et `ville` sont toujours alimentées avec les noms canoniques sélectionnés, ou la ville déclarée si elle manque au référentiel. Aucun autre module n’a besoin de changer ses requêtes. La nouvelle donnée structurée est disponible via :

```sql
SELECT e.id, e.nom, e.province, e.ville,
       g.unite_id, g.precision_localite, g.a_completer, g.chemin_snapshot
FROM etablissements e
LEFT JOIN geographie_localisations g ON g.etablissement_id = e.id;
```

## Installation dans le projet existant

Prérequis : même socle de l’équipe, PHP 8.1+, extensions `pdo_mysql` et `mbstring`, MySQL 8.0+, tables métier InnoDB. L’utilisateur MySQL configuré doit pouvoir créer les quatre tables et leurs contraintes. La configuration de connexion du projet cible est utilisée ; aucun `.env`, mot de passe, dossier `vendor` ou donnée personnelle n’est fourni.

Le correctif est déjà déposé dans `~/Projets/stagia2`. Sauvegarder la base, arrêter le serveur PHP local avec `Ctrl+C`, puis lancer :

```bash
cd ~/Projets/stagia2
php tests/geographie-regles.php
php tests/geographie-referentiel.php
php bin/installer-geographie-base.php ~/Projets/stagia2
php -S 127.0.0.1:8001
```

L’installateur de base :

1. Vérifie les extensions PHP, la connexion et les types des identifiants métier avant le DDL.
2. Exécute la migration de base puis la migration additive des subdivisions.
3. Contrôle la présence des quatre tables et affiche les volumes par type.

Il ne copie, ne remplace et ne supprime aucun fichier applicatif : les autres modules restent inchangés.

Une relance de la migration ne réactive pas les localités désactivées ni ne remplace les noms corrigés. Les créations de tables MySQL ne sont pas annulables ensemble : en cas d’échec SQL, corriger la cause puis relancer. Aucune table préexistante ne doit être supprimée pour résoudre une erreur.

Pour une exécution SQL séparée par l’équipe (inutile si l’installateur a terminé) :

```bash
mysql -h 127.0.0.1 -P 3306 -u Greak_Kay -p stagia_recovery \
  < database/migrations/2026_09_14_000007_geographie.sql
mysql -h 127.0.0.1 -P 3306 -u Greak_Kay -p stagia_recovery \
  < database/migrations/2026_09_14_000008_geographie_subdivisions.sql
```

La sauvegarde automatique concerne les fichiers, pas une copie complète de la base. Pour revenir aux écrans précédents, arrêter le serveur et restaurer les cinq fichiers sauvegardés ainsi que `includes/app-header.php`. Conserver les tables géographiques pour ne pas perdre les nouvelles localisations. Les fichiers ajoutés sont listés dans la sauvegarde ; leur retrait n’est pas nécessaire au retour des anciens écrans.

## Fichiers intégrés

Modifications ciblées : `adhesion.php`, `actions/adhesion/store.php`, `actions/adhesion/validate.php`, `views/adhesions/validate.php` (ancien point d’entrée encore présent), `views/adhesions/show.php`.

Ajouts : fonctions `includes/geographie.php`, fragment de formulaire, API publique des options, API de gestion protégée, vue d’administration et ressources CSS/JS propres, migration, scripts d’installation et tests. `includes/app-header.php` est modifié par insertion du lien, sans remplacer le fichier complet. `config/reference.php` reste disponible pour les autres pages qui l’utilisent.

## Vérification locale et recette d’équipe

```bash
php tests/geographie-regles.php
php tests/geographie-referentiel.php
mysql -h 127.0.0.1 -P 3306 -u Greak_Kay -p stagia_recovery \
  -e "SELECT type, COUNT(*) AS total FROM geographie_unites GROUP BY type;"
```

À vérifier dans une base de test : ajout d’un territoire puis d’un secteur par le super administrateur ; refus d’accès avec un administrateur d’établissement ; sélection du chemin à l’adhésion ; changement de province ; demande avec subdivision absente ; consultation puis validation ; liaison à l’établissement ; désactivation d’un parent et de ses enfants ; refus d’un chemin forgé ; modification concurrente d’une fiche. Vérifier aussi que connexion, anciennes demandes, communication et bibliothèque s’ouvrent toujours.

Vérifications effectuées pendant la préparation : syntaxe PHP et JavaScript, 21 scénarios de validation serveur, puis contrôle statique des 1 051 subdivisions (code unique, parent présent et type compatible). Le service MySQL local n’étant pas démarré, l’import réel et la recette visuelle avec vos comptes restent à exécuter après sauvegarde de la base.

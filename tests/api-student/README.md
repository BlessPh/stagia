# Tests de l'API mobile étudiante

La suite couvre quatre axes : contrat OpenAPI, scénarios métier de lecture et
d'authentification, isolation entre deux étudiants et concurrence sur la
dernière capacité disponible.

## Préparation

1. Copier `config.example.json` vers `config.local.json`.
2. Renseigner deux comptes étudiants actifs dédiés aux tests.
3. Créer des ressources appartenant à l'étudiant B et remplacer les UUID/ULID
   des cas d'autorisation.
4. Pour la concurrence, préparer une participation D4 avec exactement une
   place restante, renseigner les rattachements propres à A et B, puis passer
   `capacity_concurrency.enabled` à `true`.

`config.local.json` contient des secrets et ne doit pas être versionné.

## Exécution sous Windows/WAMP

```powershell
cd E:\Projets\Android\stagia
php tests\api-student\run.php contract
php tests\api-student\run.php integration authorization
php tests\api-student\run.php capacity-concurrency
```

La suite complète peut être lancée avec :

```powershell
php tests\api-student\run.php
```

Le test de concurrence doit uniquement tourner sur une fixture jetable. Quand
`cleanup` vaut `true`, la réservation gagnante est annulée après vérification.

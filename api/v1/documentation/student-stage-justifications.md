# Justifications de stage — API étudiant v1

Toutes les routes exigent un jeton Bearer appartenant à un utilisateur `STAGIAIRE` actif.

## Lister les stages et leurs justifications

`GET /api/v1/student/stage-justifications`

La réponse contient les stages de l’étudiant, la situation calculée, la justification éventuelle,
les statistiques, les valeurs autorisées et les actions disponibles. Cette lecture ne marque pas
automatiquement une décision comme lue.

## Déposer ou renvoyer une justification

`POST /api/v1/student/stage-justifications`

Corps JSON ou `multipart/form-data` :

- `assignment_uuid` : UUID de l’affectation ;
- `type_situation` : `NON_DEMARRAGE` ou `INTERRUPTION`, selon `allowed_type` renvoyé par la liste ;
- `motif` : une valeur de `allowed_values.reasons` ;
- `explication` : obligatoire lorsque le motif est `AUTRE` ;
- `document` : fichier facultatif PDF, JPG ou PNG, limité à 8 Mo (multipart uniquement).

Un second envoi pour la même affectation met à jour la justification et la replace en attente.
Une justification acceptée ne peut plus être modifiée.

## Consulter le document

`GET /api/v1/student/stage-justifications/{uuid}/file`

Ajouter `?download=1` pour forcer le téléchargement. Le document est toujours vérifié contre
l’identité de l’étudiant authentifié.

## Marquer la décision comme lue

`POST /api/v1/student/stage-justifications/{uuid}/read`

Disponible uniquement après une décision `ACCEPTEE` ou `REJETEE`.

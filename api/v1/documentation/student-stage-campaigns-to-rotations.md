# API étudiant — des campagnes aux rotations

Version 1.9.0 — mise à jour le 13 octobre 2026.

Cette documentation couvre le parcours mobile d’un étudiant depuis la consultation des campagnes ouvertes jusqu’aux résultats universitaires et aux documents de fin de stage.

## Conventions générales

Chemin de base : `/api/v1`

Toutes les routes exigent un jeton d’accès valide et un rôle `STAGIAIRE` actif :

```http
Authorization: Bearer <access_token>
Accept: application/json
```

Les requêtes `POST` utilisent également :

```http
Content-Type: application/json
```

Toutes les réponses suivent cette enveloppe :

```json
{
  "success": true,
  "message": "",
  "data": {}
}
```

En cas d’erreur :

```json
{
  "success": false,
  "message": "Description de l'erreur.",
  "data": {}
}
```

Règles concernant les collections :

- une liste est toujours un tableau JSON, même si elle est vide ou ne contient qu’un élément ;
- un filtre multi-valeurs accepte une liste séparée par des virgules, par exemple `status=ACTIVE,TERMINEE` ;
- les routes qui le prévoient acceptent aussi la notation `status[]=ACTIVE&status[]=TERMINEE` ;
- les identifiants numériques sont des nombres et les UUID sont des chaînes ;
- une unité de rotation vaut `null` lorsque la rotation vise directement un service sans subdivision plus précise.

Les exemples montrent les champs structurants utiles aux interfaces. Certaines réponses conservent aussi des champs plats historiques pour la compatibilité avec les anciens clients.

## Parcours fonctionnel

```text
Campagne ouverte
  → Candidature et réservation temporaire
  → Décision de l’université
  → Paiement éventuel
  → Placement
  → Admission à l’hôpital
  → Affectation dans un département
  → Rotations planifiées dans les services
  → Évaluations finales
  → Mini-jurys départementaux
  → Délibération finale hospitalière
  → Transmission et validation universitaire
  → Clôture hospitalière définitive
  → Attestation disponible
```

## Catalogue des statuts

| Objet | Statuts possibles |
|---|---|
| Campagne exposée à l’étudiant | `OUVERTE` |
| Candidature | `BROUILLON`, `SOUMISE`, `EN_ETUDE`, `ACCEPTEE`, `REFUSEE`, `ANNULEE` |
| Réservation | `RESERVEE_TEMPORAIREMENT`, `EN_ATTENTE_PAIEMENT`, `CONFIRMEE`, `EXPIREE`, `ANNULEE` |
| Paiement public | `NOT_REQUIRED`, `NOT_STARTED`, `PENDING`, `FAILED`, `PARTIAL`, `PAID`, `CANCELLED`, `EXPIRED` |
| Transaction | `INITIE`, `EN_ATTENTE`, `VALIDE`, `ECHOUE`, `ANNULE` |
| Facture | `EMISE`, `PARTIELLEMENT_PAYEE`, `PAYEE`, `ANNULEE`, `EXPIREE` |
| Obligation financière | `PENDING`, `PARTIALLY_PAID`, `PAID`, `CANCELLED`, `EXPIRED` |
| Placement | `CONFIRME`, `ANNULE`, `TERMINE` |
| Admission | `ATTENDU`, `ADMIS`, `EN_COURS`, `TERMINE`, `ANNULE` |
| Affectation | `PLANIFIEE`, `ACTIVE`, `TERMINEE`, `ANNULEE` |
| Rotation | `PLANIFIEE`, `ACTIVE`, `TERMINEE`, `ANNULEE` |
| Clôture du stage | `EN_PREPARATION`, `PRET`, `VALIDE`, `REFUSE`, `ANNULE` |
| Transmission universitaire | `NON_TRANSMIS`, `ENVOYE`, `RECU`, `VALIDE`, `ARCHIVE` |
| Décision de résultat | `VALIDE`, `A_REPRENDRE`, `AJOURNE`, `NON_VALIDE` (historique) |
| Document de fin de stage | `GENERE`, `ANNULE` |
| Rapport de stage | `SOUMIS`, `RECU`, `ACCEPTE`, `A_CORRIGER`, `REJETE`, `ARCHIVE` |
| Type de justification | `NON_DEMARRAGE`, `INTERRUPTION` |
| Décision de justification | `EN_ATTENTE`, `ACCEPTEE`, `REJETEE` |

Les statuts synthétiques du workflow sont :

- `DECISION_UNIVERSITAIRE_EN_ATTENTE`
- `EN_ATTENTE_PAIEMENT`
- `PLACEMENT_UNIVERSITAIRE_EN_ATTENTE`
- `PLACEMENT_ANNULE`
- `ADMISSION_HOSPITALIERE_EN_ATTENTE`
- `AFFECTATION_EN_ATTENTE`
- `STAGE_PLANIFIE`
- `STAGE_EN_COURS`
- `STAGE_TERMINE`
- `STAGE_VALIDE`
- `CANDIDATURE_REFUSEE`
- `ANNULEE`
- `RESERVATION_EXPIREE`

## Résumé des routes

| Méthode | Route | Fonction |
|---|---|---|
| `POST` | `/login` | Authentifier l’étudiant avec limitation des tentatives et réactivation éventuelle du compte |
| `GET` | `/student/dashboard` | Charger la synthèse mobile du parcours de stage |
| `GET` | `/student/stage-options` | Voir les campagnes ouvertes et les hôpitaux disponibles |
| `POST` | `/student/reservations` | Créer une candidature et une réservation temporaire |
| `GET` | `/student/applications` | Consulter les candidatures avec filtres |
| `GET` | `/student/reservations` | Consulter les réservations et les actions disponibles |
| `POST` | `/student/reservations/{uuid}/cancel` | Annuler une réservation temporaire |
| `GET` | `/student/payments` | Consulter les obligations, factures et paiements |
| `GET` | `/student/payments/{obligation_uuid}` | Consulter une facture ciblée depuis une notification |
| `POST` | `/student/payments/initiate` | Initier un paiement Mobile Money |
| `POST` | `/student/payments/sync` | Resynchroniser l’état financier d’une réservation |
| `GET` | `/student/admissions` | Suivre placement, admission et affectation |
| `GET` | `/student/stages` | Consulter les affectations et rotations ordonnées |
| `GET` | `/student/attendance` | Consulter l’historique filtrable et paginé des présences |
| `POST` | `/student/attendance/arrival` | Pointer l’arrivée dans la rotation active |
| `POST` | `/student/attendance/departure` | Pointer le départ de la rotation active |
| `GET` | `/student/execution-context` | Charger le contexte complet de la rotation active |
| `GET` | `/student/tasks` | Consulter les tâches filtrables et paginées |
| `POST` | `/student/tasks/{uuid}/{action}` | Démarrer, commenter ou terminer une tâche |
| `GET` | `/student/logbook` | Consulter le journal filtrable et paginé |
| `POST` | `/student/logbook` | Enregistrer un brouillon et recevoir l’entrée complète |
| `POST` | `/student/logbook/{uuid}/submit` | Soumettre le journal et recevoir l’entrée complète |
| `GET` | `/student/feedbacks` | Consulter les feedbacks filtrables sans lecture automatique |
| `POST` | `/student/feedbacks/{uuid}/read` | Marquer explicitement un feedback comme lu |
| `GET` | `/student/evaluations` | Consulter les évaluations filtrables et leur évaluateur |
| `GET` | `/student/completions` | Consulter les clôtures hospitalières de ses stages |
| `GET` | `/student/results` | Consulter ses résultats et leur validation universitaire |
| `GET` | `/student/documents` | Consulter ses attestations et certificats filtrables |
| `GET` | `/student/certificates/{uuid}/file` | Afficher ou télécharger une attestation disponible |
| `POST` | `/student/stage-reports` | Soumettre une nouvelle version d’un rapport de stage |
| `GET` | `/student/stage-reports` | Consulter l’historique des rapports envoyés |
| `GET` | `/student/stage-reports/{uuid}` | Consulter un rapport précis |
| `GET` | `/student/stage-reports/{uuid}/file` | Afficher ou télécharger le fichier soumis |
| `GET` | `/student/stage-justifications` | Consulter les situations et justifications de stage |
| `POST` | `/student/stage-justifications` | Déposer ou renvoyer une justification |
| `GET` | `/student/stage-justifications/{uuid}/file` | Afficher ou télécharger le justificatif |
| `POST` | `/student/stage-justifications/{uuid}/read` | Marquer la décision comme lue |
| `POST` | `/student/account/deactivate` | Désactiver le compte et programmer sa suppression à 30 jours |

---

## Fonctionnement réel du paiement Mobile Money

1. Une notification `financial.obligation.created` fournit `action.type=payment`, l’UUID dans `action.target_id` et la route de détail dans `action.metadata.detail_endpoint`.
2. `GET /student/payments/{obligation_uuid}` charge la facture ciblée, son contexte et les actions autorisées. `GET /student/payments` reste disponible pour toute la collection.
3. `POST /student/payments/initiate` transmet à MaishaPay l’obligation, l’opérateur, le numéro et le montant restant. Une réponse `PENDING` signifie seulement que la demande USSD a été acceptée par la passerelle.
4. L’opérateur affiche la demande sur le téléphone. L’étudiant saisit son code PIN directement dans l’interface de l’opérateur; le PIN ne transite jamais par STAGIA.
5. MaishaPay appelle `/maishapay-callback.php` avec l’issue finale signée. Le serveur enregistre `SUCCEEDED`, `FAILED` ou `CANCELLED`, met à jour l’obligation et applique les conséquences métier.
6. L’application appelle périodiquement `POST /student/payments/sync` pour relire cet état jusqu’à obtenir un résultat terminal. Une notification est également créée après confirmation ou échec.

`initiate` ne doit donc jamais être interprété comme une réussite financière. Le webhook est la source de l’issue finale et `sync` restitue l’état enregistré par ce webhook.

## 0A. Authentifier l’étudiant

```http
POST /api/v1/login
Content-Type: application/json

{
  "identifiant": "STG-ETU-00000030",
  "password": "mot-de-passe",
  "device_name": "Android"
}
```

La connexion reste réservée à un compte actif possédant le rôle `STAGIAIRE`. Les échecs répétés déclenchent un blocage temporaire avec le code `429` et l’en-tête `Retry-After`.

Si l’étudiant avait demandé la suppression de son compte et se reconnecte avant `delete_after`, la demande est annulée et la réponse contient `account_reactivated: true`. Une suppression déjà exécutée produit le code `410`.

## 0B. Charger le tableau de bord

```http
GET /api/v1/student/dashboard
```

La réponse fournit le profil, les compteurs, le stage courant et sa progression. Depuis la version `1.9` :

- `note_finale` reste `null` tant que la clôture du stage n’est pas `VALIDE` ;
- un certificat n’est compté ou affiché comme disponible qu’après validation universitaire du résultat.

## 1. Voir les campagnes ouvertes

```http
GET /api/v1/student/stage-options
```

La route retourne uniquement les campagnes auxquelles l’étudiant est éligible selon son inscription académique. Les campagnes gérées par l’université restent réservables par l’étudiant, mais son choix doit être confirmé par l’université.

### Réponse attendue

```json
{
  "success": true,
  "message": "",
  "data": {
    "campaigns": [
      {
        "campaign_id": 39,
        "academic_enrollment_id": 43,
        "code": "CAM-000039",
        "title": "Stage de fin d'année 2026",
        "start_date": "2026-10-01",
        "end_date": "2026-10-31",
        "stage_type": {
          "code": "SPECIALISATION",
          "label": "Spécialisation"
        },
        "mode": {
          "is_d4": false,
          "self_reservation_allowed": true,
          "reservation_mode": "STUDENT_CHOICE_UNIVERSITY_CONFIRMATION"
        },
        "promotion": {
          "code": "PRO-0009",
          "name": "Médecine Humaine · 2026-2027 · L1",
          "level": "L1"
        },
        "program": "Médecine Humaine",
        "hospitals": [
          {
            "participation_id": 81,
            "hospital": {
              "code": "HOP-001",
              "name": "Hôpital Général",
              "phone": "+243000000000",
              "city": "Kinshasa",
              "province": "Kinshasa",
              "services": []
            },
            "capacity": 20,
            "used_places": 7,
            "available_places": 13,
            "available": true,
            "fees_required": true,
            "amount": 25,
            "currency": "USD",
            "conditions": null
          }
        ],
        "hospitals_count": 1,
        "available_hospitals": 1
      }
    ],
    "university_managed_campaigns": [],
    "stats": {
      "campaigns": 1,
      "university_managed_campaigns": 0,
      "hospitals": 1,
      "available_hospitals": 1,
      "available_places": 13
    }
  }
}
```

`participation_id` est nécessaire pour créer une réservation. `university_managed_campaigns` est conservée pour compatibilité, mais les campagnes concernées sont désormais intégrées à `campaigns`.

## 2. Créer une réservation temporaire

```http
POST /api/v1/student/reservations
```

### Corps

```json
{
  "campaign_id": 39,
  "academic_enrollment_id": 43,
  "participation_id": 81,
  "motivation": "Je souhaite effectuer ce stage dans cet établissement."
}
```

`motivation` est facultatif. Les trois identifiants sont obligatoires.

### Réponse attendue

La création retourne `201`. Une nouvelle soumission identique retourne `200` avec `created: false` au lieu de dupliquer la réservation.

```json
{
  "success": true,
  "message": "Réservation temporaire créée.",
  "data": {
    "created": true,
    "application_id": 120,
    "application_uuid": "9fb326fc-38a9-4eed-96ef-c33dfe55cc83",
    "application_status": "SOUMISE",
    "reservation_id": 92,
    "reservation_uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0",
    "reservation_status": "RESERVEE_TEMPORAIREMENT",
    "expires_at": "2026-10-08 12:00:00",
    "workflow_status": "DECISION_UNIVERSITAIRE_EN_ATTENTE"
  }
}
```

Erreurs principales : `409` pour un conflit métier et `422` pour des paramètres invalides.

## 3. Consulter les candidatures

```http
GET /api/v1/student/applications
```

### Filtres

| Paramètre | Type | Description |
|---|---|---|
| `status` | collection | Un ou plusieurs statuts de candidature |
| `reservation_status` | collection | Un ou plusieurs statuts de réservation |
| `workflow_status` | collection | Un ou plusieurs statuts synthétiques du workflow |
| `campaign_id` | entier | Limiter à une campagne |
| `hospital_id` | entier | Limiter à un hôpital |
| `page` | entier | Page, valeur par défaut `1` |
| `per_page` | entier | Taille de page, valeur par défaut `20`, maximum `100` |

Exemple :

```http
GET /api/v1/student/applications?status=SOUMISE,ACCEPTEE&workflow_status=EN_ATTENTE_PAIEMENT,PLACEMENT_UNIVERSITAIRE_EN_ATTENTE&page=1&per_page=20
```

### Réponse attendue

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "uuid": "9fb326fc-38a9-4eed-96ef-c33dfe55cc83",
        "application_status": "ACCEPTEE",
        "motivation": "Je souhaite effectuer ce stage dans cet établissement.",
        "refusal_reason": null,
        "campaign_id": 39,
        "campaign_code": "CAM-000039",
        "campaign_title": "Stage de fin d'année 2026",
        "hospital_id": 8,
        "hospital_name": "Hôpital Général",
        "reservation_uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0",
        "reservation_status": "EN_ATTENTE_PAIEMENT",
        "placement": null,
        "admission_uuid": null,
        "assignment_uuid": null,
        "assignment_status": null,
        "completion_status": null,
        "workflow_status": "EN_ATTENTE_PAIEMENT",
        "workflow_message": "Votre réservation a été approuvée. Le paiement reste requis."
      }
    ],
    "total": 1,
    "stats": {
      "total": 4,
      "draft": 0,
      "submitted": 2,
      "under_review": 0,
      "accepted": 1,
      "rejected": 1,
      "cancelled": 0
    },
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 1,
      "pages": 1,
      "from": 1,
      "to": 1
    },
    "filters": {
      "status": ["SOUMISE", "ACCEPTEE"],
      "reservation_status": [],
      "workflow_status": ["EN_ATTENTE_PAIEMENT", "PLACEMENT_UNIVERSITAIRE_EN_ATTENTE"],
      "campaign_id": null,
      "hospital_id": null
    },
    "available_filters": {
      "status": ["BROUILLON", "SOUMISE", "EN_ETUDE", "ACCEPTEE", "REFUSEE", "ANNULEE"],
      "reservation_status": ["RESERVEE_TEMPORAIREMENT", "EN_ATTENTE_PAIEMENT", "CONFIRMEE", "EXPIREE", "ANNULEE"],
      "workflow_status": [
        "DECISION_UNIVERSITAIRE_EN_ATTENTE",
        "EN_ATTENTE_PAIEMENT",
        "PLACEMENT_UNIVERSITAIRE_EN_ATTENTE",
        "PLACEMENT_ANNULE",
        "ADMISSION_HOSPITALIERE_EN_ATTENTE",
        "AFFECTATION_EN_ATTENTE",
        "STAGE_PLANIFIE",
        "STAGE_EN_COURS",
        "STAGE_TERMINE",
        "STAGE_VALIDE",
        "CANDIDATURE_REFUSEE",
        "ANNULEE",
        "RESERVATION_EXPIREE"
      ]
    }
  }
}
```

`stats` décrit l’ensemble de l’historique, tandis que `total` et `pagination` concernent le résultat filtré.

`note_finale` reste `null` tant que `completion_status` n’est pas `VALIDE`. Une note intermédiaire ou une délibération non encore validée ne doit donc pas être affichée comme résultat officiel.

## 4. Consulter les réservations

```http
GET /api/v1/student/reservations
```

### Filtres

| Paramètre | Type | Description |
|---|---|---|
| `status` | collection | Statuts de réservation |
| `application_status` | collection | Statuts de candidature |
| `workflow_status` | collection | Statuts synthétiques du workflow |
| `payment_status` | collection | Statuts publics de paiement |
| `campaign_id` | entier | Campagne précise |
| `hospital_id` | entier | Hôpital précis |
| `actionable` | valeur | `CANCEL` pour ne voir que les réservations annulables |
| `page` | entier | Page, par défaut `1` |
| `per_page` | entier | Éléments par page, par défaut `20`, maximum `100` |

Exemple :

```http
GET /api/v1/student/reservations?status=RESERVEE_TEMPORAIREMENT,EN_ATTENTE_PAIEMENT&payment_status=NOT_STARTED,PENDING
```

### Structure d’un élément

Chaque entrée fournit les objets utiles à l’interface :

- `reservation`
- `application`
- `campaign`
- `stage_type`
- `mode`
- `academic_context`
- `hospital`
- `payment`
- `placement`
- `admission`
- `assignment`
- `completion`
- `workflow`
- `actions`

### Réponse attendue

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "reservation": {
          "uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0",
          "status": "RESERVEE_TEMPORAIREMENT",
          "expires_at": "2026-10-08 12:00:00"
        },
        "application": {
          "uuid": "9fb326fc-38a9-4eed-96ef-c33dfe55cc83",
          "status": "SOUMISE"
        },
        "campaign": {
          "id": 39,
          "code": "CAM-000039",
          "title": "Stage de fin d'année 2026"
        },
        "hospital": {
          "id": 8,
          "name": "Hôpital Général"
        },
        "payment": {
          "required": false,
          "status": "NOT_REQUIRED"
        },
        "placement": null,
        "admission": null,
        "assignment": null,
        "completion": null,
        "workflow": {
          "status": "DECISION_UNIVERSITAIRE_EN_ATTENTE",
          "message": "Votre réservation attend la décision de l'université."
        },
        "actions": {
          "cancel": {
            "allowed": true,
            "method": "POST",
            "endpoint": "/api/v1/student/reservations/24e27142-f88d-4655-b7db-1ae4f27e39b0/cancel"
          }
        }
      }
    ],
    "stats": {
      "total": 1,
      "temporary": 1,
      "waiting_payment": 0,
      "confirmed": 0,
      "expired": 0,
      "cancelled": 0,
      "can_cancel": 1
    },
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 1,
      "pages": 1,
      "from": 1,
      "to": 1
    },
    "filters": {},
    "available_filters": {}
  }
}
```

Une réservation n’est annulable que si elle est temporaire, non expirée, sans décision universitaire, sans paiement bloquant, sans placement et sans admission.

## 5. Annuler une réservation temporaire

```http
POST /api/v1/student/reservations/{uuid}/cancel
```

Le corps peut être vide. L’opération est idempotente : répéter l’annulation d’une réservation déjà annulée ne crée pas d’erreur métier.

### Réponse attendue

```json
{
  "success": true,
  "message": "Réservation annulée.",
  "data": {
    "reservation_uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0",
    "reservation_status": "ANNULEE",
    "application_status": "ANNULEE",
    "idempotent": false
  }
}
```

Erreurs principales : `404` si la réservation n’appartient pas à l’étudiant, `409` si une décision, un paiement, un placement ou une admission empêche l’annulation.

## 6. Consulter les paiements et obligations

```http
GET /api/v1/student/payments
```

Filtres facultatifs :

- `status=PENDING,PARTIALLY_PAID` ou `statuses[]=PENDING&statuses[]=PARTIALLY_PAID` ;
- `type=STAGE_RESERVATION` pour limiter la collection à un type d’obligation.

La réponse contient deux collections :

- `items`, conservée pour les anciens clients et centrée sur les paiements de stage ;
- `obligations`, collection canonique de toutes les obligations financières de l’utilisateur.

### Réponse attendue

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "application_uuid": "9fb326fc-38a9-4eed-96ef-c33dfe55cc83",
        "reservation_uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0",
        "reservation_status": "EN_ATTENTE_PAIEMENT",
        "campaign": {
          "id": 39,
          "code": "CAM-000039",
          "title": "Stage de fin d'année 2026"
        },
        "hospital": {
          "id": 8,
          "name": "Hôpital Général"
        },
        "payment_required": true,
        "payment_allowed": true,
        "payment_status": "NOT_STARTED",
        "amount_required": 25,
        "amount_validated": 0,
        "amount_remaining": 25,
        "currency": "USD",
        "invoice": {},
        "obligation": {},
        "payments": []
      }
    ],
    "obligations": [
      {
        "uuid": "109114cb-d48f-4220-8e67-0bc7469d6abd",
        "reference": "OBL-2026-000052",
        "type": "STAGE_RESERVATION",
        "subject": {"type": "STAGE_RESERVATION", "key": "92"},
        "label": "Frais de réservation de stage",
        "amount": 25,
        "currency": "USD",
        "status": "PENDING",
        "amount_paid": 0,
        "amount_remaining": 25,
        "payment_status": "NOT_STARTED",
        "payable": true,
        "payment_pending": false,
        "due_at": null,
        "payments": []
      }
    ],
    "obligation_stats": {
      "total": 1,
      "payable": 1,
      "pending": 0,
      "paid": 0,
      "failed": 0,
      "cancelled": 0,
      "expired": 0
    },
    "stats": {
      "total": 1,
      "payable": 1,
      "paid": 0,
      "pending": 0,
      "failed": 0,
      "free": 0
    },
    "channels": {
      "MPESA": "M-Pesa",
      "ORANGE_MONEY": "Orange Money",
      "AIRTEL_MONEY": "Airtel Money",
      "AFRIMONEY": "Afrimoney",
      "BANQUE": "Banque",
      "CARTE": "Carte bancaire"
    }
  }
}
```

`BANQUE` et `CARTE` figurent dans le catalogue global, mais l’initiation en ligne MaishaPay accepte actuellement les quatre canaux Mobile Money.

## 7. Consulter une obligation ou une facture

```http
GET /api/v1/student/payments/{obligation_uuid}
Authorization: Bearer <access_token>
```

Cette route est la cible de l’action envoyée avec la notification de paiement. L’application mobile lit `action.target_id`, ouvre son écran de facture, puis appelle cette route. Une obligation appartenant à un autre utilisateur retourne `404` sans divulguer son existence.

### Réponse attendue

```json
{
  "success": true,
  "message": "Obligation financière chargée.",
  "data": {
    "obligation": {
      "uuid": "109114cb-d48f-4220-8e67-0bc7469d6abd",
      "reference": "OBL-2026-000052",
      "type": "STAGE_RESERVATION",
      "label": "Frais de réservation de stage",
      "amount": 10000,
      "currency": "CDF",
      "status": "PENDING",
      "amount_paid": 0,
      "amount_remaining": 10000,
      "payment_status": "NOT_STARTED",
      "payable": true,
      "payment_pending": false,
      "due_at": null,
      "payments": [],
      "context": {
        "type": "STAGE_RESERVATION",
        "reservation": {"uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0", "status": "EN_ATTENTE_PAIEMENT", "expires_at": null},
        "application": {"uuid": "9fb326fc-38a9-4eed-96ef-c33dfe55cc83", "status": "ACCEPTEE"},
        "campaign": {"id": 39, "uuid": "campaign-uuid", "code": "CAM-000039", "title": "Stage de fin d'année 2026", "start_date": "2026-10-01", "end_date": "2026-10-31"},
        "hospital": {"id": 8, "code": "HOP-001", "name": "Hôpital Général", "phone": "+243810000000", "email": null, "address": null, "city": "Kinshasa", "province": "Kinshasa"},
        "invoice": {"uuid": "invoice-uuid", "reference": "FAC-STG-00000052", "amount": 10000, "currency": "CDF", "status": "EMISE", "issued_at": "2026-10-08 10:00:00", "due_at": null, "paid_at": null}
      },
      "actions": {
        "initiate": {"allowed": true, "method": "POST", "path": "/api/v1/student/payments/initiate", "required_body": ["obligation_uuid", "channel", "phone_number"], "required_header": "Idempotency-Key"},
        "sync": {"allowed": false, "method": "POST", "path": "/api/v1/student/payments/sync", "body": {"obligation_uuid": "109114cb-d48f-4220-8e67-0bc7469d6abd"}}
      }
    }
  }
}
```

Pour une obligation qui n’est pas liée à un stage, `context` peut valoir `null`. `actions.initiate.allowed` et `actions.sync.allowed` doivent piloter les boutons de l’interface mobile.

## 8. Initier un paiement Mobile Money

```http
POST /api/v1/student/payments/initiate
Idempotency-Key: <clé unique>
```

La cible canonique est `obligation_uuid`. `invoice_id`, `invoice_uuid` et `reservation_uuid` restent acceptés pour la compatibilité avec les anciens clients de stage.

Le mobile ne fournit volontairement ni `amount`, ni `currency`, ni `callback_url` :

- `amount` est le montant restant de l’obligation, calculé par le serveur ;
- `currency` provient de l’obligation (`USD`, `CDF`, etc.) ;
- `callbackUrl` provient de `MAISHAPAY_CALLBACK_URL` dans la configuration serveur.

Cela empêche un client de remplacer, par exemple, une obligation de `10 000 CDF` par `100 CDF` ou de détourner le callback vers son propre serveur.

### Corps

```json
{
  "obligation_uuid": "109114cb-d48f-4220-8e67-0bc7469d6abd",
  "channel": "MPESA",
  "phone_number": "+243810000000"
}
```

La clé d’idempotence peut aussi être placée dans le corps sous `idempotency_key`. Elle doit contenir entre 1 et 100 caractères.

### Réponse attendue

Une nouvelle transaction retourne `201`. La répétition avec la même clé retourne `200`. Dans les deux cas, le paiement reste normalement `PENDING` jusqu’à la réponse de l’opérateur reçue par le callback.

```json
{
  "success": true,
  "message": "Paiement initié.",
  "data": {
    "payment": {
      "created": true,
      "idempotent": false,
      "uuid": "70c4dd30-58fd-4075-aaf8-7540da899e43",
      "reference": "PAY-2026-000081",
      "transaction_reference": "MP-123456",
      "amount": 25,
      "currency": "USD",
      "channel": "MPESA",
      "operator": "VODACOM",
      "status": "INITIE",
      "phone_number": "+243810000000",
      "initiated_at": "2026-10-07 13:30:00"
    },
    "obligation": {
      "uuid": "109114cb-d48f-4220-8e67-0bc7469d6abd",
      "reference": "OBL-2026-000052",
      "status": "PENDING",
      "amount": 25,
      "currency": "USD"
    },
    "reservation_uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0"
  }
}
```

Erreurs principales : `404` pour une facture introuvable, `409` si elle n’est pas payable, `422` pour une cible, un canal, un téléphone ou une clé invalide.

## 9. Synchroniser l’état d’un paiement

```http
POST /api/v1/student/payments/sync
```

### Corps recommandé

```json
{
  "obligation_uuid": "109114cb-d48f-4220-8e67-0bc7469d6abd"
}
```

`reservation_uuid` reste accepté pour les anciens flux de stage. Cette route ne crée aucune transaction, ne contacte pas le téléphone et ne transforme jamais un simple délai d’attente en succès. Elle relit le résultat du callback, répare les projections métier et peut faire passer la réservation associée à `CONFIRMEE` ou `EXPIREE`.

### Réponse attendue

```json
{
  "success": true,
  "message": "État du paiement synchronisé.",
  "data": {
    "reservation_uuid": "24e27142-f88d-4655-b7db-1ae4f27e39b0",
    "reservation_status": "CONFIRMEE",
    "payment_status": "PAID",
    "invoice_status": "PAYEE",
    "amount_required": 25,
    "amount_validated": 25,
    "amount_remaining": 0,
    "currency": "USD",
    "placement_pending": true
  }
}
```

Dans certains cas techniques, `payment_status` peut aussi valoir `NOT_CONFIRMED` si le paiement ne permet pas encore de confirmer la réservation.

## 10. Suivre l’admission et l’affectation

```http
GET /api/v1/student/admissions
```

Filtre facultatif :

```http
GET /api/v1/student/admissions?reservation_uuid=24e27142-f88d-4655-b7db-1ae4f27e39b0
```

### Réponse attendue

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "reservation": {},
        "application": {},
        "placement": {
          "exists": true,
          "status": "CONFIRME"
        },
        "admission": {
          "exists": true,
          "uuid": "3f7cb984-6eea-4555-a3ec-f8195bb4c888",
          "status": "ADMIS"
        },
        "assignment": {
          "exists": true,
          "uuid": "aac385ad-2069-4929-b231-461e7da40c16",
          "status": "PLANIFIEE"
        },
        "campaign": {},
        "stage_type": {},
        "mode": {},
        "hospital": {},
        "completion": {},
        "workflow_status": "STAGE_PLANIFIE",
        "workflow_message": "Votre affectation est planifiée."
      }
    ],
    "stats": {
      "total": 1,
      "waiting_decision": 0,
      "waiting_payment": 0,
      "waiting_placement": 0,
      "waiting_admission": 0,
      "admitted": 1,
      "assigned": 1,
      "completed": 0,
      "refused": 0,
      "expired": 0
    }
  }
}
```

Les objets `placement`, `admission` et `assignment` contiennent toujours le champ `exists`, ce qui permet à l’interface d’afficher l’étape courante sans déductions fragiles.

Dans `completion`, `final_score` reste `null` tant que `completion.status` n’est pas `VALIDE`.

## 11. Consulter les affectations et rotations

```http
GET /api/v1/student/stages
```

### Filtres

| Paramètre | Alias | Type | Description |
|---|---|---|---|
| `status` | `statuses`, `assignment_status` | collection | Statuts d’affectation ; défaut : `PLANIFIEE,ACTIVE,TERMINEE` |
| `rotation_status` | `rotation_statuses` | collection | Statuts de rotation |
| `campaign_id` | `campaign_ids` | collection d’entiers | Campagnes |
| `hospital_id` | `hospital_ids` | collection d’entiers | Hôpitaux |
| `date_from` | — | date | L’affectation se termine à cette date ou après |
| `date_to` | — | date | L’affectation commence à cette date ou avant |
| `include` | — | collection | `rotations`, `supervisors`, `execution_access`, `summary` |

Par défaut, tous les blocs `include` sont retournés. Pour récupérer aussi les affectations annulées, il faut explicitement ajouter `ANNULEE` au filtre `status`.

Exemple :

```http
GET /api/v1/student/stages?status=PLANIFIEE,ACTIVE&rotation_status=PLANIFIEE,ACTIVE&include=rotations,supervisors,summary
```

### Réponse attendue

```json
{
  "success": true,
  "message": "",
  "data": {
    "student": {
      "id": 30,
      "stagia_code": "STG-ETU-00000030"
    },
    "items": [
      {
        "assignment_id": 47,
        "assignment_uuid": "aac385ad-2069-4929-b231-461e7da40c16",
        "assignment_status": "PLANIFIEE",
        "workflow_status": "PLANIFIE",
        "department": {
          "id": 12,
          "code": "DEP-PED",
          "name": "Département de pédiatrie",
          "type": "DEPARTEMENT",
          "active": true
        },
        "department_integrity": {
          "valid": true,
          "source": "stage_admissions.coordination_unit_id"
        },
        "hospital": {
          "id": 8,
          "code": "HOP-001",
          "name": "Hôpital Général",
          "phone": "+243000000000",
          "email": "contact@hopital.test",
          "location": {
            "city": "Kinshasa",
            "province": "Kinshasa"
          }
        },
        "campaign": {
          "id": 39,
          "uuid": "c8997aae-a879-43d0-a6e2-01c41d5d6167",
          "code": "CAM-000039",
          "title": "Stage de fin d'année 2026",
          "stage_type": {}
        },
        "period": {
          "start_date": "2026-10-10",
          "end_date": "2026-11-10"
        },
        "planning": {
          "current_rotation": null,
          "next_rotation": {
            "id": 71,
            "uuid": "f22bd970-53f8-4657-9913-19bd434daf48",
            "position": 1,
            "sequence": 1,
            "status": "PLANIFIEE",
            "period": {
              "start_date": "2026-10-10",
              "end_date": "2026-10-20"
            },
            "service": {
              "id": 31,
              "code": "SRV-PED",
              "name": "Pédiatrie",
              "type": "SERVICE"
            },
            "unit": {
              "id": 38,
              "code": "UNT-URG",
              "name": "Urgences",
              "type": "UNITE"
            },
            "supervisor": {
              "user_id": 82,
              "name": "Dr Exemple",
              "function": "Maître de stage"
            },
            "objectives": "Prise en charge initiale des urgences pédiatriques.",
            "observation": null,
            "integrity": {
              "valid": true,
              "service_target_valid": true,
              "belongs_to_assignment_department": true
            }
          },
          "rotations": [
            {
              "id": 71,
              "uuid": "f22bd970-53f8-4657-9913-19bd434daf48",
              "position": 1,
              "sequence": 1,
              "status": "PLANIFIEE"
            }
          ],
          "total": 1
        },
        "rotations": [
          {
            "id": 71,
            "uuid": "f22bd970-53f8-4657-9913-19bd434daf48",
            "position": 1,
            "sequence": 1,
            "status": "PLANIFIEE"
          }
        ],
        "execution_access": null
      }
    ],
    "filters": {
      "statuses": ["PLANIFIEE", "ACTIVE"],
      "rotation_statuses": ["PLANIFIEE", "ACTIVE"],
      "campaign_ids": [],
      "hospital_ids": [],
      "date_from": null,
      "date_to": null,
      "includes": ["rotations", "supervisors", "summary"]
    },
    "summary": {
      "total": 1,
      "by_status": {
        "PLANIFIEE": 1
      },
      "by_workflow_status": {
        "PLANIFIE": 1
      }
    }
  }
}
```

`planning.rotations` contient la collection complète et ordonnée. `rotations` est son alias de compatibilité. Les rotations sont triées par :

1. `sequence` croissante ;
2. date de début croissante ;
3. identifiant croissant.

`planning.current_rotation` désigne la rotation active à la date courante et `planning.next_rotation` la prochaine rotation planifiée.

La propriété `note_finale` reste `null` tant que `completion_status` n’est pas `VALIDE`.

### Règles d’intégrité

- le département d’affectation provient de `stage_admissions.coordination_unit_id` ;
- chaque rotation doit cibler un service rattaché à ce département, ou une unité enfant de ce service ;
- une ancienne donnée incohérente reste visible, mais `integrity.valid` vaut `false` afin que l’interface puisse la signaler ;
- `unit` peut être `null` lorsqu’aucune unité plus précise que le service n’est définie.

## 12. Consulter l’historique des présences

```http
GET /api/v1/student/attendance
Authorization: Bearer <access_token>
```

### Filtres

| Paramètre | Alias | Type | Description |
|---|---|---|---|
| `assignment_uuid` | — | UUID | Limite les résultats à une affectation |
| `rotation_uuid` | — | UUID | Limite les résultats à une rotation |
| `status` | `statuses` | collection | `PRESENT`, `RETARD`, `ABSENT`, `JUSTIFIE`, `GARDE` |
| `date_from` | — | date `YYYY-MM-DD` | Date minimale incluse |
| `date_to` | — | date `YYYY-MM-DD` | Date maximale incluse |
| `page` | — | entier | Page demandée, défaut : `1` |
| `per_page` | — | entier | Taille de page, défaut : `20`, maximum : `100` |

Le filtre de statut accepte les deux formes :

```http
GET /api/v1/student/attendance?status=PRESENT,RETARD
GET /api/v1/student/attendance?statuses[]=PRESENT&statuses[]=RETARD
```

Exemple combiné :

```http
GET /api/v1/student/attendance?assignment_uuid={uuid}&rotation_uuid={uuid}&status=PRESENT,RETARD&date_from=2026-10-01&date_to=2026-10-31&page=1&per_page=20
```

### Réponse attendue

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "uuid": "attendance-uuid",
        "date": "2026-10-09",
        "status": "PRESENT",
        "arrival_time": "08:02:00",
        "departure_time": "16:10:00",
        "source": "MOBILE",
        "minutes_late": 2,
        "duration_minutes": 488,
        "justification": null,
        "observation": null,
        "validated": true,
        "validated_at": "2026-10-09 17:00:00",
        "validator": {"user_id": 14, "name": "Docteur Encadreur"},
        "rotation": {"uuid": "rotation-uuid", "sequence": 1},
        "assignment": {"uuid": "assignment-uuid", "status": "ACTIVE", "start_date": "2026-10-01", "end_date": "2026-10-31"},
        "campaign": {"code": "CAM-000039", "title": "Stage de fin d'année 2026"},
        "hospital": {"code": "HOP-001", "name": "Hôpital Général"},
        "unit": {"id": 8, "code": "SRV-PED", "name": "Pédiatrie", "type": "SERVICE"}
      }
    ],
    "stats": {
      "total": 12,
      "present": 9,
      "late": 2,
      "absent": 1,
      "justified": 0,
      "guard": 0,
      "effective_presence": 11,
      "attendance_rate": 91.67,
      "minutes_late": 17,
      "total_duration_minutes": 5580,
      "validated": 10
    },
    "pagination": {"page": 1, "per_page": 20, "total": 12, "pages": 1, "from": 1, "to": 12},
    "filters": {
      "assignment_uuid": "assignment-uuid",
      "rotation_uuid": "rotation-uuid",
      "statuses": ["PRESENT", "RETARD"],
      "date_from": "2026-10-01",
      "date_to": "2026-10-31"
    },
    "available_filters": {
      "statuses": ["PRESENT", "RETARD", "ABSENT", "JUSTIFIE", "GARDE"],
      "per_page_max": 100
    }
  }
}
```

Les statistiques sont calculées sur la totalité des présences correspondant aux filtres, et non uniquement sur les éléments de la page courante.

## 13. Pointer l’arrivée

```http
POST /api/v1/student/attendance/arrival
Authorization: Bearer <access_token>
```

Le corps est vide. Le serveur identifie lui-même l’affectation, la rotation active et la date courante. Le mobile ne transmet jamais `assignment_uuid`, `rotation_uuid` ou `action`.

```json
{
  "success": true,
  "message": "Arrivée pointée à 08:02.",
  "data": {
    "punch": {
      "date": "2026-10-09",
      "assignment_id": 47,
      "rotation": {},
      "attendance": {
        "uuid": "attendance-uuid",
        "date_presence": "2026-10-09",
        "heure_arrivee": "08:02:00",
        "heure_depart": null,
        "statut": "PRESENT"
      },
      "can_punch": true,
      "next_action": "DEPART",
      "reason": null
    }
  }
}
```

## 14. Pointer le départ

```http
POST /api/v1/student/attendance/departure
Authorization: Bearer <access_token>
```

Le corps est vide. Une arrivée doit exister pour la journée courante.

```json
{
  "success": true,
  "message": "Départ pointé à 16:10.",
  "data": {
    "punch": {
      "date": "2026-10-09",
      "attendance": {
        "uuid": "attendance-uuid",
        "heure_arrivee": "08:02:00",
        "heure_depart": "16:10:00",
        "statut": "PRESENT"
      },
      "can_punch": false,
      "next_action": "TERMINE",
      "reason": "Votre pointage du jour est terminé."
    }
  }
}
```

## 15. Charger le contexte d’exécution

```http
GET /api/v1/student/execution-context?assignment_uuid={uuid}
```

`GET /student/attendance/context` est un alias. La réponse contient maintenant un objet `context` normalisé avec l’affectation, la campagne, l’hôpital, le département, les rotations actuelle et suivante, le pointage, les capacités et les actions `arrival`, `departure` et `logbook`. Les anciens objets `execution` et `punch` sont conservés pour compatibilité.

Lorsque `assignment_uuid` est fourni, le contexte et le pointage sont tous les deux limités à cette affectation.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "context": {
      "date": "2026-10-09",
      "assignment": {
        "uuid": "assignment-uuid",
        "status": "ACTIVE",
        "period": {"start_date": "2026-10-01", "end_date": "2026-10-31"}
      },
      "campaign": {"id": 39, "uuid": "campaign-uuid", "code": "CAM-000039", "title": "Stage clinique 2026"},
      "hospital": {
        "id": 7,
        "code": "HOP-001",
        "name": "Hôpital Général",
        "phone": "+243810000000",
        "email": "contact@hopital.cd",
        "address": "12, avenue de la Santé",
        "city": "Kinshasa",
        "province": "Kinshasa"
      },
      "department": {"id": 3, "code": "DEP-MED", "name": "Médecine", "type": "DEPARTEMENT"},
      "current_rotation": {
        "id": 81,
        "uuid": "rotation-uuid",
        "sequence": 1,
        "status": "ACTIVE",
        "period": {"start_date": "2026-10-01", "end_date": "2026-10-15"},
        "service": {"id": 8, "code": "SRV-PED", "name": "Pédiatrie", "type": "SERVICE"},
        "unit": {"id": 9, "code": "UNI-URG", "name": "Urgences", "type": "UNITE"},
        "supervisor": {"user_id": 14, "name": "Docteur Encadreur", "function": "Maître de stage"},
        "objectives": "Prise en charge initiale des urgences pédiatriques.",
        "observation": null
      },
      "next_rotation": null,
      "attendance": {
        "uuid": "attendance-uuid",
        "date_presence": "2026-10-09",
        "heure_arrivee": "08:02:00",
        "heure_depart": null,
        "statut": "PRESENT"
      },
      "actions": {
        "arrival": {"allowed": false, "method": "POST", "path": "/api/v1/student/attendance/arrival"},
        "departure": {"allowed": true, "method": "POST", "path": "/api/v1/student/attendance/departure"},
        "logbook": {"allowed": true, "method": "POST", "path": "/api/v1/student/logbook"}
      },
      "capabilities": {"attendance": true, "logbook": true, "evaluation": true},
      "reason": null,
      "unlock_date": null
    },
    "execution": {
      "date": "2026-10-09",
      "current_rotation": {},
      "next_rotation": null,
      "can_logbook": true,
      "can_attendance": true,
      "can_evaluation": true,
      "reason": null,
      "unlock_date": null
    },
    "punch": {
      "date": "2026-10-09",
      "assignment_id": 47,
      "attendance": {},
      "can_punch": true,
      "next_action": "DEPART",
      "reason": null
    }
  }
}
```

## 16. Consulter les tâches

```http
GET /api/v1/student/tasks?status=A_FAIRE,EN_COURS&priority=HAUTE,URGENTE&assignment_uuid={uuid}&rotation_uuid={uuid}&due_from=2026-10-01&due_to=2026-10-31&page=1&per_page=20
```

Filtres disponibles : `status`/`statuses`, `priority`/`priorities`, `assignment_uuid`, `rotation_uuid`, `due_from`, `due_to`, `page` et `per_page`. Les collections acceptent une liste séparée par des virgules ou la notation `status[]=...`.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "uuid": "task-uuid",
        "title": "Participer à la consultation pédiatrique",
        "description": "Réaliser l’anamnèse sous supervision.",
        "priority": "HAUTE",
        "status": "A_FAIRE",
        "due_date": "2026-10-12",
        "student_comment": null,
        "supervisor_comment": null,
        "started_at": null,
        "completed_at": null,
        "validated_at": null,
        "created_at": "2026-10-08 09:00:00",
        "updated_at": "2026-10-08 09:00:00",
        "training_plan_item": {"id": 4, "title": "Consultation supervisée"},
        "assignment": {"uuid": "assignment-uuid", "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-31"}},
        "rotation": {"uuid": "rotation-uuid", "sequence": 1, "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-15"}},
        "hospital": {"id": 7, "code": "HOP-001", "name": "Hôpital Général", "phone": "+243810000000"},
        "service": {"id": 8, "code": "SRV-PED", "name": "Pédiatrie", "type": "SERVICE"},
        "unit": {"id": 9, "code": "UNI-CONS", "name": "Consultation", "type": "UNITE"},
        "actions": {
          "start": {"allowed": true, "method": "POST", "path": "/api/v1/student/tasks/task-uuid/start"},
          "comment": {"allowed": true, "method": "POST", "path": "/api/v1/student/tasks/task-uuid/comment"},
          "complete": {"allowed": false, "method": "POST", "path": "/api/v1/student/tasks/task-uuid/complete"}
        }
      }
    ],
    "stats": {"total": 4, "a_faire": 1, "en_cours": 1, "terminees": 1, "a_revoir": 0, "validees": 1, "annulees": 0},
    "progress": 25,
    "pagination": {"page": 1, "per_page": 20, "total": 4, "pages": 1, "from": 1, "to": 4},
    "filters": {
      "statuses": ["A_FAIRE", "EN_COURS"],
      "priorities": ["HAUTE", "URGENTE"],
      "assignment_uuid": "assignment-uuid",
      "rotation_uuid": "rotation-uuid",
      "due_from": "2026-10-01",
      "due_to": "2026-10-31"
    },
    "available_filters": {
      "statuses": ["A_FAIRE", "EN_COURS", "TERMINEE", "A_REVOIR", "VALIDEE", "ANNULEE"],
      "priorities": ["BASSE", "NORMALE", "HAUTE", "URGENTE"],
      "per_page_max": 100
    }
  }
}
```

## 17. Modifier l’état d’une tâche

```http
POST /api/v1/student/tasks/{task_uuid}/start
POST /api/v1/student/tasks/{task_uuid}/comment
POST /api/v1/student/tasks/{task_uuid}/complete
```

Le corps peut contenir `{"comment":"..."}` ; il est obligatoire pour l’action `comment`. Chaque route retourne maintenant l’objet `task` complet et actualisé, avec les nouvelles actions autorisées.

Réponse `200` après `start` :

```json
{
  "success": true,
  "message": "Tache demarree.",
  "data": {
    "task": {
      "uuid": "task-uuid",
      "title": "Participer à la consultation pédiatrique",
      "description": "Réaliser l’anamnèse sous supervision.",
      "priority": "HAUTE",
      "status": "EN_COURS",
      "due_date": "2026-10-12",
      "student_comment": null,
      "supervisor_comment": null,
      "started_at": "2026-10-09 08:15:00",
      "completed_at": null,
      "validated_at": null,
      "created_at": "2026-10-08 09:00:00",
      "updated_at": "2026-10-09 08:15:00",
      "training_plan_item": {"id": 4, "title": "Consultation supervisée"},
      "assignment": {"uuid": "assignment-uuid", "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-31"}},
      "rotation": {"uuid": "rotation-uuid", "sequence": 1, "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-15"}},
      "hospital": {"id": 7, "code": "HOP-001", "name": "Hôpital Général", "phone": "+243810000000"},
      "service": {"id": 8, "code": "SRV-PED", "name": "Pédiatrie", "type": "SERVICE"},
      "unit": {"id": 9, "code": "UNI-CONS", "name": "Consultation", "type": "UNITE"},
      "actions": {
        "start": {"allowed": false, "method": "POST", "path": "/api/v1/student/tasks/task-uuid/start"},
        "comment": {"allowed": true, "method": "POST", "path": "/api/v1/student/tasks/task-uuid/comment"},
        "complete": {"allowed": true, "method": "POST", "path": "/api/v1/student/tasks/task-uuid/complete"}
      }
    }
  }
}
```

## 18. Consulter le journal

```http
GET /api/v1/student/logbook?status=BROUILLON,REJETEE&assignment_uuid={uuid}&rotation_uuid={uuid}&date_from=2026-10-01&date_to=2026-10-31&editable=true&page=1&per_page=20
```

Filtres disponibles : `status`/`statuses`, `assignment_uuid`, `rotation_uuid`, `date_from`, `date_to`, `editable`, `page` et `per_page`.

Les statuts publics sont normalisés en `BROUILLON`, `SOUMISE`, `VALIDEE` et `REJETEE`. La réponse comprend `items`, `stats`, `pagination`, `filters` et `available_filters`.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "uuid": "logbook-uuid",
        "date": "2026-10-09",
        "status": "BROUILLON",
        "summary": "Participation aux consultations pédiatriques.",
        "learning": "Évaluation des signes de gravité.",
        "difficulties": "Communication avec un enfant anxieux.",
        "observation": null,
        "submitted_at": null,
        "validated_at": null,
        "validator_comment": null,
        "editable": true,
        "can_submit": true,
        "activities": [
          {
            "uuid": "activity-uuid",
            "activity": "Anamnèse pédiatrique",
            "description": "Interrogatoire sous supervision.",
            "category": "PARTICIPATION",
            "involvement_level": "REALISE_SUPERVISE",
            "quantity": 3,
            "observation": null
          }
        ],
        "activities_count": 1,
        "rotation": {"uuid": "rotation-uuid", "sequence": 1, "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-15"}},
        "assignment": {"uuid": "assignment-uuid", "status": "ACTIVE", "start_date": "2026-10-01", "end_date": "2026-10-31"},
        "campaign": {"code": "CAM-000039", "title": "Stage clinique 2026"},
        "hospital": {"code": "HOP-001", "name": "Hôpital Général"},
        "unit": {"code": "UNI-CONS", "name": "Consultation"}
      }
    ],
    "stats": {"total": 6, "draft": 1, "submitted": 2, "validated": 2, "rejected": 1, "activities": 14},
    "pagination": {"page": 1, "per_page": 20, "total": 6, "pages": 1, "from": 1, "to": 6},
    "filters": {
      "assignment_uuid": "assignment-uuid",
      "rotation_uuid": "rotation-uuid",
      "statuses": ["BROUILLON", "REJETEE"],
      "date_from": "2026-10-01",
      "date_to": "2026-10-31",
      "editable": true
    },
    "available_filters": {
      "statuses": ["BROUILLON", "SOUMISE", "VALIDEE", "REJETEE"],
      "editable": [true, false],
      "per_page_max": 100
    }
  }
}
```

## 19. Enregistrer et soumettre le journal

```http
POST /api/v1/student/logbook
POST /api/v1/student/logbook/{logbook_uuid}/submit
```

Le corps d’enregistrement contient `assignment_uuid`, `date`, `summary` et `activities`. `rotation_uuid` peut être transmis pour sélectionner explicitement la rotation; sans lui, la rotation correspondant à la date est résolue automatiquement. Une rotation `ACTIVE` ou `TERMINEE` est acceptée. La date doit rester dans sa période, ne peut pas être future et ne peut pas dupliquer une entrée existante de la même rotation.

Règles supplémentaires de la version `1.9` :

- une seule entrée est permise par étudiant, rotation et date ;
- le résumé est obligatoire et la soumission exige au moins une activité ;
- une modification remet à zéro l’ancienne validation et le commentaire de l’encadreur ;
- `can_submit` tient compte du statut et de la période de rotation ;
- une erreur de validation du journal retourne `422`.

Après une création, une modification ou une soumission, la réponse contient désormais :

```json
{
  "success": true,
  "message": "Brouillon du journal enregistré.",
  "data": {
    "entry": {
      "uuid": "logbook-uuid",
      "date": "2026-10-09",
      "status": "BROUILLON",
      "summary": "Participation aux consultations pédiatriques.",
      "learning": "Évaluation des signes de gravité.",
      "difficulties": null,
      "observation": null,
      "submitted_at": null,
      "validated_at": null,
      "validator_comment": null,
      "activities": [
        {
          "uuid": "activity-uuid",
          "activity": "Anamnèse pédiatrique",
          "description": "Interrogatoire sous supervision.",
          "category": "PARTICIPATION",
          "involvement_level": "REALISE_SUPERVISE",
          "quantity": 3,
          "observation": null
        }
      ],
      "activities_count": 1,
      "assignment": {"uuid": "assignment-uuid", "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-31"}},
      "rotation": {"uuid": "rotation-uuid", "sequence": 1, "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-15"}},
      "campaign": {"code": "CAM-000039", "title": "Stage clinique 2026"},
      "hospital": {"code": "HOP-001", "name": "Hôpital Général"},
      "service": {"id": 8, "code": "SRV-PED", "name": "Pédiatrie", "type": "SERVICE"},
      "unit": null,
      "actions": {
        "edit": {"allowed": true, "method": "POST", "path": "/api/v1/student/logbook"},
        "submit": {"allowed": true, "method": "POST", "path": "/api/v1/student/logbook/logbook-uuid/submit"}
      }
    }
  }
}
```

Réponse `200` après `POST /student/logbook/{logbook_uuid}/submit` :

```json
{
  "success": true,
  "message": "Journal soumis à l’encadreur.",
  "data": {
    "entry": {
      "uuid": "logbook-uuid",
      "date": "2026-10-09",
      "status": "SOUMISE",
      "summary": "Participation aux consultations pédiatriques.",
      "learning": "Évaluation des signes de gravité.",
      "difficulties": null,
      "observation": null,
      "submitted_at": "2026-10-09 17:10:00",
      "validated_at": null,
      "validator_comment": null,
      "activities": [
        {
          "uuid": "activity-uuid",
          "activity": "Anamnèse pédiatrique",
          "description": "Interrogatoire sous supervision.",
          "category": "PARTICIPATION",
          "involvement_level": "REALISE_SUPERVISE",
          "quantity": 3,
          "observation": null
        }
      ],
      "activities_count": 1,
      "assignment": {"uuid": "assignment-uuid", "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-31"}},
      "rotation": {"uuid": "rotation-uuid", "sequence": 1, "status": "ACTIVE", "period": {"start_date": "2026-10-01", "end_date": "2026-10-15"}},
      "campaign": {"code": "CAM-000039", "title": "Stage clinique 2026"},
      "hospital": {"code": "HOP-001", "name": "Hôpital Général"},
      "service": {"id": 8, "code": "SRV-PED", "name": "Pédiatrie", "type": "SERVICE"},
      "unit": null,
      "actions": {
        "edit": {"allowed": false, "method": "POST", "path": "/api/v1/student/logbook"},
        "submit": {"allowed": false, "method": "POST", "path": "/api/v1/student/logbook/logbook-uuid/submit"}
      }
    }
  }
}
```

Une nouvelle tentative de soumission d’un journal déjà soumis ou validé retourne également l’entrée complète.

## 20. Consulter les feedbacks

```http
GET /api/v1/student/feedbacks?type=ENCOURAGEMENT,A_AMELIORER&assignment_uuid={uuid}&rotation_uuid={uuid}&seen=false&date_from=2026-10-01&date_to=2026-10-31&page=1&per_page=20
```

Filtres disponibles : `type`/`types`, `assignment_uuid`, `rotation_uuid`, `seen`, `date_from`, `date_to`, `page` et `per_page`. Les types possibles sont `OBSERVATION`, `ENCOURAGEMENT`, `A_AMELIORER` et `AVERTISSEMENT`.

La réponse contient `items`, `stats`, `pagination`, `filters` et `available_filters`. Chaque élément expose le contexte de l’affectation et de la rotation, l’hôpital, l’unité, l’auteur et l’action `read`. La consultation de cette collection ne marque plus aucun feedback comme lu.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "uuid": "feedback-uuid",
        "date": "2026-10-09",
        "type": "ENCOURAGEMENT",
        "title": "Bonne progression",
        "comment": "Bonne qualité d’écoute et d’anamnèse.",
        "published_at": "2026-10-09 16:30:00",
        "seen": false,
        "seen_at": null,
        "assignment": {"uuid": "assignment-uuid"},
        "rotation": {"uuid": "rotation-uuid", "sequence": 1},
        "hospital": {"name": "Hôpital Général"},
        "unit": {"name": "Consultation"},
        "author": {"name": "Docteur Encadreur"},
        "actions": {
          "read": {"allowed": true, "method": "POST", "endpoint": "/api/v1/student/feedbacks/feedback-uuid/read"}
        }
      }
    ],
    "stats": {"total": 3, "observations": 0, "encouragements": 2, "a_ameliorer": 1, "avertissements": 0, "unread": 2, "read": 1},
    "pagination": {"page": 1, "per_page": 20, "total": 3, "pages": 1, "from": 1, "to": 3},
    "filters": {
      "types": ["ENCOURAGEMENT", "A_AMELIORER"],
      "assignment_uuid": "assignment-uuid",
      "rotation_uuid": "rotation-uuid",
      "seen": false,
      "date_from": "2026-10-01",
      "date_to": "2026-10-31"
    },
    "available_filters": {
      "types": ["OBSERVATION", "ENCOURAGEMENT", "A_AMELIORER", "AVERTISSEMENT"],
      "seen": [true, false],
      "per_page_max": 100
    }
  }
}
```

## 21. Marquer un feedback comme lu

```http
POST /api/v1/student/feedbacks/{feedback_uuid}/read
Authorization: Bearer <access_token>
```

Le corps est vide. Seul le feedback ciblé est marqué comme lu et la réponse retourne l’objet `feedback` complet actualisé. L’action est idempotente.

Réponse `200` :

```json
{
  "success": true,
  "message": "Feedback marque comme lu.",
  "data": {
    "feedback": {
      "uuid": "feedback-uuid",
      "date": "2026-10-09",
      "type": "ENCOURAGEMENT",
      "title": "Bonne progression",
      "comment": "Bonne qualité d’écoute et d’anamnèse.",
      "published_at": "2026-10-09 16:30:00",
      "seen": true,
      "seen_at": "2026-10-09 17:05:00",
      "assignment": {"uuid": "assignment-uuid"},
      "rotation": {"uuid": "rotation-uuid", "sequence": 1},
      "hospital": {"name": "Hôpital Général"},
      "unit": {"name": "Consultation"},
      "author": {"name": "Docteur Encadreur"},
      "actions": {
        "read": {"allowed": false, "method": "POST", "endpoint": "/api/v1/student/feedbacks/feedback-uuid/read"}
      }
    }
  }
}
```

## 22. Consulter les évaluations

```http
GET /api/v1/student/evaluations?type=CONTINUE,FIN_ROTATION&status=VALIDEE,FINALISEE&assignment_uuid={uuid}&rotation_uuid={uuid}&date_from=2026-10-01&date_to=2026-10-31&page=1&per_page=20
```

Filtres disponibles : `type`/`types`, `status`/`statuses`, `assignment_uuid`, `rotation_uuid`, `date_from`, `date_to`, `page` et `per_page`. Seules les évaluations `VALIDEE` ou `FINALISEE` sont visibles par l’étudiant.

Chaque évaluation contient désormais `evaluator`, les dates du cycle de validation, `score_summary`, les scores détaillés, ainsi que le contexte de la rotation, de l’affectation, de la campagne, de l’hôpital et de l’unité. La réponse comprend aussi `stats`, `final_evaluation`, `pagination`, `filters` et `available_filters`.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [
      {
        "uuid": "evaluation-uuid",
        "type": "FIN_ROTATION",
        "status": "FINALISEE",
        "note": 16.5,
        "appreciation": "Très bonne progression clinique.",
        "strengths": "Communication et raisonnement clinique.",
        "improvement_areas": "Gagner en rapidité dans les urgences.",
        "evaluated_at": "2026-10-15 14:00:00",
        "submitted_at": "2026-10-15 14:10:00",
        "validated_at": "2026-10-15 15:00:00",
        "finalized_at": "2026-10-15 16:00:00",
        "score_summary": {"total": 33, "maximum": 40, "percentage": 82.5},
        "scores": [
          {
            "code": "COMP-CLIN",
            "nom": "Raisonnement clinique",
            "categorie": "CLINIQUE",
            "note": 17,
            "note_max": 20,
            "poids": 1,
            "commentaire": "Bonne analyse des situations."
          }
        ],
        "evaluator": {"user_id": 14, "name": "Docteur Encadreur", "function": "Maître de stage"},
        "rotation": {"uuid": "rotation-uuid", "sequence": 1},
        "assignment": {"uuid": "assignment-uuid", "status": "ACTIVE", "start_date": "2026-10-01", "end_date": "2026-10-31"},
        "campaign": {"code": "CAM-000039", "title": "Stage clinique 2026"},
        "hospital": {"code": "HOP-001", "name": "Hôpital Général"},
        "unit": {"id": 9, "code": "UNI-CONS", "name": "Consultation", "type": "UNITE"}
      }
    ],
    "stats": {"total": 2, "continuous": 1, "mid_rotation": 0, "final_rotation": 1, "validated": 1, "finalized": 1, "average": 15.75},
    "final_evaluation": {"uuid": "evaluation-uuid", "type": "FIN_ROTATION", "status": "FINALISEE", "note": 16.5},
    "pagination": {"page": 1, "per_page": 20, "total": 2, "pages": 1, "from": 1, "to": 2},
    "filters": {
      "types": ["CONTINUE", "FIN_ROTATION"],
      "statuses": ["VALIDEE", "FINALISEE"],
      "assignment_uuid": "assignment-uuid",
      "rotation_uuid": "rotation-uuid",
      "date_from": "2026-10-01",
      "date_to": "2026-10-31"
    },
    "available_filters": {
      "types": ["CONTINUE", "MI_ROTATION", "FIN_ROTATION"],
      "statuses": ["VALIDEE", "FINALISEE"],
      "per_page_max": 100
    }
  }
}
```

## 23. Consulter les clôtures hospitalières

```http
GET /api/v1/student/completions?status=PRET,VALIDE&assignment_uuid={uuid}&campaign_uuid={uuid}&hospital_id=24&date_from=2026-10-01&date_to=2026-10-31&page=1&per_page=20
```

Filtres disponibles : `status`/`statuses`, `assignment_uuid`, `campaign_uuid`, `hospital_id`, `date_from`, `date_to`, `page` et `per_page`.

Cette collection est strictement informative. `readiness.blockers` explique ce qui empêche encore la clôture, mais aucune action de validation n’est fournie à l’étudiant.

Les blocages couvrent désormais la fin de période, les rotations, les présences, les journaux, les évaluations finales, la délibération finale hospitalière et la validation universitaire. Chaque élément expose `final_deliberation` et `university_validation`. `score` reste `null` tant que la clôture n’est pas `VALIDE`.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [{
      "uuid": "completion-uuid",
      "status": "VALIDE",
      "score": 80,
      "attendance_rate": 92.5,
      "final_appreciation": "Stage satisfaisant.",
      "decision_comment": null,
      "prepared_at": "2026-10-30 15:00:00",
      "validated_at": "2026-10-31 10:00:00",
      "final_deliberation": {"status": "VALIDEE", "date": "2026-10-31", "validated_at": "2026-10-31 09:00:00"},
      "university_validation": {"status": "VALIDE", "validated_at": "2026-11-01 09:00:00"},
      "progress": {
        "rotations": {"total": 4, "completed": 4},
        "attendance": {"records": 24, "covered_rotations": 4},
        "logbook": {"total": 12, "validated": 12, "covered_rotations": 4},
        "final_evaluations": {"completed_rotations": 4}
      },
      "readiness": {"ready": true, "blockers": []},
      "validator": {"user_id": 31, "name": "Administrateur Hôpital"},
      "assignment": {"uuid": "assignment-uuid", "status": "TERMINEE", "start_date": "2026-10-01", "end_date": "2026-10-31"},
      "campaign": {"uuid": "campaign-uuid", "code": "CAM-000039", "title": "Stage clinique 2026"},
      "hospital": {"id": 24, "code": "HOP-001", "name": "Hôpital Général", "phone": "+243000000000", "email": "contact@hopital.cd"},
      "department": {"id": 7, "code": "DEP-PED", "name": "Pédiatrie", "type": "DEPARTEMENT"}
    }],
    "stats": {
      "total": 1,
      "by_status": {"EN_PREPARATION": 0, "PRET": 0, "VALIDE": 1, "REFUSE": 0, "ANNULE": 0},
      "ready": 0,
      "validated": 1,
      "average_score": 80,
      "average_attendance_rate": 92.5
    },
    "pagination": {"page": 1, "per_page": 20, "total": 1, "pages": 1, "from": 1, "to": 1},
    "filters": {"statuses": ["PRET", "VALIDE"], "assignment_uuid": null, "campaign_uuid": null, "hospital_id": null, "date_from": null, "date_to": null},
    "available_filters": {"statuses": ["EN_PREPARATION", "PRET", "VALIDE", "REFUSE", "ANNULE"], "per_page_max": 100}
  }
}
```

## 24. Consulter les résultats et leur validation universitaire

```http
GET /api/v1/student/results?decision=VALIDE&transmission_status=RECU,VALIDE,ARCHIVE&assignment_uuid={uuid}&campaign_uuid={uuid}&hospital_id=24&page=1&per_page=20
```

Filtres disponibles : `decision`/`decisions`, `transmission_status`/`transmission_statuses`, `assignment_uuid`, `campaign_uuid`, `hospital_id`, `page` et `per_page`.

`administrative_process.student_action_required` vaut toujours `false`. La note et la décision viennent de la délibération finale et ne sont exposées comme résultat officiel qu'après validation universitaire. Les statuts synthétiques possibles sont `AWAITING_FINAL_DELIBERATION`, `AWAITING_TRANSMISSION`, `AWAITING_UNIVERSITY_RECEIPT`, `AWAITING_UNIVERSITY_VALIDATION`, `AWAITING_HOSPITAL_CLOSURE` et `COMPLETED`.

Avant cette validation, `decision`, `score` et `appreciation` restent `null`. `result_source` vaut `FINAL_DELIBERATION` pour le nouveau circuit ou `LEGACY_CLOSURE` pour une ancienne clôture compatible. Les décisions filtrables sont `VALIDE`, `A_REPRENDRE`, `AJOURNE` et `NON_VALIDE`.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [{
      "decision": "VALIDE",
      "score": 80,
      "attendance_rate": 92.5,
      "appreciation": "Présence 92.5% · Journaux validés 12/12 · Note 80%",
      "result_source": "FINAL_DELIBERATION",
      "metrics": {"rotations": 4, "attendance_records": 24, "present": 21, "late": 1, "absent": 1, "justified": 1, "guard": 0, "logbook_entries": 12, "validated_logbook_entries": 12, "evaluations": 4},
      "administrative_process": {
        "status": "COMPLETED",
        "student_action_required": false,
        "final_deliberation": {"status": "VALIDEE", "date": "2026-10-31", "validated_at": "2026-10-31 09:00:00", "result_available": true},
        "hospital_closure": {"status": "VALIDE", "validated_at": "2026-10-31 10:00:00"},
        "university_transmission": {"uuid": "transmission-uuid", "status": "VALIDE", "transmitted_at": "2026-10-31 11:00:00", "received_at": "2026-10-31 12:00:00", "validated_at": "2026-11-01 09:00:00", "archived_at": null}
      },
      "assignment": {"uuid": "assignment-uuid", "status": "TERMINEE", "start_date": "2026-10-01", "end_date": "2026-10-31"},
      "campaign": {"uuid": "campaign-uuid", "code": "CAM-000039", "title": "Stage clinique 2026"},
      "hospital": {"id": 24, "code": "HOP-001", "name": "Hôpital Général"},
      "department": {"id": 7, "code": "DEP-PED", "name": "Pédiatrie", "type": "DEPARTEMENT"},
      "document": {"uuid": "certificate-uuid", "reference": "ATT-2026-12345", "type": "ATTESTATION_STAGE", "status": "GENERE", "available": true, "generated_at": "2026-11-01 09:00:01", "file_endpoint": "/api/v1/student/certificates/certificate-uuid/file", "download_endpoint": "/api/v1/student/certificates/certificate-uuid/file?download=1"}
    }],
    "stats": {
      "total": 1,
      "by_decision": {"VALIDE": 1, "A_REPRENDRE": 0, "AJOURNE": 0, "NON_VALIDE": 0},
      "by_transmission_status": {"NON_TRANSMIS": 0, "ENVOYE": 0, "RECU": 0, "VALIDE": 1, "ARCHIVE": 0},
      "average_score": 80,
      "average_attendance_rate": 92.5,
      "documents_available": 1
    },
    "pagination": {"page": 1, "per_page": 20, "total": 1, "pages": 1, "from": 1, "to": 1},
    "filters": {"decisions": ["VALIDE"], "transmission_statuses": ["RECU", "VALIDE", "ARCHIVE"], "assignment_uuid": null, "campaign_uuid": null, "hospital_id": null},
    "available_filters": {"decisions": ["VALIDE", "A_REPRENDRE", "AJOURNE", "NON_VALIDE"], "transmission_statuses": ["NON_TRANSMIS", "ENVOYE", "RECU", "VALIDE", "ARCHIVE"], "per_page_max": 100}
  }
}
```

## 25. Consulter les documents de fin de stage

```http
GET /api/v1/student/documents?type=ATTESTATION_STAGE&status=GENERE&available=true&assignment_uuid={uuid}&campaign_uuid={uuid}&page=1&per_page=20
```

Filtres disponibles : `type`/`types`, `status`/`statuses`, `available`, `assignment_uuid`, `campaign_uuid`, `page` et `per_page`.

Chaque document contient son stage, sa campagne, l’hôpital, l’université et son unité. `file_endpoint` et `download_endpoint` restent `null` tant que la clôture hospitalière et les résultats universitaires ne sont pas validés.

La réponse contient `items`, `stats`, `pagination`, `filters` et `available_filters`. Les compteurs distinguent `available`, `cancelled` et `pending_validation`.

Réponse `200` :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [{
      "uuid": "certificate-uuid",
      "reference": "ATT-2026-12345",
      "type_document": "ATTESTATION_STAGE",
      "status": "GENERE",
      "available": true,
      "university_result_status": "VALIDE",
      "availability_reason": null,
      "generated_at": "2026-11-01 09:00:01",
      "stage": {"assignment_uuid": "assignment-uuid", "start_date": "2026-10-01", "end_date": "2026-10-31", "note_finale": 80, "taux_presence": 92.5, "validated_at": "2026-10-31 10:00:00"},
      "campaign": {"code": "CAM-000039", "title": "Stage clinique 2026"},
      "hospital": {"code": "HOP-001", "name": "Hôpital Général"},
      "university": {"code": "UNI-001", "name": "Université Exemple"},
      "unit": {"code": "DEP-PED", "name": "Pédiatrie"},
      "file_endpoint": "/api/v1/student/certificates/certificate-uuid/file",
      "download_endpoint": "/api/v1/student/certificates/certificate-uuid/file?download=1",
      "requires_bearer": true,
      "verification_url": "/verification-attestation.php?token=certificate-uuid"
    }],
    "stats": {"total": 1, "attestations": 1, "certificates": 0, "available": 1, "cancelled": 0, "pending_validation": 0},
    "pagination": {"page": 1, "per_page": 20, "total": 1, "pages": 1, "from": 1, "to": 1},
    "filters": {"types": ["ATTESTATION_STAGE"], "statuses": ["GENERE"], "available": true, "assignment_uuid": null, "campaign_uuid": null},
    "available_filters": {"types": ["ATTESTATION_STAGE", "CERTIFICAT_STAGE"], "statuses": ["GENERE", "ANNULE"], "available": [true, false], "per_page_max": 100}
  }
}
```

## 26. Afficher ou télécharger une attestation

```http
GET /api/v1/student/certificates/{uuid}/file
GET /api/v1/student/certificates/{uuid}/file?download=1
```

La route retourne le fichier uniquement s’il appartient à l’étudiant authentifié, si sa clôture est `VALIDE`, si la transmission universitaire est `VALIDE` ou `ARCHIVE`, et si le document est `GENERE`. Sinon elle retourne `404` ou `409`.

Réponse `200` : flux `application/pdf` ou `text/html`. Avec `download=1`, l’en-tête `Content-Disposition` passe de `inline` à `attachment`.

Réponse d’erreur JSON :

```json
{
  "success": false,
  "message": "Ce document n'est pas disponible avant la validation universitaire des résultats.",
  "data": []
}
```

## Frontière administrative

Ces quatre routes sont exclusivement consultatives. Aucun endpoint étudiant ne permet de clôturer un stage, transmettre, recevoir, valider ou archiver des résultats, ni de générer ou annuler une attestation.

## 27. Soumettre un rapport de stage

```http
POST /api/v1/student/stage-reports
Authorization: Bearer <access_token>
Content-Type: multipart/form-data
```

Champs : `assignment_uuid` obligatoire, `report` (ou `file`) obligatoire, `title` et `description` facultatifs. Formats acceptés : PDF, DOC et DOCX, avec une taille maximale de 15 Mo.

Chaque nouvelle soumission crée une version immuable rattachée à l’affectation et à l’université propriétaire de la campagne. La réponse `201` contient le rapport complet, son numéro de version et ses liens de consultation.

## 28. Consulter l’historique des rapports

Réponse `201` attendue :

```json
{
  "success": true,
  "message": "Rapport de stage soumis a votre universite.",
  "data": {
    "report": {
      "uuid": "report-uuid",
      "version": 1,
      "title": "Rapport de stage",
      "status": "SOUMIS",
      "submitted_at": "2026-10-13 14:30:00",
      "assignment": { "uuid": "assignment-uuid", "status": "ACTIVE" },
      "campaign": { "uuid": "campaign-uuid", "code": "CAM-000039", "title": "Stage clinique 2026" },
      "hospital": { "id": 7, "code": "HOP-001", "name": "Hopital General" },
      "university": { "id": 3, "code": "UNI-001", "name": "Universite Exemple" },
      "file": { "original_name": "rapport.pdf", "mime_type": "application/pdf", "extension": "pdf", "size": 524288 },
      "detail_endpoint": "/api/v1/student/stage-reports/report-uuid",
      "file_endpoint": "/api/v1/student/stage-reports/report-uuid/file"
    }
  }
}
```

```http
GET /api/v1/student/stage-reports?status=SOUMIS,A_CORRIGER&assignment_uuid={uuid}&campaign_uuid={uuid}&date_from=2026-01-01&date_to=2026-12-31&page=1&per_page=20
```

Filtres : `status`/`statuses`, `assignment_uuid`, `campaign_uuid`, `date_from`, `date_to`, `page` et `per_page`. La collection contient `items`, `stats`, `pagination`, `filters` et `available_filters`.

## 29. Récupérer un rapport précis

Réponse attendue :

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [{ "uuid": "report-uuid", "version": 1, "title": "Rapport de stage", "status": "SOUMIS" }],
    "stats": { "total": 1, "by_status": { "SOUMIS": 1, "RECU": 0, "ACCEPTE": 0, "A_CORRIGER": 0, "REJETE": 0, "ARCHIVE": 0 }, "assignments": 1 },
    "pagination": { "page": 1, "per_page": 20, "total": 1, "pages": 1, "from": 1, "to": 1 },
    "filters": { "statuses": ["SOUMIS"], "assignment_uuid": null, "campaign_uuid": null, "date_from": null, "date_to": null },
    "available_filters": { "statuses": ["SOUMIS", "RECU", "ACCEPTE", "A_CORRIGER", "REJETE", "ARCHIVE"], "per_page_max": 100 }
  }
}
```

```http
GET /api/v1/student/stage-reports/{uuid}
```

La route retourne les métadonnées, le statut universitaire, le commentaire éventuel, la version, l’affectation, la campagne, l’hôpital, l’université et les liens du fichier. Un étudiant ne peut consulter que ses propres rapports.

## 30. Afficher ou télécharger le fichier d’un rapport

```http
GET /api/v1/student/stage-reports/{uuid}/file
GET /api/v1/student/stage-reports/{uuid}/file?download=1
```

La première forme utilise `inline`; `download=1` utilise `attachment`. Le jeton Bearer est obligatoire.

## 31. Consulter les situations et justifications de stage

```http
GET /api/v1/student/stage-justifications
```

La collection retourne les stages de l’étudiant, la situation calculée, la justification éventuelle, les statistiques, les valeurs autorisées et les actions disponibles. La lecture de la collection ne marque jamais automatiquement une décision comme lue.

```json
{
  "success": true,
  "message": "",
  "data": {
    "items": [{
      "assignment_uuid": "assignment-uuid",
      "situation": "INTERRUPTION",
      "allowed_type": "INTERRUPTION",
      "justification_uuid": "justification-uuid",
      "justification_status": "EN_ATTENTE",
      "reason": "MALADIE",
      "explanation": null,
      "decision_seen": false,
      "file_endpoint": "/api/v1/student/stage-justifications/justification-uuid/file",
      "read_endpoint": "/api/v1/student/stage-justifications/justification-uuid/read"
    }],
    "stats": {},
    "allowed_values": {
      "types": ["NON_DEMARRAGE", "INTERRUPTION"],
      "reasons": ["MALADIE", "ACCIDENT", "CONTRAINTE_FAMILIALE", "AUTRE"],
      "statuses": ["EN_ATTENTE", "ACCEPTEE", "REJETEE"]
    }
  }
}
```

## 32. Déposer ou renvoyer une justification

```http
POST /api/v1/student/stage-justifications
Content-Type: multipart/form-data
```

Champs :

- `assignment_uuid` : affectation concernée ;
- `type_situation` : `NON_DEMARRAGE` ou `INTERRUPTION`, conformément à `allowed_type` ;
- `motif` : valeur retournée dans `allowed_values.reasons` ;
- `explication` : obligatoire lorsque le motif vaut `AUTRE` ;
- `document` : PDF, JPG ou PNG facultatif, limité à 8 Mo.

La route accepte aussi un corps JSON lorsqu’aucun fichier n’est transmis. Un nouvel envoi met à jour la justification existante et la replace en attente. Une justification `ACCEPTEE` ne peut plus être modifiée.

```json
{
  "success": true,
  "message": "Justification enregistrée.",
  "data": {
    "justification": {
      "assignment_uuid": "assignment-uuid",
      "justification_uuid": "justification-uuid",
      "justification_status": "EN_ATTENTE",
      "reason": "MALADIE",
      "file_endpoint": "/api/v1/student/stage-justifications/justification-uuid/file"
    }
  }
}
```

## 33. Afficher ou télécharger un justificatif

```http
GET /api/v1/student/stage-justifications/{uuid}/file
GET /api/v1/student/stage-justifications/{uuid}/file?download=1
```

La réponse est un flux PDF, JPEG ou PNG. L’appartenance du document à l’étudiant authentifié est toujours vérifiée. `download=1` force le téléchargement.

## 34. Marquer la décision comme lue

```http
POST /api/v1/student/stage-justifications/{uuid}/read
```

Cette action est disponible uniquement après une décision `ACCEPTEE` ou `REJETEE`.

```json
{
  "success": true,
  "message": "Décision marquée comme lue.",
  "data": {"uuid": "justification-uuid"}
}
```

## 35. Désactiver et programmer la suppression du compte

```http
POST /api/v1/student/account/deactivate
Content-Type: application/json

{
  "password": "mot-de-passe-actuel",
  "confirmation": "DESACTIVER"
}
```

La route suspend immédiatement le compte et le profil mobile, révoque tous les jetons API et désactive les appareils de notification. `delete_after` vaut exactement 30 jours après la demande.

Une connexion avec le bon mot de passe avant `delete_after` annule automatiquement la demande et réactive le compte. Après l’échéance, le traitement anonymise le compte, détache le profil académique, révoque les rôles et conserve les historiques institutionnels.

Le traitement planifié doit exécuter :

Réponse attendue :

```json
{
  "success": true,
  "message": "Compte desactive. Sa suppression est programmee dans 30 jours.",
  "data": {
    "deletion_request": {
      "uuid": "deletion-request-uuid",
      "status": "PENDING",
      "requested_at": "2026-10-13 15:00:00",
      "delete_after": "2026-11-12 15:00:00",
      "grace_period_days": 30
    }
  }
}
```

```powershell
php bin/process-account-deletions.php
```

## Notifications liées au parcours

| Événement | Sujet attendu |
|---|---|
| `stage.reservation.approved` | Réservation de stage approuvée |
| `stage.reservation.payment_required` | Réservation approuvée — paiement requis |
| `stage.reservation.rejected` | Réservation de stage refusée |
| `stage.reservation.expired` | Réservation temporaire expirée |
| `stage.placement.confirmed` | Placement de stage confirmé |
| `stage.placement.cancelled` | Placement de stage annulé |
| `stage.assignment.created` | Nouvelle affectation de stage |
| `stage.assignment.updated` | Affectation de stage modifiée |
| `stage.rotation.created` | Nouvelle rotation de stage |
| `stage.rotation.updated` | Planification de rotation modifiée |

Les notifications d’affectation contiennent l’identifiant de l’affectation. Les notifications de rotation contiennent la période, le service, l’unité éventuelle et les informations de supervision disponibles.

## Codes HTTP communs

| Code | Signification |
|---|---|
| `200` | Lecture réussie, opération idempotente ou mise à jour réussie |
| `201` | Ressource créée |
| `400` | Requête mal formée |
| `401` | Jeton absent, invalide, expiré ou révoqué |
| `403` | Rôle `STAGIAIRE` absent/inactif ou accès interdit |
| `404` | Ressource introuvable pour l’étudiant authentifié |
| `409` | Conflit avec l’état courant du workflow |
| `422` | Paramètre ou donnée métier invalide |
| `500` | Erreur interne non prévue |

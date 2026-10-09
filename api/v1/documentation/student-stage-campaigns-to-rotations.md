# API étudiant — des campagnes aux rotations

Version 1.6.1 — mise à jour le 9 octobre 2026.

Cette documentation couvre le parcours mobile d’un étudiant depuis la consultation des campagnes ouvertes jusqu’à la consultation de ses affectations et rotations.

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

---

## Fonctionnement réel du paiement Mobile Money

1. Une notification `financial.obligation.created` fournit `action.type=payment`, l’UUID dans `action.target_id` et la route de détail dans `action.metadata.detail_endpoint`.
2. `GET /student/payments/{obligation_uuid}` charge la facture ciblée, son contexte et les actions autorisées. `GET /student/payments` reste disponible pour toute la collection.
3. `POST /student/payments/initiate` transmet à MaishaPay l’obligation, l’opérateur, le numéro et le montant restant. Une réponse `PENDING` signifie seulement que la demande USSD a été acceptée par la passerelle.
4. L’opérateur affiche la demande sur le téléphone. L’étudiant saisit son code PIN directement dans l’interface de l’opérateur; le PIN ne transite jamais par STAGIA.
5. MaishaPay appelle `/maishapay-callback.php` avec l’issue finale signée. Le serveur enregistre `SUCCEEDED`, `FAILED` ou `CANCELLED`, met à jour l’obligation et applique les conséquences métier.
6. L’application appelle périodiquement `POST /student/payments/sync` pour relire cet état jusqu’à obtenir un résultat terminal. Une notification est également créée après confirmation ou échec.

`initiate` ne doit donc jamais être interprété comme une réussite financière. Le webhook est la source de l’issue finale et `sync` restitue l’état enregistré par ce webhook.

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
        "rotation": {"uuid": "rotation-uuid", "sequence": 1},
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

Après une création, une modification ou une soumission, la réponse contient désormais :

```json
{
  "success": true,
  "message": "Journal enregistré en brouillon.",
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
  "message": "Journal soumis pour validation.",
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

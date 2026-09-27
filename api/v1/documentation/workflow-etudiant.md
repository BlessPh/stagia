# Workflow etudiant STAGIA

Date d'analyse : 2026-09-18

Ce document cartographie les fonctionnalites disponibles pour l'etudiant, les endpoints deja presents dans le projet, les doublons entre la couche Web et l'API v1, ainsi que les endpoints a ajouter pour couvrir completement l'experience etudiante.

## 1. Vue globale du parcours etudiant

Le workflow etudiant actuel couvre les etapes suivantes :

1. Activation / connexion
   - L'etudiant peut activer son compte via les pages publiques d'activation.
   - Il peut se connecter au Web via `login.php`.
   - Il peut se connecter a l'API mobile via `POST /api/v1/login`.
   - Il peut demander puis confirmer une reinitialisation de mot de passe depuis le mobile.

2. Consultation du profil et du parcours academique
   - L'etudiant consulte ses informations personnelles.
   - Il consulte ses rattachements universitaires.
   - Il consulte son parcours academique : annee, promotion, filiere, departement, faculte.

3. Recherche d'opportunites de stage
   - L'etudiant voit les campagnes ouvertes auxquelles il est eligible.
   - Pour le flux D4 medical, il voit uniquement les offres hospitalieres finalisees par son universite (`capacite_acceptee`).

4. Candidature / reservation
   - L'etudiant choisit une structure d'accueil.
   - Le systeme cree ou remet a jour une candidature.
   - Le systeme cree une candidature `SOUMISE` et une reservation `RESERVEE_TEMPORAIREMENT`.
   - Sa duree provient de la politique `reservation_hold_minutes` du type de stage, avec 30 minutes par defaut.
   - Si une reservation active existe deja pour la meme campagne, l'etudiant est bloque.

5. Decision universitaire, confirmation et paiement
   - L'universite accepte ou refuse d'abord la candidature.
   - Un refus annule la reservation et libere la place.
   - Apres acceptation, une reservation gratuite est confirmee ; une reservation payante passe en attente de paiement.
   - Le Web permet d'initier un paiement avec canal et numero de telephone.
   - L'API mobile sait preparer une facture et synchroniser l'etat du paiement, mais ne declenche pas encore de paiement operateur reel.

6. Placement, admission et affectation
   - L'universite confirme le placement uniquement apres acceptation et confirmation de la reservation.
   - Le placement confirme cree l'admission hospitaliere `ATTENDU`.
   - L'hopital ou la structure d'accueil admet l'etudiant.
   - Une affectation de stage est ensuite creee, avec unite/service et dates.
   - Les groupes et rotations publies cote hopital alimentent le suivi de l'etudiant.

7. Execution du stage
   - L'etudiant consulte ses stages planifies, actifs, termines ou valides.
   - Il consulte ses rotations et son encadreur.
   - Il pointe son arrivee/depart sur le Web.
   - Il consulte ses presences.
   - Il tient son journal de stage : brouillon, activites, soumission.
   - Il consulte les taches assignees par les encadreurs et peut les commenter ou les declarer terminees.
   - Il consulte les feedbacks publies par les encadreurs.

8. Evaluation, notes et cloture
   - L'etudiant consulte ses evaluations de stage.
   - Il consulte ses notes academiques.
   - Lorsque le stage est valide, les attestations/certificats deviennent disponibles.

9. Documents
   - L'etudiant consulte ses conventions et documents officiels.
   - Il gere ses documents personnels sur le Web ou avec l'API Bearer.
   - Les conventions, documents officiels et certificats sont telecharges par des endpoints Bearer controles.

## 2. Endpoints API v1 deja presents

Tous les endpoints `api/v1/student/*` utilisent en principe un token `Authorization: Bearer <token>` et le role `STAGIAIRE`.

### Authentification API

| Methode | Endpoint | Role | Fonction |
|---|---|---|---|
| POST | `/api/v1/login` | Public | Connexion par nom non ambigu, identifiant, email ou code STAGIA. Retourne un access token d'une heure et un refresh token de 30 jours. |
| POST | `/api/v1/forgot-password` | Public | Demande un lien de reinitialisation par identifiant, email ou code STAGIA. La reponse reste generique pour ne pas reveler l'existence d'un compte. |
| POST | `/api/v1/reset-password` | Public | Definit un nouveau mot de passe avec un token valable 60 minutes et utilisable une seule fois. |
| POST | `/api/v1/refresh-token` | Refresh token | Effectue une rotation atomique de l'access token et du refresh token. |
| POST | `/api/v1/logout` | Bearer token | Revoque uniquement la session mobile courante. |
| GET | `/api/v1/me` | Bearer token | Retourne l'utilisateur, ses roles actifs et, si applicable, son profil etudiant. |

Le corps de `login` contient `identifiant`, `password` et, facultativement, `device_name`. `forgot-password` accepte `identifiant` ou `email`. `reset-password` attend `token`, `password` et `password_confirmation`. Le corps de `refresh-token` contient `refresh_token`. Les routes protegees attendent `Authorization: Bearer <access_token>`. Le serveur ne conserve que les empreintes SHA-256 des tokens.

Les migrations `000009_api_refresh_tokens`, `000010_password_reset_tokens` et `000011_stage_payment_idempotency` doivent etre appliquees avant les tests d'integration. L'environnement doit definir les variables `SMTP_*` et `MOBILE_PASSWORD_RESET_URL`; cette derniere pointe vers le lien profond ou universel qui ouvre l'ecran mobile de reinitialisation. Une reinitialisation reussie revoque toutes les sessions API de l'utilisateur.

### Profil et parcours

| Methode | Endpoint | Fonction | Observations |
|---|---|---|---|
| GET | `/api/v1/student/dashboard.php` | Resume etudiant : stats candidatures, reservations, stages, documents, stage courant. | Fonctionnel et complet pour un tableau de bord mobile. |
| GET | `/api/v1/student/profile` | Profil etudiant + rattachement actif. | Authentification Bearer et role `STAGIAIRE` obligatoires. |
| GET | `/api/v1/student/enrollments` | Liste les rattachements universitaires de l'etudiant. | Authentification Bearer et controle du profil etudiant actif. |
| GET | `/api/v1/student/academic-path?enrollment_id=...` | Parcours academique pour un rattachement donne. | Verifie que le rattachement appartient a l'etudiant Bearer. |

### Stage, candidature et reservation

| Methode | Endpoint | Fonction | Parametres principaux |
|---|---|---|---|
| GET | `/api/v1/student/stage-options` | Offres D4 reservables et campagnes des autres types pilotees par l'universite. | Aucun parametre obligatoire. |
| POST | `/api/v1/student/reservations` | Cree ou retrouve une candidature D4 et sa reservation temporaire. | `campaign_id`, `academic_enrollment_id`, `participation_id`, `motivation` optionnel. |
| GET | `/api/v1/student/applications` | Liste les candidatures et leur avancement canonique. | Aucun parametre obligatoire. |
| GET | `/api/v1/student/reservations` | Liste les reservations, expiration et actions permises. | Aucun parametre obligatoire. |
| POST | `/api/v1/student/reservations/{uuid}/confirm` | Consultation idempotente de la confirmation; ne remplace jamais la decision universitaire. | Aucun corps obligatoire. |
| POST | `/api/v1/student/reservations/{uuid}/cancel` | Annule avant placement si aucun paiement valide ne necessite de remboursement. | Aucun corps obligatoire. |
| GET | `/api/v1/student/admissions` | Suit placement, admission, affectation et statut de stage. | `reservation_uuid` optionnel. |
| GET | `/api/v1/student/stages.php` | Liste les stages reels et statistiques. | Aucun parametre obligatoire. |

### Paiements

| Methode | Endpoint | Fonction | Observations |
|---|---|---|---|
| GET | `/api/v1/student/payments` | Liste factures, tentatives et canaux disponibles. | Distingue `NOT_REQUIRED`, `NOT_STARTED`, `PENDING`, `FAILED`, `PARTIAL`, `PAID`. |
| POST | `/api/v1/student/payments/initiate` | Initie un paiement avec les memes canaux que le Web. | `invoice_uuid` ou `reservation_uuid`, `channel`, `phone_number` selon le canal et cle obligatoire via `idempotency_key` ou l'en-tete `Idempotency-Key`. |
| POST | `/api/v1/student/payments/sync` | Synchronise facture et reservation sans creer de transaction. | `reservation_uuid`; operation idempotente. |
| POST | `/api/v1/student/payment-checkout.php` | Compatibilite historique : prepare l'etat et indique la nouvelle route d'initiation. | Ne cree plus de facture ni de paiement. |

### Execution de stage

| Methode | Endpoint | Fonction | Parametres principaux |
|---|---|---|---|
| GET | `/api/v1/student/attendance.php` | Liste les presences de l'etudiant. | `assignment_uuid` optionnel. |
| GET | `/api/v1/student/logbook.php` | Liste le journal de stage et les activites. | `assignment_uuid` optionnel. |
| POST | `/api/v1/student/logbook-save.php` | Cree/met a jour un brouillon de journal. | `assignment_uuid`, `date`, `summary`, `learning`, `difficulties`, `activities[]`, `uuid` optionnel. |
| POST | `/api/v1/student/logbook-submit.php` | Soumet un journal brouillon pour validation. | `uuid`. |
| GET | `/api/v1/student/evaluations.php` | Liste les evaluations validees. | `assignment_uuid` optionnel. |

### Documents officiels

| Methode | Endpoint | Fonction | Probleme repere |
|---|---|---|---|
| GET | `/api/v1/student/documents` | Liste certificats/attestations generes et URLs de verification. | Retourne un endpoint de fichier existant, protege par Bearer. |

## 3. Endpoints Web/AJAX deja presents pour l'espace etudiant

Ces endpoints utilisent la session PHP, le role `STAGIAIRE` et souvent un token CSRF. Ils sont appeles par les vues `views/espace-etudiant/*`.

### Pages et vues etudiant

| Vue | Fonction principale | Endpoints appeles |
|---|---|---|
| `views/espace-etudiant/dashboard.php` | Tableau de bord web. | Requetes SQL directes dans la vue. |
| `views/espace-etudiant/profil.php` | Profil web. | Requetes SQL directes dans la vue. |
| `views/espace-etudiant/parcours.php` | Parcours academique. | `actions/etudiants/student-parcours-list.php`. |
| `views/espace-etudiant/notes.php` | Notes academiques. | `student-parcours-list.php`, `student-note-list.php`. |
| `views/espace-etudiant/stages.php` | Choix de stage general. | `student-stage-options.php`, `actions/stages/student-stage-reserve.php`. |
| `views/espace-etudiant/d4-choisir-hopital.php` | Choix hopital D4. | `actions/espace-etudiant/d4-options-list.php`, `d4-reserve.php`. |
| `views/espace-etudiant/reservations.php` | Reservations. | `actions/stages/student-reservation-list.php`, `student-reservation-confirm.php`. |
| `views/espace-etudiant/candidatures.php` | Candidatures. | `actions/etudiants/student-application-list.php`. |
| `views/espace-etudiant/paiements.php` | Paiements web. | `student-payment-list.php`, `student-payment-initiate.php`. |
| `views/espace-etudiant/mes-stages.php` | Stages affectes et rotations. | `student-my-stages.php`. |
| `views/espace-etudiant/presences.php` | Presences et pointeuse. | `student-attendance-list.php`, `student-attendance-punch-context.php`, `student-attendance-punch.php`. |
| `views/espace-etudiant/journal-stage.php` | Journal de stage. | `student-logbook-list.php`, `student-logbook-save.php`, `student-logbook-submit.php`. |
| `views/espace-etudiant/evaluations.php` | Evaluations. | `student-evaluation-list.php`. |
| `views/espace-etudiant/documents.php` | Documents officiels et personnels. | `student-documents-list.php`, `student-convention-list.php`, `student-personal-document-*`. |
| `views/espace-etudiant/taches.php` | Taches et progression. | `actions/espace-etudiant/task-list.php`, `task-status.php`. |
| `views/espace-etudiant/feedbacks.php` | Feedbacks visibles. | `actions/espace-etudiant/feedback-list.php`. |

### Actions Web etudiant

| Endpoint | Methode probable | Fonction |
|---|---|---|
| `actions/etudiants/student-parcours-list.php` | GET | Liste le parcours academique courant/historique. |
| `actions/etudiants/student-note-list.php` | GET | Liste les notes par parcours. |
| `actions/etudiants/student-stage-options.php` | GET | Liste les campagnes/stages eligibles. |
| `actions/stages/student-stage-reserve.php` | POST | Cree candidature + reservation temporaire. |
| `actions/espace-etudiant/d4-options-list.php` | GET | Liste les options D4. |
| `actions/espace-etudiant/d4-reserve.php` | POST | Reserve une place D4. |
| `actions/stages/student-reservation-list.php` | GET | Liste les reservations web. |
| `actions/stages/student-reservation-confirm.php` | POST | Confirme reservation ou passe en attente de paiement. |
| `actions/etudiants/student-application-list.php` | GET | Liste les candidatures. |
| `actions/etudiants/student-payment-list.php` | GET | Liste les factures payables et paiements. |
| `actions/etudiants/student-payment-initiate.php` | POST | Initie un paiement Web avec canal et telephone. |
| `actions/etudiants/student-my-stages.php` | GET | Liste stages affectes, rotations, groupe et droits d'execution. |
| `actions/etudiants/student-attendance-list.php` | GET | Liste les presences par mois. |
| `actions/etudiants/student-attendance-punch-context.php` | GET | Retourne le contexte de pointage du jour. |
| `actions/etudiants/student-attendance-punch.php` | POST | Pointe arrivee ou depart. |
| `actions/etudiants/student-logbook-list.php` | GET | Liste les journaux de stage. |
| `actions/etudiants/student-logbook-save.php` | POST | Enregistre un brouillon de journal. |
| `actions/etudiants/student-logbook-submit.php` | POST | Soumet un journal. |
| `actions/etudiants/student-evaluation-list.php` | GET | Liste evaluations finalisees et scores. |
| `actions/etudiants/student-documents-list.php` | GET | Liste attestations/certificats. |
| `actions/etudiants/student-convention-list.php` | GET | Liste conventions. |
| `actions/etudiants/student-personal-document-list.php` | GET | Liste documents personnels. |
| `actions/etudiants/student-personal-document-upload.php` | POST | Upload document personnel. |
| `actions/etudiants/student-personal-document-file.php` | GET | Telecharge/affiche document personnel. |
| `actions/etudiants/student-personal-document-delete.php` | POST | Supprime document personnel. |
| `actions/espace-etudiant/task-list.php` | GET | Liste taches de l'etudiant. |
| `actions/espace-etudiant/task-status.php` | POST | Change statut/commentaire d'une tache. |
| `actions/espace-etudiant/feedback-list.php` | GET | Liste feedbacks publies et visibles. |

## 4. Fonctionnalites etudiantes couvertes

### Couvertes en API v1

- Connexion mobile avec token Bearer.
- Tableau de bord.
- Profil, rattachements et parcours academique partiel.
- Recherche d'options D4.
- Reservation de place.
- Suivi candidatures, reservations, admission et stages.
- Paiements en lecture, preparation checkout et synchronisation.
- Presences en lecture.
- Journal de stage : liste, brouillon, soumission.
- Evaluations en lecture.
- Documents officiels en lecture.

### Couvertes seulement en Web/session

- Paiement operateur/initiation avec canal et telephone.

### Couvertes partiellement ou incoherentes

- Authentification API unifiee : login, recuperation de mot de passe, refresh, logout et me utilisent le meme contrat JSON et le bootstrap commun.
- Dossier academique API : profil, rattachements, parcours, notes, conventions, documents officiels et personnels utilisent le compte Bearer.
- Deux modeles de relation coexistent :
  - API v1 recente : `stage_applications -> stage_reservations -> stage_admissions -> stage_assignments`.
  - Certains endpoints Web anciens : presence de `stage_placements`, par exemple `task-list.php`, `feedback-list.php`, `student-current-rotation.php`.
- Les evaluations `VALIDEE` et `FINALISEE` sont exposees avec le meme perimetre etudiant que le Web.

## 5. Endpoints a ajouter ou consolider

### Priorite 1 : rendre l'API mobile complete

| Methode | Endpoint recommande | Objectif |
|---|---|---|
| POST | `/api/v1/student/attendance/arrival` et `/departure` | Pointer l'arrivee ou le depart depuis mobile. |
| GET | `/api/v1/student/attendance/context` | Retourner rotation active, pointage du jour, droits et raison si non disponible. |
| GET | `/api/v1/student/tasks` | Lister les taches de stage assignees. |
| POST | `/api/v1/student/tasks/{uuid}/start`, `/comment`, `/complete` | Demarrer, commenter, declarer terminee une tache. |
| GET | `/api/v1/student/feedbacks` | Lister les feedbacks publies et visibles pour l'etudiant. |
| GET | `/api/v1/student/notes` | Implemente : notes de tous les rattachements possedes, filtrables par `academic_enrollment_id`. |
| GET | `/api/v1/student/conventions` | Implemente : conventions signees/archivees appartenant a l'etudiant. |
| GET | `/api/v1/student/certificates/{uuid}/file` | Implemente : fichier certificat controle par le compte Bearer. |

### Priorite 2 : documents personnels en API (implementee)

| Methode | Endpoint recommande | Objectif |
|---|---|---|
| GET | `/api/v1/student/personal-documents` | Liste des documents personnels du compte Bearer. |
| POST | `/api/v1/student/personal-documents` | Upload multipart d'un document personnel. |
| GET | `/api/v1/student/personal-documents/{uuid}/file` | Telechargement/preview avec controle de propriete. |
| DELETE ou POST | `/api/v1/student/personal-documents/{uuid}` | Suppression avec controle de propriete. |

### Priorite 3 : paiement complet

| Methode | Endpoint recommande | Objectif |
|---|---|---|
| POST | `/api/v1/student/payments/initiate` | Implemente : initiation idempotente avec les canaux Web existants. |
| GET | `/api/v1/student/payment-methods.php` | Retourner les canaux supportes : M-Pesa, Orange Money, Airtel Money, Afrimoney, banque, carte. |
| POST | `/api/v1/payments/callback.php` | Callback prestataire de paiement, separe du namespace etudiant si appele par operateur externe. |

### Priorite 4 : nettoyage/coherence technique

| Action | Pourquoi |
|---|---|
| Unifier `api/v1/bootstrap.php` et `api/v1/api-auth.php`. | Eviter deux formats de reponse et deux mecanismes de token. |
| Appliquer la migration des refresh tokens. | Le code est unifie, mais les colonnes `refresh_token_hash` et `refresh_expires_at` doivent exister en base. |
| Maintenir `student/profile.php` sur le socle Bearer. | Il utilise `api-auth.php` et `requireApiStudent()`. |
| Maintenir les statuts d'evaluation alignes. | L'API expose maintenant `VALIDEE` et `FINALISEE`, comme le Web. |
| Eviter les anciens liens `stage_placements` dans le suivi etudiant. | Le pointage et la rotation courante utilisent maintenant reservations/admissions/assignments. |

## 6. Workflow recommande cote application mobile

1. `POST /api/v1/login`
   - Stocker `access_token` et `refresh_token` dans le stockage securise du mobile.
   - Renouveler la session avec `POST /api/v1/refresh-token` lorsque l'access token expire.

2. `GET /api/v1/student/dashboard.php`
   - Afficher resume, stats, stage courant.

3. `GET /api/v1/student/stage-options`
   - Afficher les campagnes D4 reservables.
   - Afficher separement les autres types avec `reservation_mode=UNIVERSITY_MANAGED`.

4. `POST /api/v1/student/reservations`
   - Envoyer `campaign_id`, `academic_enrollment_id`, `participation_id`.
   - Recuperer `reservation_uuid`; la candidature est `SOUMISE` et la reservation temporaire.

5. Attendre la decision universitaire via les endpoints de consultation.
   - Un refus annule la reservation.
   - Une acceptation autorise la suite du workflow.

6. `GET /api/v1/student/payments`
   - Si `payment_required=false`, la reservation est deja confirmee par la decision universitaire.
   - Si `payment_required=true`, afficher la facture et les tentatives.

7. `POST /api/v1/student/payments/initiate`
   - Envoyer facture ou reservation, canal, telephone et une cle `idempotency_key` stable pendant les reprises reseau.
   - Puis appeler `POST /api/v1/student/payments/sync` jusqu'a confirmation.

8. Attendre le placement universitaire, puis appeler `GET /api/v1/student/admissions`.
   - L'admission n'apparait qu'apres un placement confirme.

9. `GET /api/v1/student/stages`
   - Afficher stages planifies, actifs, termines, valides.

10. Pendant un stage actif :
   - `GET /api/v1/student/attendance/context`.
   - `POST /api/v1/student/attendance/arrival` ou `/departure`.
   - `GET /api/v1/student/attendance`.
   - `GET|POST /api/v1/student/logbook`.
   - `POST /api/v1/student/logbook/{uuid}/submit`.
   - `GET /api/v1/student/tasks` et transitions `/start`, `/comment`, `/complete`.
   - `GET /api/v1/student/feedbacks`.

11. Fin de stage :
    - `GET /api/v1/student/evaluations`.
    - `GET /api/v1/student/documents`.
    - `GET /api/v1/student/certificates/{uuid}/file` avec le meme Bearer token.
    - `GET /api/v1/student/conventions` et `/conventions/{uuid}/file`.
    - `GET|POST /api/v1/student/personal-documents`, puis endpoints fichier/suppression par UUID.

## 7. Points d'attention securite et metier

- Tous les endpoints API etudiant doivent passer par `requireApiStudent($pdo)` pour garantir :
  - token valide ;
  - role `STAGIAIRE` ;
  - profil etudiant actif.
- Les endpoints POST doivent accepter JSON pour mobile et eventuellement `FormData` pour compatibilite.
- Les operations sensibles doivent etre idempotentes :
  - reservation repetee apres coupure reseau ;
  - soumission de journal deja soumis ;
  - synchronisation paiement deja confirme.
- Les endpoints de fichiers doivent verifier que le document appartient bien a l'etudiant connecte, sauf endpoint public de verification d'attestation.
- Seules les reservations `RESERVEE_TEMPORAIREMENT` expirent; l'attente de paiement commence apres la decision universitaire et ne reutilise pas ce delai.

## 8. Resume des lacunes principales

Les fonctionnalites etudiantes existent largement dans le projet, mais elles sont reparties entre deux surfaces :

- API v1 token, plus adaptee au mobile.
- Actions Web AJAX, plus completes pour certaines fonctions.

Pour une experience etudiant complete via API v1, il manque surtout :

1. Pointage arrivee/depart.
2. Paiement initie cote mobile.
3. Notes academiques.
4. Documents personnels.
5. Conventions.
6. Taches.
7. Feedbacks.
8. Telechargement effectif des certificats/attestations.
9. Application des migrations et tests d'integration de l'authentification API.

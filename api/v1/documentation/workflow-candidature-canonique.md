# Workflow canonique de candidature étudiant

## Séquence métier

```text
Offre hospitalière retenue (capacite_acceptee ou capacite_allouee)
→ Candidature SOUMISE
→ Réservation RESERVEE_TEMPORAIREMENT
→ Décision universitaire
   ├─ refus : candidature REFUSEE + réservation ANNULEE
   └─ acceptation : candidature ACCEPTEE
      ├─ stage payant : réservation EN_ATTENTE_PAIEMENT
      │  → paiement validé : réservation CONFIRMEE
      └─ stage gratuit : réservation CONFIRMEE
→ Placement universitaire CONFIRME
→ Admission hospitalière ATTENDU
→ Admission ADMIS
→ Affectation de service ACTIVE et admission EN_COURS
```

## Invariants

1. Une offre n'est visible et réservable que si la participation est `ACCEPTEE` et si sa capacité retenue est positive.
2. La capacité retenue provient de `capacite_acceptee` dans le flux finalisé historique ou de `capacite_allouee` dans le flux générique d'accueil. Une simple `capacite_proposee` ne suffit pas.
3. La durée d'une réservation temporaire provient de la politique `reservation_hold_minutes` du type de stage, avec 30 minutes par défaut.
4. Le mobile et le Web appellent `submitStudentStageApplication()` : ils créent ou réactivent la même candidature `SOUMISE` et la même réservation `RESERVEE_TEMPORAIREMENT`.
5. Seule la décision universitaire passe une candidature à `ACCEPTEE` ou `REFUSEE`.
6. Aucun paiement ne peut être préparé ou confirmé avant une décision `ACCEPTEE`.
7. Un placement exige une candidature `ACCEPTEE` et une réservation `CONFIRMEE`.
8. Seule la confirmation du placement crée ou réactive une admission `ATTENDU`, obligatoirement reliée par `placement_id`.
9. L'hôpital ne peut admettre ou affecter que les dossiers issus d'un placement `CONFIRME`.
10. Une décision de refus annule la réservation et toute facture non réglée.

## Responsabilités

| Étape | Acteur | Écriture principale |
|---|---|---|
| Choix | Étudiant Web ou mobile | `stage_applications`, `stage_reservations` |
| Décision | Université | statut candidature, réservation et facture éventuelle |
| Paiement | Étudiant/opérateur/encaissement | paiement, facture, réservation |
| Placement | Université | `stage_placements`, puis admission `ATTENDU` |
| Admission | Hôpital | admission `ADMIS` |
| Affectation | Hôpital/coordination | `stage_assignments`, admission `EN_COURS` |

## Surfaces alignées

- Web D4 : `actions/espace-etudiant/d4-reserve.php`
- Web historique : `actions/stages/student-stage-reserve.php`
- Mobile : `api/v1/student/reserve.php`
- Service partagé : `includes/stage-application-workflow.php`
- Décision : `actions/stages/university-application-decision.php`
- Placement : `actions/stages/d4-placement-confirm.php` et version groupée
- Paiement mobile : `payment-checkout.php`, `payment-sync.php`
- Admission : endpoints `admission-*` et `host-student-admit.php`

Les contrôles d'intégration SQL sont regroupés dans
`database/verifications/2026_09_25_workflow_candidature.sql`.


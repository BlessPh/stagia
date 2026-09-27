-- Chaque requête doit retourner zéro ligne après application du workflow canonique.

-- Admissions sans placement universitaire confirmé.
SELECT ad.id admission_id,ad.reservation_id,ad.placement_id,ad.statut admission_status
FROM stage_admissions ad
LEFT JOIN stage_placements pl ON pl.id=ad.placement_id
WHERE ad.statut<>'ANNULE'
  AND (pl.id IS NULL OR pl.reservation_id<>ad.reservation_id OR pl.statut<>'CONFIRME');

-- Placements confirmés sans décision favorable ou sans réservation confirmée.
SELECT pl.id placement_id,a.id application_id,a.statut application_status,
       r.id reservation_id,r.statut reservation_status
FROM stage_placements pl
JOIN stage_reservations r ON r.id=pl.reservation_id
JOIN stage_applications a ON a.id=r.application_id
WHERE pl.statut='CONFIRME'
  AND (a.statut<>'ACCEPTEE' OR r.statut<>'CONFIRMEE');

-- Réservations dépassant la capacité retenue par l'université.
SELECT p.id participation_id,p.capacite_acceptee,COUNT(r.id) active_reservations
FROM stage_campaign_participations p
JOIN stage_reservations r ON r.participation_id=p.id
 AND (r.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
      OR (r.statut='RESERVEE_TEMPORAIREMENT' AND r.expires_at>NOW()))
GROUP BY p.id,p.capacite_acceptee
HAVING p.capacite_acceptee IS NULL OR active_reservations>p.capacite_acceptee;

-- Factures actives créées avant décision universitaire favorable.
SELECT i.id invoice_id,i.application_id,a.statut application_status,i.statut invoice_status
FROM stage_invoices i
JOIN stage_applications a ON a.id=i.application_id
WHERE i.statut IN('EMISE','PARTIELLEMENT_PAYEE','PAYEE')
  AND a.statut<>'ACCEPTEE';

-- Affectations actives hors de la chaîne candidature/réservation/placement/admission.
SELECT ass.id assignment_id,ad.id admission_id,a.statut application_status,
       r.statut reservation_status,pl.statut placement_status,ad.statut admission_status
FROM stage_assignments ass
JOIN stage_admissions ad ON ad.id=ass.admission_id
JOIN stage_reservations r ON r.id=ad.reservation_id
JOIN stage_applications a ON a.id=r.application_id
LEFT JOIN stage_placements pl ON pl.id=ad.placement_id
WHERE ass.statut IN('PLANIFIEE','ACTIVE')
  AND (
      a.statut<>'ACCEPTEE'
      OR r.statut<>'CONFIRMEE'
      OR pl.id IS NULL
      OR pl.reservation_id<>r.id
      OR pl.statut<>'CONFIRME'
      OR ad.statut NOT IN('ADMIS','EN_COURS')
  );

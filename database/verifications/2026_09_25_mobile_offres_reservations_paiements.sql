-- Chaque requête doit retourner zéro ligne après alignement Web/mobile.

-- Facture active avant décision universitaire favorable.
SELECT i.id invoice_id,a.id application_id,a.statut application_status
FROM stage_invoices i
JOIN stage_applications a ON a.id=i.application_id
WHERE i.statut IN('EMISE','PARTIELLEMENT_PAYEE','PAYEE')
  AND a.statut<>'ACCEPTEE';

-- Paiement initié alors que la réservation n'est pas à une étape payable.
SELECT pay.id payment_id,r.id reservation_id,r.statut reservation_status,a.statut application_status
FROM stage_payments pay
JOIN stage_invoices i ON i.id=pay.invoice_id
JOIN stage_reservations r ON r.id=i.reservation_id
JOIN stage_applications a ON a.id=r.application_id
WHERE pay.statut IN('INITIE','EN_ATTENTE','VALIDE')
  AND (a.statut<>'ACCEPTEE' OR r.statut NOT IN('EN_ATTENTE_PAIEMENT','CONFIRMEE'));

-- Facture payée dont la réservation n'est pas confirmée.
SELECT i.id invoice_id,r.id reservation_id,r.statut reservation_status
FROM stage_invoices i
JOIN stage_reservations r ON r.id=i.reservation_id
WHERE i.statut='PAYEE' AND r.statut<>'CONFIRMEE';

-- Réservation payante confirmée sans facture soldée.
SELECT r.id reservation_id,i.id invoice_id,i.statut invoice_status
FROM stage_reservations r
JOIN stage_campaign_participations p ON p.id=r.participation_id AND p.frais_requis=1
LEFT JOIN stage_invoices i ON i.reservation_id=r.id
WHERE r.statut='CONFIRMEE'
  AND (i.id IS NULL OR i.statut<>'PAYEE');

-- Plusieurs tentatives simultanément en attente sur une même facture.
SELECT invoice_id,COUNT(*) pending_payments
FROM stage_payments
WHERE statut IN('INITIE','EN_ATTENTE')
GROUP BY invoice_id
HAVING COUNT(*)>1;

-- Dépassement de la capacité D4 acceptée.
SELECT p.id participation_id,p.capacite_acceptee,COUNT(r.id) occupied_places
FROM stage_campaign_participations p
JOIN stage_reservations r ON r.participation_id=p.id
 AND (r.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
      OR (r.statut='RESERVEE_TEMPORAIREMENT' AND (r.expires_at IS NULL OR r.expires_at>NOW())))
GROUP BY p.id,p.capacite_acceptee
HAVING p.capacite_acceptee IS NULL OR COUNT(r.id)>p.capacite_acceptee;

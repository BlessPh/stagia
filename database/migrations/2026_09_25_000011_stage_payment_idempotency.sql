ALTER TABLE stage_payments
    ADD COLUMN idempotency_key VARCHAR(100) NULL AFTER invoice_id,
    ADD UNIQUE KEY uq_stage_payment_idempotency (invoice_id,idempotency_key);

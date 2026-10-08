-- Ces tables participent aux memes transactions que les obligations.
ALTER TABLE stage_invoices ENGINE=InnoDB;
ALTER TABLE stage_payments ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS financial_obligations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    reference VARCHAR(50) NOT NULL,
    user_id INT NOT NULL,
    organization_id INT NULL,
    obligation_type VARCHAR(60) NOT NULL,
    subject_type VARCHAR(60) NOT NULL,
    subject_key VARCHAR(120) NOT NULL,
    label VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    status ENUM('PENDING','PARTIALLY_PAID','PAID','CANCELLED','EXPIRED') NOT NULL DEFAULT 'PENDING',
    due_at DATETIME NULL,
    paid_at DATETIME NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_obligation_uuid (uuid),
    UNIQUE KEY uq_financial_obligation_reference (reference),
    UNIQUE KEY uq_financial_obligation_subject (obligation_type,subject_type,subject_key),
    KEY idx_financial_obligation_user_status (user_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    obligation_id BIGINT UNSIGNED NOT NULL,
    merchant_reference VARCHAR(60) NOT NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'MAISHAPAY',
    provider_transaction_id VARCHAR(120) NULL,
    channel VARCHAR(30) NOT NULL,
    wallet_phone VARCHAR(30) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    status ENUM('INITIATED','PENDING','SUCCEEDED','FAILED','CANCELLED','REFUNDED') NOT NULL DEFAULT 'INITIATED',
    idempotency_key VARCHAR(100) NULL,
    request_payload JSON NULL,
    provider_response JSON NULL,
    callback_payload JSON NULL,
    initiated_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    failed_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_payment_uuid (uuid),
    UNIQUE KEY uq_financial_payment_reference (merchant_reference),
    UNIQUE KEY uq_financial_payment_idempotency (obligation_id,idempotency_key),
    KEY idx_financial_payment_provider_transaction (provider,provider_transaction_id),
    KEY idx_financial_payment_obligation_status (obligation_id,status),
    CONSTRAINT fk_financial_payment_obligation FOREIGN KEY (obligation_id)
        REFERENCES financial_obligations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_entitlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    entitlement_code VARCHAR(60) NOT NULL,
    source_obligation_id BIGINT UNSIGNED NOT NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_entitlement_user (user_id,entitlement_code),
    KEY idx_financial_entitlement_validity (entitlement_code,valid_until),
    CONSTRAINT fk_financial_entitlement_obligation FOREIGN KEY (source_obligation_id)
        REFERENCES financial_obligations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Les secrets sont desormais lus exclusivement depuis l'environnement.
DELETE FROM system_settings WHERE setting_key LIKE 'maishapay.%';

-- Certaines installations historiques ont une cle primaire sans AUTO_INCREMENT.
ALTER TABLE system_settings MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT;

INSERT INTO system_settings(setting_key,setting_value,setting_type,category,label,description,systeme)
VALUES
('student_activation.payment_enabled','0','BOOLEAN','PAIEMENTS','Paiement étudiant obligatoire','Exiger le paiement annuel pour activer l’accès étudiant.',1),
('student_activation.amount','0','STRING','PAIEMENTS','Frais annuels étudiant','Montant annuel demandé à l’étudiant.',1),
('student_activation.currency','USD','STRING','PAIEMENTS','Devise des frais étudiants','Devise du paiement annuel étudiant.',1)
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

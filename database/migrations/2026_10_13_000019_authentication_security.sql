CREATE TABLE IF NOT EXISTS login_throttle_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject_key CHAR(64) NOT NULL,
    last_ip_hash CHAR(64) NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_login_throttle_subject (subject_key),
    KEY idx_login_throttle_unlock (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

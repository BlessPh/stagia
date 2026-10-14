CREATE TABLE IF NOT EXISTS stage_mini_juries (
    id BIGINT NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    assignment_id BIGINT NOT NULL,
    department_unit_id BIGINT NOT NULL,
    host_etablissement_id INT NOT NULL,
    statut ENUM('BROUILLON','VALIDEE','ANNULEE') NOT NULL DEFAULT 'BROUILLON',
    note_finale DECIMAL(5,2) NULL,
    decision ENUM('VALIDE','A_REPRENDRE','AJOURNE') NULL,
    appreciation TEXT NULL,
    date_jury DATE NULL,
    created_by INT NULL,
    validated_by INT NULL,
    validated_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mini_jury_uuid (uuid),
    UNIQUE KEY uk_mini_jury_assignment_department (assignment_id,department_unit_id),
    KEY idx_mini_jury_assignment (assignment_id),
    KEY idx_mini_jury_department (department_unit_id),
    KEY idx_mini_jury_host (host_etablissement_id),
    KEY idx_mini_jury_status (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stage_mini_jury_rotations (
    id BIGINT NOT NULL AUTO_INCREMENT,
    mini_jury_id BIGINT NOT NULL,
    rotation_id BIGINT NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mini_jury_rotation (mini_jury_id,rotation_id),
    UNIQUE KEY uk_rotation_mini_jury (rotation_id),
    KEY idx_mjr_jury (mini_jury_id),
    KEY idx_mjr_rotation (rotation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stage_final_deliberations (
    id BIGINT NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    assignment_id BIGINT NOT NULL,
    host_etablissement_id INT NOT NULL,
    statut ENUM('BROUILLON','VALIDEE','ANNULEE') NOT NULL DEFAULT 'BROUILLON',
    mini_juries_total INT NOT NULL DEFAULT 0,
    mini_juries_valides INT NOT NULL DEFAULT 0,
    note_finale DECIMAL(5,2) NULL,
    decision ENUM('VALIDE','A_REPRENDRE','AJOURNE') NULL,
    appreciation TEXT NULL,
    date_deliberation DATE NULL,
    created_by INT NULL,
    validated_by INT NULL,
    validated_at DATETIME NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_final_deliberation_uuid (uuid),
    UNIQUE KEY uk_final_deliberation_assignment (assignment_id),
    KEY idx_final_deliberation_host (host_etablissement_id),
    KEY idx_final_deliberation_status (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stage_result_items
    MODIFY decision ENUM('VALIDE','A_REPRENDRE','AJOURNE','NON_VALIDE') NOT NULL DEFAULT 'A_REPRENDRE';

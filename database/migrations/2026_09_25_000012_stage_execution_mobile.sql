-- Schema deja utilise par les ecrans Web de suivi des taches.
CREATE TABLE IF NOT EXISTS stage_tasks (
    id BIGINT NOT NULL AUTO_INCREMENT,
    uuid CHAR(36) NOT NULL,
    host_etablissement_id BIGINT NOT NULL,
    assignment_id BIGINT NOT NULL,
    rotation_id BIGINT NULL,
    training_plan_item_id BIGINT NULL,
    student_id BIGINT NOT NULL,
    created_by BIGINT NOT NULL,
    updated_by BIGINT NULL,
    titre VARCHAR(200) NOT NULL,
    description TEXT NULL,
    priorite ENUM('BASSE','NORMALE','HAUTE','URGENTE') NOT NULL DEFAULT 'NORMALE',
    statut ENUM('A_FAIRE','EN_COURS','TERMINEE','A_REVOIR','VALIDEE','ANNULEE') NOT NULL DEFAULT 'A_FAIRE',
    date_echeance DATE NULL,
    commentaire_stagiaire TEXT NULL,
    commentaire_encadreur TEXT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    validated_at DATETIME NULL,
    validated_by BIGINT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_stage_tasks_uuid (uuid),
    KEY idx_stage_tasks_host (host_etablissement_id),
    KEY idx_stage_tasks_assignment (assignment_id),
    KEY idx_stage_tasks_rotation (rotation_id),
    KEY idx_stage_tasks_student (student_id),
    KEY idx_stage_tasks_status (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @feedback_archived_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stage_supervision_feedbacks' AND COLUMN_NAME='archived_at');
SET @feedback_archived_ddl=IF(@feedback_archived_exists=0,'ALTER TABLE stage_supervision_feedbacks ADD COLUMN archived_at DATETIME NULL AFTER published_at','SELECT 1');
PREPARE feedback_archived_stmt FROM @feedback_archived_ddl;
EXECUTE feedback_archived_stmt;
DEALLOCATE PREPARE feedback_archived_stmt;

SET @feedback_seen_exists=(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stage_supervision_feedbacks' AND COLUMN_NAME='student_seen_at');
SET @feedback_seen_ddl=IF(@feedback_seen_exists=0,'ALTER TABLE stage_supervision_feedbacks ADD COLUMN student_seen_at DATETIME NULL AFTER archived_at','SELECT 1');
PREPARE feedback_seen_stmt FROM @feedback_seen_ddl;
EXECUTE feedback_seen_stmt;
DEALLOCATE PREPARE feedback_seen_stmt;

SET @feedback_seen_index_exists=(SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stage_supervision_feedbacks' AND INDEX_NAME='idx_fb_student_seen');
SET @feedback_seen_index_ddl=IF(@feedback_seen_index_exists=0,'ALTER TABLE stage_supervision_feedbacks ADD KEY idx_fb_student_seen (student_seen_at)','SELECT 1');
PREPARE feedback_seen_index_stmt FROM @feedback_seen_index_ddl;
EXECUTE feedback_seen_index_stmt;
DEALLOCATE PREPARE feedback_seen_index_stmt;

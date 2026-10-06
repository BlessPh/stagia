-- Support des grilles d'evaluation propres a une promotion.
-- Migration idempotente : elle peut etre rejouee sans modifier les donnees existantes.

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stage_competencies'
      AND COLUMN_NAME = 'etablissement_id'
);
SET @ddl := IF(
    @column_exists = 0,
    'ALTER TABLE `stage_competencies` ADD COLUMN `etablissement_id` INT NULL AFTER `id`',
    'SELECT 1'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stage_competencies'
      AND INDEX_NAME = 'idx_stage_competency_etablissement'
);
SET @ddl := IF(
    @index_exists = 0,
    'ALTER TABLE `stage_competencies` ADD INDEX `idx_stage_competency_etablissement` (`etablissement_id`)',
    'SELECT 1'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stage_referential_competencies'
      AND COLUMN_NAME = 'note_max'
);
SET @ddl := IF(
    @column_exists = 0,
    'ALTER TABLE `stage_referential_competencies` ADD COLUMN `note_max` DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER `poids`',
    'SELECT 1'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stage_referential_competencies'
      AND COLUMN_NAME = 'section'
);
SET @ddl := IF(
    @column_exists = 0,
    'ALTER TABLE `stage_referential_competencies` ADD COLUMN `section` VARCHAR(180) NULL AFTER `note_max`',
    'SELECT 1'
);
PREPARE migration_statement FROM @ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

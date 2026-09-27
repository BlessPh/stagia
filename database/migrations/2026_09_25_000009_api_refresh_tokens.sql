-- Une ligne de api_tokens représente une session mobile.
-- La migration est réexécutable afin de faciliter les installations existantes.

SET @api_refresh_hash_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='api_tokens'
      AND COLUMN_NAME='refresh_token_hash'
);
SET @api_refresh_hash_ddl = IF(
    @api_refresh_hash_exists=0,
    'ALTER TABLE api_tokens ADD COLUMN refresh_token_hash CHAR(64) NULL AFTER token_hash',
    'SELECT 1'
);
PREPARE api_refresh_hash_stmt FROM @api_refresh_hash_ddl;
EXECUTE api_refresh_hash_stmt;
DEALLOCATE PREPARE api_refresh_hash_stmt;

SET @api_refresh_expiry_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='api_tokens'
      AND COLUMN_NAME='refresh_expires_at'
);
SET @api_refresh_expiry_ddl = IF(
    @api_refresh_expiry_exists=0,
    'ALTER TABLE api_tokens ADD COLUMN refresh_expires_at DATETIME NULL AFTER expires_at',
    'SELECT 1'
);
PREPARE api_refresh_expiry_stmt FROM @api_refresh_expiry_ddl;
EXECUTE api_refresh_expiry_stmt;
DEALLOCATE PREPARE api_refresh_expiry_stmt;

SET @api_refresh_index_exists = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE()
      AND TABLE_NAME='api_tokens'
      AND INDEX_NAME='uq_api_tokens_refresh_token_hash'
);
SET @api_refresh_index_ddl = IF(
    @api_refresh_index_exists=0,
    'ALTER TABLE api_tokens ADD UNIQUE KEY uq_api_tokens_refresh_token_hash (refresh_token_hash)',
    'SELECT 1'
);
PREPARE api_refresh_index_stmt FROM @api_refresh_index_ddl;
EXECUTE api_refresh_index_stmt;
DEALLOCATE PREPARE api_refresh_index_stmt;

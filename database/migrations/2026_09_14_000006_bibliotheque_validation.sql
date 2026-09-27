-- Après 000005. Refus par défaut, y compris pour les anciens documents.
SET @bib_col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bibliotheque_documents'
 AND COLUMN_NAME='telechargement_autorise');
SET @bib_ddl = IF(@bib_col_exists=0,
 'ALTER TABLE bibliotheque_documents ADD COLUMN telechargement_autorise TINYINT(1) NOT NULL DEFAULT 0',
 'SELECT 1');
PREPARE bib_stmt FROM @bib_ddl;
EXECUTE bib_stmt;
DEALLOCATE PREPARE bib_stmt;

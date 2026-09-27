<?php
require_once __DIR__.'/../config/database.php';
header('Content-Type:text/plain; charset=utf-8');
try{
$pdo->exec("CREATE TABLE IF NOT EXISTS stage_document_archives(
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 uuid CHAR(36) NOT NULL UNIQUE,
 document_type VARCHAR(60) NOT NULL,
 reference VARCHAR(80) NOT NULL,
 title VARCHAR(180) NOT NULL,
 student_id BIGINT UNSIGNED NULL,
 assignment_id BIGINT UNSIGNED NULL,
 campaign_id BIGINT UNSIGNED NULL,
 host_etablissement_id BIGINT UNSIGNED NULL,
 university_etablissement_id BIGINT UNSIGNED NULL,
 generated_by BIGINT UNSIGNED NULL,
 generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 source_url VARCHAR(500) NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'GENERE',
 INDEX idx_doc_archive_student(student_id),
 INDEX idx_doc_archive_campaign(campaign_id),
 INDEX idx_doc_archive_host(host_etablissement_id),
 INDEX idx_doc_archive_univ(university_etablissement_id),
 INDEX idx_doc_archive_type(document_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "OK - table stage_document_archives prête.";
}catch(Throwable $e){http_response_code(500);echo 'Erreur : '.$e->getMessage();}

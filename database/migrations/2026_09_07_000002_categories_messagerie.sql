-- STAGIA-RDC — Catégories de messagerie et brouillons
-- Migration rejouable et non destructive.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brouillons_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    utilisateur_id INT NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    contenu TEXT NOT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    modifie_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_brouillon_utilisateur_conversation (utilisateur_id, conversation_id),
    KEY idx_brouillons_modification (utilisateur_id, modifie_le),
    CONSTRAINT fk_brouillons_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_brouillons_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO communication_migrations(version,description)
VALUES('2026.09.07.000002','Catégories Reçus, Envoyés et Brouillons de la messagerie');

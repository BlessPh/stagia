-- STAGIA-RDC - appareils mobiles pour les notifications push et le temps reel.
-- Migration rejouable, sans modification du workflow Web existant.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mobile_notification_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    utilisateur_id INT NOT NULL,
    plateforme ENUM('android','ios','web') NOT NULL,
    jeton VARCHAR(512) NOT NULL,
    jeton_hash CHAR(64) NOT NULL,
    nom_appareil VARCHAR(150) NULL,
    version_application VARCHAR(50) NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    derniere_activite_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    modifie_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_mobile_device_public (identifiant_public),
    UNIQUE KEY uq_mobile_device_token (jeton_hash),
    KEY idx_mobile_device_user (utilisateur_id, actif, derniere_activite_le),
    CONSTRAINT fk_mobile_device_user FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_mobile_device_active CHECK (actif IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO communication_migrations(version,description)
VALUES('2026.09.27.000012','Appareils mobiles pour notifications push et flux temps reel');

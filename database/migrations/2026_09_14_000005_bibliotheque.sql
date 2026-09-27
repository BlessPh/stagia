-- Bibliothèque numérique 1.0.0 — migration additive, MySQL 8.
-- Exécuter sur la base STAGIA existante. Aucun document de démonstration.
CREATE TABLE IF NOT EXISTS bibliotheque_categories (
 id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 nom VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bibliotheque_documents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 titre VARCHAR(255) NOT NULL,
 auteur VARCHAR(200) NOT NULL,
 resume TEXT NOT NULL,
 mots_cles VARCHAR(500) NOT NULL DEFAULT '',
 categorie_id INT NOT NULL,
 langue VARCHAR(50) NOT NULL DEFAULT 'Français',
 annee SMALLINT UNSIGNED NULL,
 licence VARCHAR(255) NOT NULL,
 visibilite ENUM('globale','etablissement') NOT NULL,
 etablissement_id INT NULL,
 depose_par INT NOT NULL,
 statut ENUM('brouillon','soumis','publie','rejete','archive','corbeille') NOT NULL DEFAULT 'brouillon',
 motif TEXT NULL,
 nom_original VARCHAR(255) NOT NULL,
 nom_stockage CHAR(64) NOT NULL UNIQUE,
 mime VARCHAR(100) NOT NULL,
 taille BIGINT UNSIGNED NOT NULL,
 sha256 CHAR(64) NOT NULL,
 cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 modifie_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 publie_le DATETIME NULL,
 revision INT NOT NULL DEFAULT 1,
 KEY idx_biblio_catalogue (statut,visibilite,etablissement_id,cree_le),
 KEY idx_biblio_auteur (depose_par,statut),
 CONSTRAINT fk_biblio_categorie FOREIGN KEY(categorie_id) REFERENCES bibliotheque_categories(id),
 CONSTRAINT fk_biblio_etablissement FOREIGN KEY(etablissement_id) REFERENCES etablissements(id),
 CONSTRAINT fk_biblio_deposant FOREIGN KEY(depose_par) REFERENCES users(id),
 CONSTRAINT chk_biblio_portee CHECK(visibilite='globale' OR etablissement_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bibliotheque_favoris (
 utilisateur_id INT NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(utilisateur_id,document_id),
 FOREIGN KEY(utilisateur_id) REFERENCES users(id),
 FOREIGN KEY(document_id) REFERENCES bibliotheque_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bibliotheque_consultations (
 utilisateur_id INT NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 lectures INT UNSIGNED NOT NULL DEFAULT 0,
 telechargements INT UNSIGNED NOT NULL DEFAULT 0,
 derniere_consultation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(utilisateur_id,document_id),
 FOREIGN KEY(utilisateur_id) REFERENCES users(id),
 FOREIGN KEY(document_id) REFERENCES bibliotheque_documents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS bibliotheque_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 document_id BIGINT UNSIGNED NOT NULL,
 acteur_id INT NOT NULL,
 action VARCHAR(50) NOT NULL,
 details JSON NOT NULL,
 cree_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_biblio_audit_doc(document_id,cree_le),
 FOREIGN KEY(document_id) REFERENCES bibliotheque_documents(id),
 FOREIGN KEY(acteur_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO bibliotheque_categories(nom) VALUES
('Livres et manuels'),('Cours et supports pédagogiques'),('Articles et recherches'),
('Mémoires et thèses'),('Guides de stage'),('Textes institutionnels'),('Autres ressources');
INSERT INTO permissions(code,nom,module,description,systeme,actif) VALUES
('library.manage','Gérer la bibliothèque institutionnelle','BIBLIOTHEQUE','Valider les documents internes dans le périmètre de son affectation.',1,1)
ON DUPLICATE KEY UPDATE nom=VALUES(nom);

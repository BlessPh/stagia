-- STAGIA / Référentiel géographique v1.0.0 — MySQL 8.0+
-- Exécuter dans la base existante. Aucun DROP ni changement des tables métier.
-- Les identifiants demande/établissement/utilisateur sont INT signés, comme dans stagia_recovery.sql.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS geographie_version (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 modifie_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS geographie_unites (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(50) NOT NULL,
 parent_id INT UNSIGNED NULL,
 parent_cle INT UNSIGNED GENERATED ALWAYS AS (COALESCE(parent_id,0)) STORED,
 type ENUM('PROVINCE','VILLE','TERRITOIRE','COMMUNE','SECTEUR','CHEFFERIE') NOT NULL,
 nom VARCHAR(100) NOT NULL,
 source VARCHAR(500) NOT NULL,
 actif TINYINT UNSIGNED NOT NULL DEFAULT 1,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 cree_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 modifie_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_geo_code(code),
 UNIQUE KEY uq_geo_parent_type_nom(parent_cle,type,nom),
 KEY ix_geo_parent_actif(parent_id,actif,nom),
 CONSTRAINT fk_geo_parent FOREIGN KEY(parent_id) REFERENCES geographie_unites(id) ON DELETE RESTRICT,
 CONSTRAINT ck_geo_racine CHECK ((type='PROVINCE' AND parent_id IS NULL) OR (type<>'PROVINCE' AND parent_id IS NOT NULL)),
 CONSTRAINT ck_geo_actif CHECK (actif IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS geographie_localisations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 demande_adhesion_id INT NOT NULL,
 etablissement_id INT NULL,
 unite_id INT UNSIGNED NOT NULL,
 precision_localite VARCHAR(255) NULL,
 ville_libre VARCHAR(100) NULL,
 a_completer TINYINT UNSIGNED NOT NULL DEFAULT 0,
 chemin_snapshot JSON NOT NULL,
 cree_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_geo_demande(demande_adhesion_id),
 UNIQUE KEY uq_geo_etablissement(etablissement_id),
 KEY ix_geo_unite(unite_id),
 KEY ix_geo_incomplet(a_completer),
 CONSTRAINT fk_geo_demande FOREIGN KEY(demande_adhesion_id) REFERENCES demandes_adhesion(id) ON DELETE RESTRICT,
 CONSTRAINT fk_geo_etablissement FOREIGN KEY(etablissement_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
 CONSTRAINT fk_geo_localite FOREIGN KEY(unite_id) REFERENCES geographie_unites(id) ON DELETE RESTRICT,
 CONSTRAINT ck_geo_complet CHECK(a_completer IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS geographie_journal (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 unite_id INT UNSIGNED NOT NULL,
 acteur_id INT NULL,
 action VARCHAR(30) NOT NULL,
 avant JSON NULL,
 apres JSON NOT NULL,
 cree_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_geo_journal_unite(unite_id,id),
 KEY ix_geo_journal_acteur(acteur_id),
 CONSTRAINT fk_geo_journal_unite FOREIGN KEY(unite_id) REFERENCES geographie_unites(id) ON DELETE RESTRICT,
 CONSTRAINT fk_geo_journal_acteur FOREIGN KEY(acteur_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
INSERT INTO geographie_version(id) SELECT 1 WHERE NOT EXISTS(SELECT 1 FROM geographie_version WHERE id=1);
-- Source : Constitution, article 2, Journal officiel du 5 février 2011, page 6.
-- https://faolex.fao.org/docs/pdf/cng128142.pdf
-- Codes RDC-P-* internes à STAGIA, non présentés comme des codes ISO officiels.
-- Les relances ne modifient pas les noms, sources ou états corrigés par l'administrateur.
INSERT INTO geographie_unites(code,parent_id,type,nom,source)
SELECT p.code,NULL,'PROVINCE',p.nom,'Constitution RDC, article 2, JO 05/02/2011 p.6 ; https://faolex.fao.org/docs/pdf/cng128142.pdf ; vérifié le 14/09/2026'
FROM (
 SELECT 'RDC-P-BAS-UELE' AS code,'Bas-Uélé' AS nom UNION ALL
 SELECT 'RDC-P-EQUATEUR','Équateur' UNION ALL
 SELECT 'RDC-P-HAUT-KATANGA','Haut-Katanga' UNION ALL
 SELECT 'RDC-P-HAUT-LOMAMI','Haut-Lomami' UNION ALL
 SELECT 'RDC-P-HAUT-UELE','Haut-Uélé' UNION ALL
 SELECT 'RDC-P-ITURI','Ituri' UNION ALL
 SELECT 'RDC-P-KASAI','Kasaï' UNION ALL
 SELECT 'RDC-P-KASAI-CENTRAL','Kasaï-Central' UNION ALL
 SELECT 'RDC-P-KASAI-ORIENTAL','Kasaï-Oriental' UNION ALL
 SELECT 'RDC-P-KINSHASA','Kinshasa' UNION ALL
 SELECT 'RDC-P-KONGO-CENTRAL','Kongo-Central' UNION ALL
 SELECT 'RDC-P-KWANGO','Kwango' UNION ALL
 SELECT 'RDC-P-KWILU','Kwilu' UNION ALL
 SELECT 'RDC-P-LOMAMI','Lomami' UNION ALL
 SELECT 'RDC-P-LUALABA','Lualaba' UNION ALL
 SELECT 'RDC-P-MAI-NDOMBE','Mai-Ndombe' UNION ALL
 SELECT 'RDC-P-MANIEMA','Maniema' UNION ALL
 SELECT 'RDC-P-MONGALA','Mongala' UNION ALL
 SELECT 'RDC-P-NORD-KIVU','Nord-Kivu' UNION ALL
 SELECT 'RDC-P-NORD-UBANGI','Nord-Ubangi' UNION ALL
 SELECT 'RDC-P-SANKURU','Sankuru' UNION ALL
 SELECT 'RDC-P-SUD-KIVU','Sud-Kivu' UNION ALL
 SELECT 'RDC-P-SUD-UBANGI','Sud-Ubangi' UNION ALL
 SELECT 'RDC-P-TANGANYIKA','Tanganyika' UNION ALL
 SELECT 'RDC-P-TSHOPO','Tshopo' UNION ALL
 SELECT 'RDC-P-TSHUAPA','Tshuapa'
) AS p
WHERE NOT EXISTS(SELECT 1 FROM geographie_unites u WHERE u.code=p.code);
COMMIT;

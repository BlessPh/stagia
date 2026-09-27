-- STAGIA-RDC — Administration multi-organisation
-- Migration additive et rejouable après les migrations existantes.
-- Les installations issues d'un dump récent peuvent déjà contenir une partie
-- des colonnes ou index ci-dessous.

SET @admin_role_etablissement_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='roles' AND COLUMN_NAME='etablissement_id'
);
SET @admin_role_etablissement_ddl = IF(
    @admin_role_etablissement_exists=0,
    'ALTER TABLE roles ADD COLUMN etablissement_id INT NULL AFTER systeme',
    'SELECT 1'
);
PREPARE admin_role_etablissement_stmt FROM @admin_role_etablissement_ddl;
EXECUTE admin_role_etablissement_stmt;
DEALLOCATE PREPARE admin_role_etablissement_stmt;

SET @admin_role_creator_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='roles' AND COLUMN_NAME='cree_par_user_id'
);
SET @admin_role_creator_ddl = IF(
    @admin_role_creator_exists=0,
    'ALTER TABLE roles ADD COLUMN cree_par_user_id INT NULL AFTER etablissement_id',
    'SELECT 1'
);
PREPARE admin_role_creator_stmt FROM @admin_role_creator_ddl;
EXECUTE admin_role_creator_stmt;
DEALLOCATE PREPARE admin_role_creator_stmt;

SET @admin_role_owner_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='roles' AND INDEX_NAME='idx_roles_proprietaire'
);
SET @admin_role_owner_index_ddl = IF(
    @admin_role_owner_index_exists=0,
    'ALTER TABLE roles ADD INDEX idx_roles_proprietaire (etablissement_id, systeme, actif)',
    'SELECT 1'
);
PREPARE admin_role_owner_index_stmt FROM @admin_role_owner_index_ddl;
EXECUTE admin_role_owner_index_stmt;
DEALLOCATE PREPARE admin_role_owner_index_stmt;

SET @admin_role_creator_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='roles' AND INDEX_NAME='idx_roles_createur'
);
SET @admin_role_creator_index_ddl = IF(
    @admin_role_creator_index_exists=0,
    'ALTER TABLE roles ADD INDEX idx_roles_createur (cree_par_user_id)',
    'SELECT 1'
);
PREPARE admin_role_creator_index_stmt FROM @admin_role_creator_index_ddl;
EXECUTE admin_role_creator_index_stmt;
DEALLOCATE PREPARE admin_role_creator_index_stmt;

-- Les rôles existants sont le catalogue système historique.
UPDATE roles SET etablissement_id=NULL WHERE systeme=1;

CREATE TABLE IF NOT EXISTS organisations_ministeres (
    id BIGINT NOT NULL AUTO_INCREMENT,
    ministere_etablissement_id INT NOT NULL,
    organisation_etablissement_id INT NOT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    rattache_par_user_id INT NULL,
    rattache_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modifie_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ministere_organisation (ministere_etablissement_id, organisation_etablissement_id),
    KEY idx_om_organisation (organisation_etablissement_id, actif),
    CONSTRAINT fk_om_ministere FOREIGN KEY (ministere_etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_om_organisation FOREIGN KEY (organisation_etablissement_id) REFERENCES etablissements(id),
    CONSTRAINT fk_om_createur FOREIGN KEY (rattache_par_user_id) REFERENCES users(id),
    CONSTRAINT chk_om_distinct CHECK (ministere_etablissement_id <> organisation_etablissement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions(code,nom,module,description,systeme,actif)
VALUES
('organization.responsibles.view','Voir les responsables autorisés','ADMINISTRATION','Consulter les responsables du périmètre institutionnel autorisé.',1,1),
('ministry.organizations.manage','Gérer les rattachements ministériels','ADMINISTRATION','Rattacher des organisations au ministère administré.',1,1)
ON DUPLICATE KEY UPDATE nom=VALUES(nom),description=VALUES(description),actif=1;

-- Les administrateurs système reçoivent les nouvelles capacités.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='SUPER_ADMIN'
  AND p.code IN('organization.responsibles.view','ministry.organizations.manage');

-- Le ministère peut consulter les responsables de ses organisations rattachées.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='MINISTERE' AND p.code='organization.responsibles.view';

-- Les administrateurs principaux institutionnels peuvent gérer leurs rôles locaux.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code IN('ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL')
  AND p.code IN('role.view','role.create','role.update','role.permission.manage','user.role.assign','organization.responsibles.view','analytics.overview.view','analytics.students.view');

CREATE TABLE IF NOT EXISTS administration_migrations (
    version VARCHAR(40) NOT NULL PRIMARY KEY,
    description VARCHAR(255) NOT NULL,
    executee_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO administration_migrations(version,description)
VALUES('2026.09.09.000003','Rôles locaux, périmètres ministériels et statistiques institutionnelles');

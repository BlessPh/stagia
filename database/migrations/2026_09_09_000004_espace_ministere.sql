-- STAGIA-RDC — Espace institutionnel Ministère
-- Migration additive à exécuter après 2026_09_09_000003_administration_multi_organisation.sql.

INSERT INTO permissions(code,nom,module,description,systeme,actif)
VALUES
('ministry.dashboard.view','Consulter le tableau de bord ministériel','MINISTERE','Accéder aux indicateurs agrégés du ministère représenté.',1,1),
('ministry.organizations.view','Consulter les organisations rattachées','MINISTERE','Consulter les organisations placées dans le périmètre du ministère.',1,1)
ON DUPLICATE KEY UPDATE nom=VALUES(nom),description=VALUES(description),actif=1;

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code IN('SUPER_ADMIN','MINISTERE')
  AND p.code IN('ministry.dashboard.view','ministry.organizations.view');

INSERT IGNORE INTO administration_migrations(version,description)
VALUES('2026.09.09.000004','Tableau de bord et annuaire des organisations de l’espace Ministère');

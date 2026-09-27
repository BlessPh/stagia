-- STAGIA-RDC — Contrôle non destructif des responsables ministériels
-- Ce fichier ne modifie aucune donnée.

-- 1. Responsables correctement rattachés à leur ministère.
SELECT u.id,u.identifiant,u.nom,u.postnom,u.prenom,u.email,
       e.id AS ministere_id,e.nom AS ministere,
       ra.scope_type,ra.scope_entity,ra.scope_id,ra.principal
FROM users u
JOIN role_assignments ra ON ra.user_id=u.id AND ra.actif=1
JOIN roles r ON r.id=ra.role_id AND r.code='MINISTERE'
JOIN etablissements e ON e.id=ra.etablissement_id AND e.type_etablissement='MINISTERE'
JOIN etablissement_users eu ON eu.user_id=u.id AND eu.etablissement_id=e.id
ORDER BY e.nom,u.nom,u.postnom,u.prenom;

-- 2. Anomalies historiques à corriger manuellement : rôle ministère sans
-- appartenance valide ou encore affecté à toute la plateforme.
SELECT u.id,u.identifiant,u.nom,u.postnom,u.prenom,u.email,
       ra.id AS affectation_id,ra.scope_type,ra.scope_entity,
       ra.scope_id,ra.etablissement_id
FROM users u
JOIN role_assignments ra ON ra.user_id=u.id AND ra.actif=1
JOIN roles r ON r.id=ra.role_id AND r.code='MINISTERE'
LEFT JOIN etablissements e
       ON e.id=ra.etablissement_id AND e.type_etablissement='MINISTERE'
LEFT JOIN etablissement_users eu
       ON eu.user_id=u.id AND eu.etablissement_id=ra.etablissement_id
WHERE ra.scope_type<>'ORGANIZATION'
   OR ra.scope_entity<>'ESTABLISHMENT'
   OR ra.etablissement_id IS NULL
   OR ra.scope_id<>ra.etablissement_id
   OR e.id IS NULL
   OR eu.id IS NULL
ORDER BY u.id,ra.id;

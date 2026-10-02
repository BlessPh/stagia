-- Champs de profil communs à tous les comptes applicatifs.
ALTER TABLE users
    ADD COLUMN sexe VARCHAR(20) NULL AFTER prenom,
    ADD COLUMN date_naissance DATE NULL AFTER sexe,
    ADD COLUMN adresse VARCHAR(255) NULL AFTER date_naissance,
    ADD COLUMN ville VARCHAR(120) NULL AFTER adresse,
    ADD COLUMN province VARCHAR(120) NULL AFTER ville,
    ADD COLUMN matricule VARCHAR(50) NULL AFTER identifiant;

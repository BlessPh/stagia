-- STAGIA-RDC — intégration officielle du module Communication
-- Version 2026.09.04.000001 — MySQL 8 / InnoDB / utf8mb4
-- Rejouable et non destructif. À exécuter après stagia_recovery.sql.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS communication_migrations (
  version VARCHAR(40) PRIMARY KEY,
  description VARCHAR(255) NOT NULL,
  executee_le TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fichiers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    nom_original VARCHAR(255) NOT NULL,
    nom_stockage VARCHAR(255) NOT NULL,
    chemin_stockage VARCHAR(1000) NOT NULL,
    fournisseur_stockage VARCHAR(50) NOT NULL DEFAULT 'local',
    type_mime VARCHAR(150) NOT NULL,
    taille_octets BIGINT UNSIGNED NOT NULL,
    empreinte_sha256 CHAR(64) NOT NULL,
    niveau_confidentialite ENUM('public','interne','confidentiel','tres_confidentiel') NOT NULL DEFAULT 'interne',
    analyse_antivirus ENUM('en_attente','sain','infecte','echec') NOT NULL DEFAULT 'en_attente',
    cree_par_utilisateur_id INT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    archive_le DATETIME(6) NULL,
    UNIQUE KEY uq_fichiers_identifiant_public (identifiant_public),
    UNIQUE KEY uq_fichiers_nom_stockage (nom_stockage),
    KEY idx_fichiers_empreinte (empreinte_sha256),
    CONSTRAINT fk_fichiers_createur FOREIGN KEY (cree_par_utilisateur_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS espaces_collaboratifs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    organisation_proprietaire_id INT NULL,
    type_espace ENUM('institution','campagne','stage','projet','forum','autre') NOT NULL,
    nom VARCHAR(255) NOT NULL,
    description TEXT NULL,
    visibilite ENUM('prive','organisations_invitees','institution','public') NOT NULL DEFAULT 'prive',
    statut ENUM('actif','ferme','archive') NOT NULL DEFAULT 'actif',
    cree_par_utilisateur_id INT NOT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_espaces_identifiant_public (identifiant_public),
    CONSTRAINT fk_espaces_organisation FOREIGN KEY (organisation_proprietaire_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_espaces_createur FOREIGN KEY (cree_par_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS membres_espaces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    espace_collaboratif_id BIGINT UNSIGNED NOT NULL,
    utilisateur_id INT NULL,
    organisation_id INT NULL,
    role_membre ENUM('proprietaire','administrateur','moderateur','contributeur','lecteur') NOT NULL DEFAULT 'lecteur',
    rejoint_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    quitte_le DATETIME(6) NULL,
    UNIQUE KEY uq_membres_espace_utilisateur (espace_collaboratif_id, utilisateur_id),
    UNIQUE KEY uq_membres_espace_organisation (espace_collaboratif_id, organisation_id),
    CONSTRAINT fk_membres_espace FOREIGN KEY (espace_collaboratif_id) REFERENCES espaces_collaboratifs(id) ON DELETE CASCADE,
    CONSTRAINT fk_membres_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_membres_organisation FOREIGN KEY (organisation_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT chk_membres_cible CHECK ((utilisateur_id IS NOT NULL) <> (organisation_id IS NOT NULL))
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    espace_collaboratif_id BIGINT UNSIGNED NULL,
    type_conversation ENUM('privee','groupe','institutionnelle','support') NOT NULL,
    objet VARCHAR(255) NULL,
    statut ENUM('active','fermee','archivee') NOT NULL DEFAULT 'active',
    cree_par_utilisateur_id INT NOT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_conversations_identifiant_public (identifiant_public),
    CONSTRAINT fk_conversations_espace FOREIGN KEY (espace_collaboratif_id) REFERENCES espaces_collaboratifs(id) ON DELETE SET NULL,
    CONSTRAINT fk_conversations_createur FOREIGN KEY (cree_par_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    auteur_utilisateur_id INT NOT NULL,
    organisation_auteur_id INT NULL,
    message_parent_id BIGINT UNSIGNED NULL,
    contenu TEXT NOT NULL,
    statut ENUM('envoye','modifie','retire','archive') NOT NULL DEFAULT 'envoye',
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    modifie_le DATETIME(6) NULL,
    retire_le DATETIME(6) NULL,
    UNIQUE KEY uq_messages_identifiant_public (identifiant_public),
    KEY idx_messages_conversation_date (conversation_id, cree_le),
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_messages_auteur FOREIGN KEY (auteur_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_messages_organisation FOREIGN KEY (organisation_auteur_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_messages_parent FOREIGN KEY (message_parent_id) REFERENCES messages(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS participants_conversations (
    conversation_id BIGINT UNSIGNED NOT NULL,
    utilisateur_id INT NOT NULL,
    organisation_representation_id INT NULL,
    rejoint_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    quitte_le DATETIME(6) NULL,
    dernier_message_lu_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (conversation_id, utilisateur_id),
    CONSTRAINT fk_participants_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_participants_organisation FOREIGN KEY (organisation_representation_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_participants_dernier_message FOREIGN KEY (dernier_message_lu_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS pieces_jointes_messages (
    message_id BIGINT UNSIGNED NOT NULL,
    fichier_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (message_id, fichier_id),
    CONSTRAINT fk_pieces_messages_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_pieces_messages_fichier FOREIGN KEY (fichier_id) REFERENCES fichiers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS communications_officielles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    organisation_emettrice_id INT NULL,
    type_communication ENUM('annonce','circulaire','directive','note_service','convocation','enquete','demande_information','alerte') NOT NULL,
    reference VARCHAR(120) NULL,
    objet VARCHAR(255) NOT NULL,
    contenu LONGTEXT NOT NULL,
    priorite ENUM('normale','haute','urgente') NOT NULL DEFAULT 'normale',
    accuse_reception_requis TINYINT(1) NOT NULL DEFAULT 0,
    date_limite_accuse DATETIME(6) NULL,
    statut ENUM('brouillon','programmee','publiee','retiree','archivee') NOT NULL DEFAULT 'brouillon',
    publiee_par_utilisateur_id INT NULL,
    publiee_le DATETIME(6) NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    modifie_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_communications_identifiant_public (identifiant_public),
    UNIQUE KEY uq_communications_organisation_reference (organisation_emettrice_id, reference),
    CONSTRAINT fk_communications_organisation FOREIGN KEY (organisation_emettrice_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_communications_publicateur FOREIGN KEY (publiee_par_utilisateur_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_communications_accuse CHECK (accuse_reception_requis IN (0,1))
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS destinataires_communications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    communication_officielle_id BIGINT UNSIGNED NOT NULL,
    utilisateur_id INT NULL,
    organisation_id INT NULL,
    division_administrative_id BIGINT UNSIGNED NULL,
    role_id INT NULL,
    statut_remise ENUM('en_attente','envoyee','remise','echec') NOT NULL DEFAULT 'en_attente',
    remise_le DATETIME(6) NULL,
    lue_le DATETIME(6) NULL,
    accusee_le DATETIME(6) NULL,
    KEY idx_destinataires_communication (communication_officielle_id, statut_remise),
    CONSTRAINT fk_destinataires_communication FOREIGN KEY (communication_officielle_id) REFERENCES communications_officielles(id) ON DELETE CASCADE,
    CONSTRAINT fk_destinataires_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_destinataires_organisation FOREIGN KEY (organisation_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT chk_destinataires_cible CHECK (utilisateur_id IS NOT NULL OR organisation_id IS NOT NULL OR division_administrative_id IS NOT NULL OR role_id IS NOT NULL)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS evenements_calendrier (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    organisation_id INT NULL,
    espace_collaboratif_id BIGINT UNSIGNED NULL,
    campagne_stage_id BIGINT UNSIGNED NULL,
    stage_id BIGINT UNSIGNED NULL,
    titre VARCHAR(255) NOT NULL,
    description TEXT NULL,
    type_evenement ENUM('campagne','convocation','reunion','visite','evaluation','echeance','visioconference','autre') NOT NULL,
    debut DATETIME(6) NOT NULL,
    fin DATETIME(6) NOT NULL,
    lieu VARCHAR(255) NULL,
    lien_externe VARCHAR(1000) NULL,
    statut ENUM('planifie','confirme','annule','termine') NOT NULL DEFAULT 'planifie',
    cree_par_utilisateur_id INT NOT NULL,
    UNIQUE KEY uq_evenements_identifiant_public (identifiant_public),
    KEY idx_evenements_dates (debut, fin),
    CONSTRAINT fk_evenements_organisation FOREIGN KEY (organisation_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_evenements_espace FOREIGN KEY (espace_collaboratif_id) REFERENCES espaces_collaboratifs(id) ON DELETE SET NULL,
    CONSTRAINT fk_evenements_createur FOREIGN KEY (cree_par_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_evenements_dates CHECK (fin >= debut)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS participants_evenements (
    evenement_calendrier_id BIGINT UNSIGNED NOT NULL,
    utilisateur_id INT NOT NULL,
    statut_reponse ENUM('invite','accepte','refuse','incertain','present','absent') NOT NULL DEFAULT 'invite',
    repondu_le DATETIME(6) NULL,
    PRIMARY KEY (evenement_calendrier_id, utilisateur_id),
    CONSTRAINT fk_participants_evenement FOREIGN KEY (evenement_calendrier_id) REFERENCES evenements_calendrier(id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_evenement_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS preferences_notifications (
    utilisateur_id INT NOT NULL,
    type_evenement VARCHAR(100) NOT NULL,
    canal ENUM('interne','email','sms','push') NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (utilisateur_id, type_evenement, canal),
    CONSTRAINT fk_preferences_notifications_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_preferences_notifications_active CHECK (active IN (0,1))
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    utilisateur_id INT NOT NULL,
    organisation_id INT NULL,
    type_evenement VARCHAR(100) NOT NULL,
    titre VARCHAR(255) NOT NULL,
    contenu TEXT NOT NULL,
    donnees JSON NULL,
    lien_action VARCHAR(1000) NULL,
    priorite ENUM('basse','normale','haute','urgente') NOT NULL DEFAULT 'normale',
    lue_le DATETIME(6) NULL,
    archivee_le DATETIME(6) NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_notifications_identifiant_public (identifiant_public),
    KEY idx_notifications_utilisateur_lecture (utilisateur_id, lue_le, cree_le),
    CONSTRAINT fk_notifications_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_notifications_organisation FOREIGN KEY (organisation_id) REFERENCES etablissements(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS livraisons_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    canal ENUM('interne','email','sms','push') NOT NULL,
    destinataire VARCHAR(500) NULL,
    statut ENUM('en_attente','en_cours','envoyee','remise','echec','abandonnee') NOT NULL DEFAULT 'en_attente',
    nombre_tentatives SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    prochaine_tentative_le DATETIME(6) NULL,
    envoyee_le DATETIME(6) NULL,
    remise_le DATETIME(6) NULL,
    derniere_erreur TEXT NULL,
    UNIQUE KEY uq_livraisons_notification_canal (notification_id, canal),
    KEY idx_livraisons_traitement (statut, prochaine_tentative_le),
    CONSTRAINT fk_livraisons_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS taches_asynchrones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_attente VARCHAR(100) NOT NULL DEFAULT 'defaut',
    type_tache VARCHAR(180) NOT NULL,
    charge_utile JSON NOT NULL,
    statut ENUM('en_attente','reservee','terminee','echec','abandonnee') NOT NULL DEFAULT 'en_attente',
    priorite SMALLINT NOT NULL DEFAULT 0,
    tentatives SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tentatives_max SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    disponible_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    reservee_le DATETIME(6) NULL,
    reservee_par VARCHAR(180) NULL,
    terminee_le DATETIME(6) NULL,
    derniere_erreur TEXT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_taches_async_reservation (file_attente, statut, disponible_le, priorite)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS messages_sortants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_evenement VARCHAR(180) NOT NULL,
    identifiant_agregat CHAR(26) NULL,
    charge_utile JSON NOT NULL,
    statut ENUM('en_attente','publie','echec') NOT NULL DEFAULT 'en_attente',
    tentatives SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    disponible_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    publie_le DATETIME(6) NULL,
    derniere_erreur TEXT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_messages_sortants_traitement (statut, disponible_le)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS sujets_forums (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    espace_collaboratif_id BIGINT UNSIGNED NOT NULL,
    auteur_utilisateur_id INT NOT NULL,
    titre VARCHAR(255) NOT NULL,
    contenu TEXT NOT NULL,
    statut ENUM('ouvert','ferme','archive') NOT NULL DEFAULT 'ouvert',
    epingle TINYINT(1) NOT NULL DEFAULT 0,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    modifie_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_sujets_forums_identifiant (identifiant_public),
    KEY idx_sujets_forum_espace (espace_collaboratif_id,statut,cree_le),
    CONSTRAINT fk_sujets_forum_espace FOREIGN KEY (espace_collaboratif_id) REFERENCES espaces_collaboratifs(id) ON DELETE CASCADE,
    CONSTRAINT fk_sujets_forum_auteur FOREIGN KEY (auteur_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_sujets_forum_epingle CHECK (epingle IN (0,1))
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS reponses_forums (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    sujet_forum_id BIGINT UNSIGNED NOT NULL,
    auteur_utilisateur_id INT NOT NULL,
    contenu TEXT NOT NULL,
    statut ENUM('publiee','modifiee','retiree','archivee') NOT NULL DEFAULT 'publiee',
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    modifie_le DATETIME(6) NULL,
    UNIQUE KEY uq_reponses_forums_identifiant (identifiant_public),
    KEY idx_reponses_forum_sujet (sujet_forum_id,statut,cree_le),
    CONSTRAINT fk_reponses_forum_sujet FOREIGN KEY (sujet_forum_id) REFERENCES sujets_forums(id) ON DELETE CASCADE,
    CONSTRAINT fk_reponses_forum_auteur FOREIGN KEY (auteur_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS reunions_visioconference (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifiant_public CHAR(26) NOT NULL,
    evenement_calendrier_id BIGINT UNSIGNED NOT NULL,
    fournisseur ENUM('google_meet','zoom','microsoft_teams','jitsi','autre') NOT NULL,
    lien_reunion VARCHAR(1000) NOT NULL,
    code_acces VARCHAR(255) NULL,
    statut ENUM('planifiee','active','terminee','annulee') NOT NULL DEFAULT 'planifiee',
    cree_par_utilisateur_id INT NOT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_reunions_visio_identifiant (identifiant_public),
    UNIQUE KEY uq_reunions_visio_evenement (evenement_calendrier_id),
    CONSTRAINT fk_reunions_visio_evenement FOREIGN KEY (evenement_calendrier_id) REFERENCES evenements_calendrier(id) ON DELETE CASCADE,
    CONSTRAINT fk_reunions_visio_createur FOREIGN KEY (cree_par_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS journal_archivage_communications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_ressource ENUM('conversation','communication','espace','sujet_forum','notification') NOT NULL,
    ressource_id BIGINT UNSIGNED NOT NULL,
    action ENUM('archive','restaure','ferme') NOT NULL,
    motif VARCHAR(500) NULL,
    acteur_utilisateur_id INT NOT NULL,
    cree_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_journal_archivage_ressource (type_ressource,ressource_id,cree_le),
    CONSTRAINT fk_journal_archivage_acteur FOREIGN KEY (acteur_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS corbeille_communications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_ressource ENUM('conversation','message','communication','evenement','espace','sujet_forum','reponse_forum','visioconference','notification') NOT NULL,
    ressource_id BIGINT UNSIGNED NOT NULL,
    libelle VARCHAR(255) NOT NULL,
    etat_avant VARCHAR(80) NULL,
    donnees JSON NULL,
    supprime_par_utilisateur_id INT NOT NULL,
    supprime_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    restaure_le DATETIME(6) NULL,
    KEY idx_corbeille_active (supprime_par_utilisateur_id,restaure_le,supprime_le),
    KEY idx_corbeille_ressource (type_ressource,ressource_id),
    CONSTRAINT fk_corbeille_communication_acteur FOREIGN KEY (supprime_par_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS historique_modifications_communication (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_ressource ENUM('conversation','message','communication','evenement','espace','sujet_forum','reponse_forum','visioconference','configuration') NOT NULL,
    ressource_id BIGINT UNSIGNED NOT NULL,
    anciennes_valeurs JSON NULL,
    nouvelles_valeurs JSON NULL,
    modifie_par_utilisateur_id INT NOT NULL,
    modifie_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY idx_historique_communication (type_ressource,ressource_id,modifie_le),
    CONSTRAINT fk_historique_communication_acteur FOREIGN KEY (modifie_par_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS configurations_canaux_communication (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organisation_id INT NULL,
    organisation_cle INT GENERATED ALWAYS AS (IFNULL(organisation_id,0)) STORED,
    canal ENUM('smtp','sms','push') NOT NULL,
    actif TINYINT(1) NOT NULL DEFAULT 0,
    fournisseur VARCHAR(120) NULL,
    hote VARCHAR(255) NULL,
    port SMALLINT UNSIGNED NULL,
    nom_utilisateur VARCHAR(255) NULL,
    secret_chiffre TEXT NULL,
    adresse_expediteur VARCHAR(254) NULL,
    nom_expediteur VARCHAR(180) NULL,
    parametres JSON NULL,
    modifie_par_utilisateur_id INT NOT NULL,
    modifie_le DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_configuration_canal_organisation (organisation_cle,canal),
    CONSTRAINT fk_configuration_canal_organisation FOREIGN KEY (organisation_id) REFERENCES etablissements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_configuration_canal_acteur FOREIGN KEY (modifie_par_utilisateur_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_configuration_canal_actif CHECK (actif IN (0,1))
) ENGINE=InnoDB;


INSERT IGNORE INTO permissions(code,nom,module,description,systeme,actif) VALUES
('communication.access','Accéder à la communication','COMMUNICATION','Utiliser la messagerie et la collaboration institutionnelle.',1,1),
('communication.publish','Publier une communication','COMMUNICATION','Publier les communications officielles autorisées.',1,1),
('communication.configure','Configurer les canaux','COMMUNICATION','Configurer SMTP, SMS et push.',1,1);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.actif=1 AND p.code='communication.access';

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.actif=1 AND r.code<>'STAGIAIRE' AND p.code='communication.publish';

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.code='SUPER_ADMIN' AND p.code='communication.configure';

INSERT IGNORE INTO communication_migrations(version,description)
VALUES('2026.09.04.000001','Intégration du module Communication au schéma officiel STAGIA');

SELECT COUNT(*) AS tables_communication_installees
FROM information_schema.tables
WHERE table_schema=DATABASE() AND table_name IN ('fichiers','espaces_collaboratifs','membres_espaces','conversations','messages','participants_conversations','pieces_jointes_messages','communications_officielles','destinataires_communications','evenements_calendrier','participants_evenements','preferences_notifications','notifications','livraisons_notifications','taches_asynchrones','messages_sortants','sujets_forums','reponses_forums','reunions_visioconference','journal_archivage_communications','corbeille_communications','historique_modifications_communication','configurations_canaux_communication');
-- Valeur attendue : 23.

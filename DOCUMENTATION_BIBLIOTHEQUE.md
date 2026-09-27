# STAGIA — Bibliothèque numérique 1.2.0

## Correctif visibilité et notifications 1.2.0
Le catalogue partagé reste réservé aux comptes connectés à STAGIA (pas de diffusion
anonyme sur Internet). Il affiche tous les documents publiés pour le public concerné,
indépendamment du déposant. Les inscriptions étudiantes actives sont désormais prises
en compte, ainsi que les affectations ORGANIZATION / ESTABLISHMENT portées par scope_id.
Les anciennes appartenances etablissement_users sont utilisées uniquement pour les
comptes n'ayant aucune affectation de rôle : une affectation révoquée ne doit pas
être contournée par cette compatibilité.
Les droits de gestion restent régis par les permissions et l'administration principale.

Retour automatique au catalogue sans filtres après validation. Le bouton Catalogue
partagé réinitialise aussi les filtres. Actualisation toutes les 30 secondes lorsque
la page est visible et aucune fiche ouverte.

Notifications internes ciblées lors de chaque nouvelle publication :
- Diffusion globale : comptes actifs disposant d'un accès STAGIA actif.
- Diffusion interne : comptes actifs appartenant à l'établissement via la même source
  que celle utilisée par le catalogue. Le validateur lui-même est exclu.
- Enregistrement dans la table notifications existante, avec lien direct vers la fiche.
- Le compteur global existant inclut ces alertes ; le bouton Notifications de la
  bibliothèque permet de les ouvrir (50 dernières accessibles).
- Marquage lu après affichage de la fiche. Les ressources devenues inaccessibles
  ne sont plus proposées dans la liste de notifications de la bibliothèque.
- Publication et notifications dans une seule transaction ; le verrou de document et
  le contrôle de révision empêchent le doublon sur un double clic de validation.
- Le nombre de destinataires est inscrit dans l'audit.
Ce lot ajoute des notifications internes, sans envoi e-mail/SMS/push.
Les publications antérieures restent visibles selon leur diffusion mais ne déclenchent
pas d'alertes rétroactives à l'installation.

Installation : même script, reconnaissant les fichiers 1.0.0 et 1.1.0 avant sauvegarde.
Aucune nouvelle migration depuis 1.1.0. La table notifications du module Communication
doit être installée ; une erreur de notification annule la transaction de publication.
Pour une nouvelle installation, exécuter les migrations 000005 puis 000006.
Le script s'arrête si le travail local diffère des versions connues.

Diagnostic en lecture seule si un document global reste absent :
php bin/diagnostiquer-bibliotheque.php email_du_lecteur id_du_document
Le résultat distingue l'accès à la notice par le propriétaire de la visibilité dans le
catalogue. Vérifier statut=publie, le niveau de diffusion et les établissements du lecteur.
Ne pas forcer tous les documents en diffusion globale pour corriger une appartenance.

## Mise à jour depuis 1.0.0
Arrêter le serveur PHP, sauvegarder la base puis extraire l'archive hors du projet.
Exécuter bash installer-bibliotheque.sh /chemin/vers/stagia2.
L'installateur reconnaît les fichiers 1.0.0 par leur empreinte et les sauvegarde avant
remplacement. Il accepte aussi les fichiers déjà identiques à 1.1.0.
En cas de modification locale inconnue, il s'arrête avant toute copie pour permettre
une fusion avec le travail de l'équipe.

Depuis le projet, appliquer seulement la nouvelle migration :
mysql -h 127.0.0.1 -P 3306 -u Greak_Kay -p stagia_recovery < database/migrations/2026_09_14_000006_bibliotheque_validation.sql

Redémarrer le serveur et recharger avec Ctrl+Shift+R.
Les anciens documents conservent leur état et leur diffusion. Leur téléchargement
est désactivé par défaut : le déposant doit l'autoriser depuis la fiche.
La migration est rejouable et ne modifie pas les autorisations déjà enregistrées.

## Espace de validation et gestion
Lien direct : /views/bibliotheque/index.php#validation.
Les gestionnaires habilités voient les dépôts soumis dans leur périmètre, un compteur
des attentes, l'identité du déposant et les filtres de catégorie et de diffusion.
Ouvrir une fiche, consulter le contenu, puis « Valider et publier » ou « Demander une
correction » avec motif. Le catalogue n'expose le document qu'à partir de l'état publié :
global = tous les comptes actifs ; interne = membres actifs de l'établissement.

L'onglet Gestion des publications permet de retrouver tous les états autorisés,
filtrer par état, modifier, archiver et mettre à la corbeille.
L'auteur peut retirer sa soumission pour corriger un brouillon.
L'historique affiche les 50 dernières actions avec acteur, date et motif de rejet,
uniquement au déposant et aux gestionnaires habilités.
Une révision vérifiée sous verrou empêche de décider depuis une fiche devenue périmée.
Une erreur de conflit demande de fermer puis rouvrir la fiche.

## Autorisation du téléchargement
Le compte déposant représente l'auteur responsable de la diffusion. Le champ texte
« Auteur(s) » de la notice n'accorde aucun droit à un autre compte.
La case au dépôt est décochée par défaut. Le déposant peut ensuite autoriser ou
interdire le téléchargement depuis la fiche, sans dépublier la ressource.
Aucun autre compte, même SUPER_ADMIN, ne peut changer ce choix via cette opération.
Les modifications de notice et les validations ne changent pas l'autorisation.
Le lien est masqué en lecture seule et l'endpoint telecharger=1 renvoie 403 sans accord.
L'accord de téléchargement ne contourne jamais le périmètre ni l'état du document.
Cette restriction n'est pas un DRM : la lecture intégrée transmet le fichier au
navigateur, dont les outils ou le lecteur PDF peuvent permettre une copie locale.

## Intégration
Extension additive préparée sur STAGIA Administration/Communication v1.2.2.
Architecture conservée : views, actions, includes, assets, database/migrations.
Le paquet contient uniquement les nouveaux fichiers. Il ne remplace pas le module
Communication, les configurations, les secrets, les dépôts utilisateurs ni la base.
L'installateur ajoute seulement un lien à la barre latérale existante après sauvegarde,
s'il n'est pas déjà présent.

## Fonctionnalités livrées
- Catalogue paginé (12 documents), recherche par titre, auteur et mots-clés.
- Filtre par catégorie, tri par date ou titre.
- Notice : auteur, résumé, mots-clés, langue, année et licence/autorisation.
- Dépôt PDF, TXT, JPG/JPEG, PNG ou WEBP (20 Mo maximum).
- Lecture intégrée via le navigateur et téléchargement contrôlé.
- Favoris personnels.
- Mes dépôts, validation, archives, corbeille.
- Modification des notices ; une modification remet le document en brouillon.
- Brouillon → Soumis → Publié ou Rejeté (motif requis).
- Archivage, suppression logique et restauration en brouillon.
- Compteurs d'ouvertures et téléchargements visibles sur les fiches du catalogue.
- Journalisation en base des dépôts, modifications et transitions.

Aucun livre n'est fourni : déposez uniquement des contenus dont la diffusion est autorisée.
La recherche porte sur les notices, pas sur le texte intégral des fichiers.
L'affichage PDF dépend du lecteur intégré au navigateur. Aucun OCR, EPUB, prêt
chronométré, paiement, notification automatique ou antivirus n'est inclus dans ce lot.
Le remplacement du fichier n'est pas inclus : déposer une nouvelle ressource.
Les compteurs sont des requêtes d'ouverture/téléchargement, pas une preuve de lecture complète.

## Droits
Tous les comptes authentifiés et actifs peuvent consulter le catalogue autorisé,
mettre en favoris et proposer une ressource. Aucun code de rôle particulier n'est
requis pour la consultation.

- Globale : visible par tous seulement après publication ; validation par SUPER_ADMIN.
- Établissement : visible par les affectations actives de cet établissement après publication.
- Gestion locale : administrateur principal reconnu par le socle, ou permission
  library.manage attribuée dans le périmètre de l'établissement.
- Un ministère ne reçoit pas automatiquement accès aux documents internes de ses
  organismes supervisés. La tutelle n'est pas une appartenance à ces organismes.
- Le déposant consulte ses propres états non publiés et modifie ses brouillons/rejets.
- Un document soumis n'est plus modifiable par son déposant tant qu'il n'est pas rejeté.
- Le gestionnaire local ne publie pas de document global.
- Les documents en corbeille ne peuvent pas être lus/téléchargés avant restauration.
- La restauration ne republie jamais automatiquement.
Les consultations et opérations sont vérifiées côté serveur, y compris les URL directes.

## Tables ajoutées
bibliotheque_categories, bibliotheque_documents, bibliotheque_favoris,
bibliotheque_consultations, bibliotheque_audit.
Références aux tables existantes users et etablissements (id INT signé).
La permission library.manage est ajoutée à permissions sans affectation massive.
La migration ne supprime ni ne réinitialise de données. Elle est rejouable
pour le schéma identique de cette version ; elle ne répare pas un schéma divergent.

## Installation locale
1. Arrêter le serveur PHP et sauvegarder la base avant migration.
2. Extraire cette archive dans un dossier séparé du projet.
3. Exécuter :
   bash installer-bibliotheque.sh /home/VOTRE_COMPTE/Projets/stagia2
   L'installateur vérifie la syntaxe PHP avant de copier les fichiers.
   Il s'arrête si un fichier de bibliothèque contient des modifications locales inconnues.
4. Depuis le projet :
   mysql -h 127.0.0.1 -P 3306 -u Greak_Kay -p stagia_recovery < database/migrations/2026_09_14_000005_bibliotheque.sql
   mysql -h 127.0.0.1 -P 3306 -u Greak_Kay -p stagia_recovery < database/migrations/2026_09_14_000006_bibliotheque_validation.sql
5. Démarrer :
   php -S 127.0.0.1:8001
6. Ouvrir /views/bibliotheque/index.php et actualiser le navigateur.

Aucune réimportation du dump général n'est nécessaire.
Si le menu a une structure différente, les fichiers restent installés et le script
signale l'échec : intégrer manuellement un appel stagiaNav vers
BASE_URL.'/views/bibliotheque/index.php', icône bi-book, page bibliotheque.
Le script de menu est rejouable et ne duplique pas le lien.

## Stockage / configuration PHP
Le dossier par défaut est stagia_bibliotheque_privee à côté du dossier projet.
Exemple : ~/Projets/stagia_bibliotheque_privee pour ~/Projets/stagia2.
Il est créé avec droits 0700, fichiers 0600, sous l'utilisateur du serveur PHP.
Possibilité de définir BIBLIOTHEQUE_STORAGE_DIR en chemin absolu via la configuration
d'environnement existante. Il doit impérativement être hors du projet ET du document root.
Ne jamais servir ~/Projets comme racine Web ni créer d'alias public vers le stockage.
Le code refuse un emplacement situé sous la racine Web détectée.
Configurer upload_max_filesize=20M et post_max_size=24M dans le php.ini du serveur,
puis redémarrer PHP. Aucun secret n'est livré.
Le contrôle extension/MIME n'est pas une analyse antivirus : prévoir celle-ci avant
une ouverture publique à des dépôts non fiables.

## Vérifications
À exécuter localement :
php tests/bibliotheque_autorisations.php
Ces 39 assertions vérifient les décisions de lecture, gestion, modification,
téléchargement et l'exclusivité du choix par le déposant.
Elles ne testent pas l'intégration MySQL ou le serveur HTTP.

Recette obligatoire avant validation équipe :
- Appliquer la migration sur une copie de base, puis la rejouer.
- Déposer un PDF sous un étudiant A ; vérifier le brouillon et la soumission.
- Vérifier que l'étudiant B ne voit pas le brouillon même avec son URL directe.
- Publier globalement en SUPER_ADMIN et consulter depuis deux établissements.
- Publier en interne à A ; vérifier un refus depuis B sur notice ET fichier.
- Affecter library.manage à un responsable de A ; il ne gère pas B ni le global.
- Tester rejet avec motif, correction, resoumission, archive, corbeille, restauration.
- Tester favoris, pagination, recherches et compteurs.
- Vérifier qu'une soumission est visible aux validateurs mais pas aux lecteurs ;
  après validation, vérifier immédiatement le catalogue de deux établissements.
- Tester téléchargement désactivé via le bouton et URL directe, puis activé par le
  déposant ; vérifier le refus depuis un établissement hors périmètre.
- Vérifier qu'un administrateur ne peut pas modifier ce choix à la place du déposant.
- Retirer une soumission puis tenter de valider depuis une ancienne fiche :
  la décision doit être refusée et l'état rester brouillon.
- Vérifier que la modification et la restauration retirent la visibilité publiée.
- Tester un étudiant rattaché uniquement par student_enrollments : il voit la publication
  interne de son établissement, mais pas celle d'un autre.
- Publier et vérifier une seule notification par destinataire, y compris sous double clic.
- Vérifier la présence de l'alerte dans le compteur existant et son ouverture depuis
  Bibliothèque > Notifications. Le marquage lu doit correspondre à l'utilisateur courant.
- Simuler un échec d'insertion de notification sur une base de test : publication annulée.
- Tester upload trop volumineux, MIME falsifié, jeton CSRF absent et session expirée.
- Tester la tablette, le mobile, le clavier, le lecteur PDF et les téléchargements.
- Recontrôler connexion, communication, espace ministère et navigation existante.

Dans l'environnement de préparation : vérification JavaScript et inspection statique.
PHP/MySQL n'étant pas disponibles, la syntaxe PHP et la recette intégrée sont à
exécuter sur votre machine. Ne pas considérer ce lot comme validé en production.

## Retour arrière
Arrêter PHP et restaurer uniquement l'en-tête sauvegardé par l'installateur (chemin affiché).
Retirer le lien suffit à retirer la navigation ; pour désactiver aussi les accès directs,
déplacer hors du dossier Web les dossiers actions/bibliotheque et views/bibliotheque.
Conserver les tables et le stockage privé pour ne perdre aucun document.
Les fichiers des autres modules ne sont pas modifiés.

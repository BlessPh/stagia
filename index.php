<?php
/*
 * Portail public STAGIA-RDC.
 * Cette page présente la plateforme et oriente les visiteurs vers la
 * connexion, l’adhésion ou la vérification d’un étudiant.
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <!-- Métadonnées, bibliothèques visuelles et styles. -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta
        name="description"
        content="STAGIA-RDC - Système National de Gestion Intelligente des Stages et de l'Insertion Professionnelle"
    >

    <title>STAGIA-RDC | Portail National des Stages</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css"
        rel="stylesheet"
    >
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        /* Ajustement local de la couleur des liens de navigation. */
        .nav-link {
            display: block;
            padding: var(--bs-nav-link-padding-y) var(--bs-nav-link-padding-x);
            font-size: var(--bs-nav-link-font-size);
            font-weight: var(--bs-nav-link-font-weight);
            color: #f8f8f8;
            text-decoration: none;
            background: 0 0;
            border: 0;
            transition:
                color .15s ease-in-out,
                background-color .15s ease-in-out,
                border-color .15s ease-in-out;
        }
    </style>
</head>

<body>
    <!-- Barre institutionnelle. -->
    <div class="institution-bar">
        <div class="container d-flex justify-content-between align-items-center">
            <span>République Démocratique du Congo</span>
            <span class="d-none d-md-inline">Portail National des Stages</span>
        </div>
    </div>

    <!-- Navigation à ancres. -->
    <nav class="navbar navbar-expand-lg main-navbar">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img
                    src="assets/img/logo.png"
                    class="brand-logo"
                    alt="Logo STAGIA-RDC"
                >
            </a>

            <button
                class="navbar-toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#mainMenu"
                aria-label="Menu"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="mainMenu">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link active" href="#accueil">Accueil</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#plateforme">La plateforme</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#fonctionnement">Fonctionnement</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#acteurs">Acteurs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#apropos">Qui sommes-nous ?</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#partenaires">Partenaires</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <li class="nav-item ms-lg-3 mt-3 mt-lg-0">
                        <a href="login.php" class="btn btn-login">
                            <i class="bi bi-box-arrow-in-right me-1"></i>
                            Se connecter
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Bannière principale. -->
    <section class="hero-section" id="accueil">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-7" style="margin-top: -20px;">
                    <div class="hero-badge">
                        <i class="bi bi-globe-africa"></i>
                        Plateforme nationale
                    </div>

                    <h1 class="hero-title">
                        Le portail national de
                        <span>gestion intelligente des stages</span>
                    </h1>

                    <p class="hero-description">
                        STAGIA-RDC accompagne les établissements,
                        organismes d'accueil, encadreurs et stagiaires
                        dans l'organisation, le suivi, l'évaluation
                        et la valorisation des stages.
                    </p>

                    <!-- Accès aux parcours principaux. -->
                    <div class="hero-actions">
                        <a href="login.php" class="btn btn-primary-stagia">
                            <i class="bi bi-person-circle me-2"></i>
                            Accéder à mon espace
                        </a>

                        <a href="adhesion.php" class="btn btn-outline-stagia">
                            <i class="bi bi-building-add me-2"></i>
                            Demander une adhésion
                            <i class="bi bi-arrow-right ms-2"></i>
                        </a>

                        <a href="verifier-etudiant.php" class="btn btn-outline-stagia">
                            <i class="bi bi-person-check me-2"></i>
                            Vérifier un étudiant
                        </a>
                    </div>

                    <div class="hero-info">
                        <div>
                            <i class="bi bi-shield-check"></i>
                            Accès sécurisé
                        </div>
                        <div>
                            <i class="bi bi-clock-history"></i>
                            Traçabilité
                        </div>
                        <div>
                            <i class="bi bi-diagram-3"></i>
                            Gestion centralisée
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="hero-visual">
                        <div class="hero-shape"></div>

                        <!-- Cette image est remplacée automatiquement par JavaScript. -->
                        <img
                            src="assets/img/hero/hero-1.png"
                            id="heroImage"
                            class="hero-image"
                            alt="STAGIA-RDC"
                        >

                        <div class="hero-floating-card">
                            <span class="floating-icon">
                                <i class="bi bi-mortarboard-fill"></i>
                            </span>

                            <div>
                                <strong>STAGIA-RDC</strong>
                                <small>Votre stage, notre priorité</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Écosystème de la plateforme. -->
    <section
        class="ecosystem-section"
        id="plateforme"
        data-aos="zoom-in"
        data-aos-delay="100"
    >
        <div class="container">
            <div class="section-heading text-center">
                <span class="section-label">LA PLATEFORME</span>
                <h2>Un écosystème numérique unique</h2>
                <p>
                    STAGIA-RDC centralise les acteurs et processus liés aux stages
                    dans un environnement numérique intégré.
                </p>
            </div>

            <?php
            /*
             * Données de présentation.
             * Chaque ligne génère une carte autour du logo STAGIA.
             */
            $features = [
                ['bi-bank', 'Établissements', 'Universités, instituts et centres de formation.'],
                ['bi-building-check', 'Organismes d’accueil', 'Entreprises, hôpitaux, administrations et ONG.'],
                ['bi-people', 'Stagiaires', 'Gestion du parcours académique et professionnel.'],
                ['bi-geo-alt', 'Affectation', 'Candidatures, capacités et affectations.'],
                ['bi-clipboard-data', 'Suivi & évaluation', 'Présences, activités et évaluations.'],
                ['bi-graph-up-arrow', 'Insertion professionnelle', 'Valorisation des compétences et opportunités.']
            ];
            ?>

            <div class="ecosystem">
                <div class="ecosystem-center">
                    <div class="ecosystem-logo">
                        <img src="assets/img/logo.png" alt="STAGIA-RDC">
                    </div>

                    <strong>ÉCOSYSTÈME STAGIA</strong>
                    <span>Une plateforme • Plusieurs acteurs</span>
                </div>

                <?php foreach ($features as $i => [$icon, $title, $text]): ?>
                    <!-- L’index définit la position visuelle et le délai d’animation. -->
                    <div class="ecosystem-item item-<?= $i + 1 ?>">
                        <div
                            class="ecosystem-item-aos"
                            data-aos="fade-up"
                            data-aos-delay="<?= 100 + ($i * 100) ?>"
                            data-aos-duration="700"
                        >
                            <div class="ecosystem-icon">
                                <i class="bi <?= $icon ?>"></i>
                            </div>

                            <div>
                                <h4><?= $title ?></h4>
                                <p><?= $text ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Étapes du parcours de stage. -->
    <section
        class="process-section"
        id="fonctionnement"
        aos-init
        aos-animate*"*"
        data-aos="fade-up"
    >
        <div class="container">
            <div class="section-heading text-center">
                <span class="section-label">PARCOURS</span>
                <h2>Comment fonctionne STAGIA ?</h2>
            </div>

            <div class="process-container">
                <?php
                /* Étapes du parcours métier, affichées dans leur ordre naturel. */
                $steps = [
                    ['bi-person-plus', 'Inscription', 'Création et validation du dossier.'],
                    ['bi-send', 'Candidature', 'Participation aux campagnes de stage.'],
                    ['bi-geo-alt', 'Affectation', "Placement dans une structure d'accueil."],
                    ['bi-journal-check', 'Suivi', 'Activités et présences documentées.'],
                    ['bi-award', 'Évaluation', 'Validation et valorisation du parcours.']
                ];

                foreach ($steps as $i => [$icon, $title, $text]):
                    /* La flèche n’est affichée qu’entre deux étapes. */
                    if ($i):
                ?>
                    <div class="process-arrow">
                        <i class="bi bi-arrow-right"></i>
                    </div>
                <?php endif; ?>

                    <div
                        class="process-item"
                        aos-init
                        aos-animate*"*"
                        data-aos="fade-up"
                    >
                        <span><?= sprintf('%02d', $i + 1) ?></span>
                        <i class="bi <?= $icon ?>"></i>
                        <h5><?= $title ?></h5>
                        <p><?= $text ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Principaux acteurs. -->
    <section class="section actors-section" id="acteurs">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="section-label">UN SYSTÈME INTÉGRÉ</span>

                    <h2 class="actors-title">
                        Tous les acteurs du stage dans un environnement unique
                    </h2>

                    <p class="actors-text">
                        STAGIA facilite les échanges entre établissements de formation,
                        structures d'accueil, stagiaires, encadreurs et autorités compétentes.
                    </p>

                    <a href="login.php" class="btn btn-primary-stagia">
                        Commencer maintenant
                        <i class="bi bi-arrow-right ms-2"></i>
                    </a>
                </div>

                <div class="col-lg-6">
                    <div class="actors-grid">
                        <div><i class="bi bi-bank"></i>Université</div>
                        <div><i class="bi bi-building"></i>Entreprise</div>
                        <div><i class="bi bi-hospital"></i>Hôpital</div>
                        <div><i class="bi bi-person-vcard"></i>Stagiaire</div>
                        <div><i class="bi bi-person-workspace"></i>Encadreur</div>
                        <div><i class="bi bi-buildings"></i>Administration</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Présentation institutionnelle. -->
    <section class="about-section" id="apropos">
        <div class="container">
            <div class="row align-items-center g-5">
                <div
                    class="col-lg-6"
                    data-aos="fade-right"
                    data-aos-delay="100"
                >
                    <span class="section-label">QUI SOMMES-NOUS ?</span>

                    <h2 class="about-title">
                        Une plateforme nationale au service
                        <span>des stages et de l'insertion professionnelle</span>
                    </h2>

                    <p class="about-text">
                        STAGIA-RDC est une plateforme numérique conçue pour moderniser,
                        centraliser et sécuriser la gestion des stages en République
                        Démocratique du Congo.
                    </p>

                    <p class="about-text">
                        Elle rapproche les établissements de formation, les organismes
                        d'accueil, les stagiaires, les encadreurs et les administrations
                        au sein d'un environnement numérique unique.
                    </p>

                    <div class="about-points">
                        <div>
                            <span><i class="bi bi-check-lg"></i></span>
                            <div>
                                <strong>Gestion centralisée</strong>
                                <small>
                                    Une plateforme commune pour l'ensemble des acteurs.
                                </small>
                            </div>
                        </div>

                        <div>
                            <span><i class="bi bi-shield-check"></i></span>
                            <div>
                                <strong>Traçabilité et sécurité</strong>
                                <small>
                                    Un suivi numérique fiable du parcours de stage.
                                </small>
                            </div>
                        </div>

                        <div>
                            <span><i class="bi bi-graph-up-arrow"></i></span>
                            <div>
                                <strong>Insertion professionnelle</strong>
                                <small>
                                    Valorisation des compétences et rapprochement
                                    avec les opportunités professionnelles.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="col-lg-6"
                    data-aos="fade-left"
                    data-aos-delay="200"
                >
                    <div class="about-visual">
                        <div class="about-circle about-circle-one"></div>
                        <div class="about-circle about-circle-two"></div>

                        <div class="about-main-card">
                            <img
                                src="assets/img/logo.png"
                                alt="STAGIA-RDC"
                                class="about-logo"
                            >

                            <h3>STAGIA-RDC</h3>
                            <p>Portail National des Stages</p>
                            <div class="about-divider"></div>
                            <span>Une plateforme • Plusieurs acteurs</span>
                        </div>

                        <div class="about-mini-card card-one">
                            <i class="bi bi-bank"></i>
                            <div>
                                <strong>Établissements</strong>
                                <small>Formation</small>
                            </div>
                        </div>

                        <div class="about-mini-card card-two">
                            <i class="bi bi-building-check"></i>
                            <div>
                                <strong>Organismes</strong>
                                <small>Accueil</small>
                            </div>
                        </div>

                        <div class="about-mini-card card-three">
                            <i class="bi bi-people"></i>
                            <div>
                                <strong>Stagiaires</strong>
                                <small>Accompagnement</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Partenaires. -->
    <section class="partners-section" id="partenaires">
        <div class="container">
            <div class="section-heading text-center" data-aos="fade-up">
                <span class="section-label">NOS PARTENAIRES</span>

                <h2>Des partenaires engagés à nos côtés</h2>

                <p>
                    STAGIA-RDC développe un écosystème de collaboration avec
                    les institutions, établissements et organisations contribuant
                    à l'amélioration de la formation et de l'insertion professionnelle.
                </p>
            </div>

            <?php
            /*
             * Remplace simplement les images et les noms
             * lorsque tu auras les logos officiels.
             */
            $partners = [
                ['assets/img/partners/partner-1.png', 'Partenaire institutionnel'],
                ['assets/img/partners/partner-2.png', 'Partenaire académique'],
                ['assets/img/partners/partner-3.png', 'Partenaire professionnel'],
                ['assets/img/partners/partner-4.png', 'Partenaire technique'],
                ['assets/img/partners/partner-5.png', 'Partenaire stratégique'],
                ['assets/img/partners/partner-6.png', 'Partenaire']
            ];
            ?>

            <!-- htmlspecialchars protège les attributs src et alt. -->
            <div class="partners-grid">
                <?php foreach ($partners as $i => [$logo, $name]): ?>
                    <div
                        class="partner-card"
                        data-aos="fade-up"
                        data-aos-delay="<?= 100 + ($i * 80) ?>"
                    >
                        <div class="partner-logo-box">
                            <img
                                src="<?= htmlspecialchars($logo) ?>"
                                alt="<?= htmlspecialchars($name) ?>"
                            >
                        </div>

                        <span><?= htmlspecialchars($name) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div
                class="partners-bottom"
                data-aos="fade-up"
                data-aos-delay="200"
            >
                <div>
                    <i class="bi bi-handshake"></i>

                    <div>
                        <strong>Devenir partenaire de STAGIA-RDC</strong>
                        <p>
                            Rejoignez un écosystème national consacré à la formation,
                            aux stages et à l'insertion professionnelle.
                        </p>
                    </div>
                </div>

                <a href="#contact" class="btn btn-primary-stagia">
                    Nous contacter
                    <i class="bi bi-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- Contact. -->
    <section class="contact-section" id="contact">
        <div class="container">
            <div class="contact-wrapper">
                <div
                    class="contact-visual"
                    aos-init
                    aos-animate*"*"
                    data-aos="fade-right"
                    data-aos-delay="100"
                >
                    <div class="contact-shape"></div>

                    <img
                        src="assets/img/contact.png"
                        alt="Contact STAGIA-RDC"
                        class="contact-image"
                    >

                    <div class="contact-badge">
                        <span><i class="bi bi-headset"></i></span>

                        <div>
                            <strong>Besoin d'aide ?</strong>
                            <small>Notre équipe vous accompagne</small>
                        </div>
                    </div>
                </div>

                <div
                    class="contact-content"
                    aos-init
                    aos-animate*"*"
                    data-aos="fade-left"
                    data-aos-delay="200"
                >
                    <span class="contact-label">CONTACT</span>

                    <h2>
                        Une question ?
                        <br>
                        <span>Parlons-en.</span>
                    </h2>

                    <p class="contact-description">
                        Pour toute demande d'information, d'assistance ou de partenariat,
                        contactez l'équipe STAGIA-RDC.
                    </p>

                    <!-- Formulaire visuel : aucun traitement PHP n'est présent dans index.php. -->
                    <form action="#" method="POST" class="contact-form">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nom complet</label>

                                <div class="contact-input">
                                    <i class="bi bi-person"></i>
                                    <input
                                        type="text"
                                        name="nom"
                                        placeholder="Votre nom"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Adresse e-mail</label>

                                <div class="contact-input">
                                    <i class="bi bi-envelope"></i>
                                    <input
                                        type="email"
                                        name="email"
                                        placeholder="Votre e-mail"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Objet</label>

                                <div class="contact-input">
                                    <i class="bi bi-chat-left-text"></i>
                                    <input
                                        type="text"
                                        name="objet"
                                        placeholder="Objet de votre demande"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Message</label>

                                <div class="contact-input contact-textarea">
                                    <i class="bi bi-pencil"></i>
                                    <textarea
                                        name="message"
                                        rows="4"
                                        placeholder="Écrivez votre message..."
                                        required
                                    ></textarea>
                                </div>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn-contact">
                                    Envoyer le message
                                    <i class="bi bi-send ms-2"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <!-- Pied de page. -->
    <footer class="footer" id="contact">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="footer-logo-box">
                        <img
                            src="assets/img/logo.png"
                            class="footer-logo"
                            alt="STAGIA-RDC"
                        >
                    </div>

                    <p class="mt-3">
                        Système National de Gestion Intelligente des Stages
                        et de l'Insertion Professionnelle.
                    </p>
                </div>

                <div class="col-lg-3">
                    <h6>Navigation</h6>
                    <a href="#accueil">Accueil</a>
                    <a href="#plateforme">La plateforme</a>
                    <a href="#fonctionnement">Fonctionnement</a>
                </div>

                <div class="col-lg-4">
                    <h6>Assistance</h6>
                    <p>République Démocratique du Congo</p>
                    <p>Portail National STAGIA-RDC</p>
                </div>
            </div>

            <hr>

            <div class="footer-bottom">
                <span>© <?= date('Y') ?> STAGIA-RDC. Tous droits réservés.</span>
                <span>Plateforme nationale de gestion des stages</span>
            </div>
        </div>
    </footer>

    <!-- Rotation automatique des images de la bannière. -->
    <script>
        (() => {
            const images = [
                'assets/img/hero/hero-1.png',
                'assets/img/hero/hero-2.png',
                'assets/img/hero/hero-3.png',
                'assets/img/hero/hero-4.png'
            ];

            const hero = document.getElementById('heroImage');
            let index = 0;

            /* Préchargement afin d’éviter une image vide pendant le changement. */
            images.forEach(src => {
                const img = new Image();
                img.src = src;
            });

            /* Fondu sortant, changement de source, puis fondu entrant. */
            setInterval(() => {
                hero.animate(
                    [{ opacity: 1 }, { opacity: 0 }],
                    { duration: 450, fill: 'forwards', easing: 'ease' }
                ).onfinish = () => {
                    index = (index + 1) % images.length;
                    hero.src = images[index];

                    hero.animate(
                        [{ opacity: 0 }, { opacity: 1 }],
                        { duration: 650, fill: 'forwards', easing: 'ease' }
                    );
                };
            }, 4000);
        })();
    </script>

    <!-- Animations au défilement. -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>

    <script>
        AOS.init({
            duration: 1300
        });
    </script>

    <!-- Menu mobile Bootstrap. -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
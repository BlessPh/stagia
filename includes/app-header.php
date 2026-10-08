<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/academic-structure.php';
require_once __DIR__.'/chef-service-scope.php';
require_once __DIR__.'/document-branding.php';

$pageTitle=$pageTitle??'STAGIA-RDC';
$activePage=$activePage??'';
$role=$_SESSION['role_code']??'';
$roleUpper=strtoupper(trim((string)$role));

/* Détection large Maître de stage / Encadreur : évite d'afficher Affectations/Rotations globales. */
$maitreStageRoleCodes=['ENCADREUR','ENCADREUR_CLINIQUE','EVALUATEUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_DE_STAGE_CLINIQUE','MAITRE_STAGE_CLINIQUE'];
$isMaitreStageLike=in_array($roleUpper,$maitreStageRoleCodes,true)||preg_match('/(ENCADREUR|EVALUATEUR.*CLINIQUE|MAITRE.*STAGE|MAÎTRE.*STAGE)/u',$roleUpper);
$prenom=$_SESSION['prenom']??'';
$nom=$_SESSION['nom']??'';
$nomComplet=trim("$prenom $nom");
$initiale=strtoupper(substr($prenom!==''?$prenom:($nom!==''?$nom:'U'),0,1));

$etablissementNom=$_SESSION['etablissement_nom']??'';
$typeNom=$_SESSION['type_etablissement_nom']??'';
$academic=contextAcademicEnabled();
$host=contextHostEnabled();
$academicSettings=$_SESSION['academic_settings']??[];

$isSuper=hasRole('SUPER_ADMIN');
$isMinistry=hasRole('MINISTERE');
$isOrder=hasRole('ORDRE_MEDECINS');
$isNational=$isMinistry||$isOrder;
$isStudent=$role==='STAGIAIRE';

/* Slogan affiché sous le logo dans la sidebar.
   Il utilise le slogan défini dans Paramètres documents si disponible. */
$sidebarSlogan='Stages pour un avenir meilleur';
try{
    $sloganEid=(int)($_SESSION['etablissement_id']??0);
    if($sloganEid>0 && function_exists('stagiaDocumentBrand')){
        $b=stagiaDocumentBrand($pdo,$sloganEid);
        $sidebarSlogan=trim((string)($b['motto']??'')) ?: $sidebarSlogan;
    }
}catch(Throwable $e){}

$hostNewSolicitations=0;$academicNewOffers=0;$academicNewApplications=0;$hostExpectedInterns=0;$hostReadyAssignments=0;$hostResultReadyCount=0;$universityResultCount=0;$studentNewOffers=0;
$stageNotifications=[];$notificationPrimaryHref='#';

if(($notificationEid=(int)($_SESSION['etablissement_id']??0))>0){
    try{
        if($host&&contextPermission('campaign.hosting.respond')){
            $s=$pdo->prepare("SELECT COUNT(*) FROM stage_campaign_participations WHERE host_etablissement_id=? AND statut='SOLLICITEE'");
            $s->execute([$notificationEid]);$hostNewSolicitations=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('H:',p.id) notification_key,
                       CONCAT('Nouvelle sollicitation — ',u.nom) title,
                       CONCAT(c.code,' — ',c.titre) subtitle,
                       CONCAT(st.libelle,' · ',COALESCE(DATE_FORMAT(COALESCE(p.requested_at,p.created_at),'%d/%m/%Y %H:%i'),'')) meta,
                       '".BASE_URL."/views/espace-hopital/sollicitations-d4.php' href,
                       COALESCE(p.requested_at,p.created_at) notification_at
                FROM stage_campaign_participations p
                JOIN stage_campaigns c ON c.id=p.university_campaign_id
                JOIN stage_types st ON st.id=c.stage_type_id
                JOIN etablissements u ON u.id=c.owner_etablissement_id
                WHERE p.host_etablissement_id=? AND p.statut='SOLLICITEE'
                ORDER BY notification_at DESC,p.id DESC LIMIT 6
            ");
            $s->execute([$notificationEid]);$stageNotifications=array_merge($stageNotifications,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($host&&contextPermission(['admission.hosting.view','stage.manage'])){
            $s=$pdo->prepare("SELECT COUNT(*) FROM stage_admissions WHERE host_etablissement_id=? AND statut='ATTENDU'");
            $s->execute([$notificationEid]);$hostExpectedInterns=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('E:',a.id) notification_key,
                       'Nouveau stagiaire attendu' title,
                       CONCAT(
                           COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,app.academic_enrollment_id))),
                           ' · ',c.code
                       ) subtitle,
                       CONCAT(c.titre,' · ',COALESCE(DATE_FORMAT(a.created_at,'%d/%m/%Y %H:%i'),'')) meta,
                       '".BASE_URL."/views/espace-hopital/stagiaires-attendus.php' href,
                       a.created_at notification_at
                FROM stage_admissions a
                JOIN stage_reservations sr ON sr.id=a.reservation_id
                JOIN stage_applications app ON app.id=sr.application_id
                JOIN stage_campaigns c ON c.id=app.campaign_id
                LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
                LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
                LEFT JOIN student_profiles sp ON sp.id=se.student_id
                WHERE a.host_etablissement_id=? AND a.statut='ATTENDU'
                ORDER BY a.created_at DESC,a.id DESC LIMIT 6
            ");
            $s->execute([$notificationEid]);$stageNotifications=array_merge($stageNotifications,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($host&&($roleUpper==='CHEF_SERVICE'||preg_match('/ROLE_CHEF_SERVICE$/',$roleUpper))){
            $readyBase="
                FROM stage_admissions a
                JOIN stage_reservations sr ON sr.id=a.reservation_id
                WHERE a.host_etablissement_id=?
                  AND a.statut IN('ADMIS','EN_COURS')
                  AND sr.statut='CONFIRMEE'
                  AND a.coordination_unit_id IS NOT NULL
                  AND NOT EXISTS(
                      SELECT 1 FROM stage_assignments ass
                      WHERE ass.admission_id=a.id AND ass.statut<>'ANNULEE'
                  )
            ";

            $s=$pdo->prepare("SELECT COUNT(*) ".$readyBase);
            $s->execute([$notificationEid]);$hostReadyAssignments=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('AF:',a.id) notification_key,
                       'Stagiaire prêt à affecter' title,
                       CONCAT(
                           COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,app.academic_enrollment_id))),
                           ' · ',COALESCE(cu.nom,'Coordination')
                       ) subtitle,
                       CONCAT(c.code,' — ',c.titre,' · ',COALESCE(DATE_FORMAT(COALESCE(a.coordination_sent_at,a.created_at),'%d/%m/%Y %H:%i'),'')) meta,
                       '".BASE_URL."/views/espace-hopital/affectations.php' href,
                       COALESCE(a.coordination_sent_at,a.created_at) notification_at
                FROM stage_admissions a
                JOIN stage_reservations sr ON sr.id=a.reservation_id
                JOIN stage_applications app ON app.id=sr.application_id
                JOIN stage_campaigns c ON c.id=app.campaign_id
                LEFT JOIN host_units cu ON cu.id=a.coordination_unit_id
                LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
                LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
                LEFT JOIN student_profiles sp ON sp.id=se.student_id
                WHERE a.host_etablissement_id=?
                  AND a.statut IN('ADMIS','EN_COURS')
                  AND sr.statut='CONFIRMEE'
                  AND a.coordination_unit_id IS NOT NULL
                  AND NOT EXISTS(
                      SELECT 1 FROM stage_assignments ass
                      WHERE ass.admission_id=a.id AND ass.statut<>'ANNULEE'
                  )
                ORDER BY notification_at DESC,a.id DESC LIMIT 6
            ");
            $s->execute([$notificationEid]);$stageNotifications=array_merge($stageNotifications,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($academic&&contextPermission('campaign.university.view')){
            $s=$pdo->prepare("
                SELECT COUNT(*)
                FROM stage_campaign_participations p
                JOIN stage_campaigns c ON c.id=p.university_campaign_id
                WHERE c.owner_etablissement_id=? AND p.statut='ACCEPTEE'
                  AND p.capacite_proposee IS NOT NULL AND p.capacite_acceptee IS NULL
            ");
            $s->execute([$notificationEid]);$academicNewOffers=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('A:',p.id) notification_key,
                       CONCAT('Nouvelle offre — ',h.nom) title,
                       CONCAT(COALESCE(p.capacite_proposee,0),' place(s) proposées · ',c.code) subtitle,
                       CONCAT(st.libelle,' · ',COALESCE(DATE_FORMAT(p.responded_at,'%d/%m/%Y %H:%i'),'')) meta,
                       '".BASE_URL."/views/stages/d4-partenaires.php?campaign_id=',c.id href,
                       COALESCE(p.responded_at,p.created_at) notification_at
                FROM stage_campaign_participations p
                JOIN stage_campaigns c ON c.id=p.university_campaign_id
                JOIN stage_types st ON st.id=c.stage_type_id
                JOIN etablissements h ON h.id=p.host_etablissement_id
                WHERE c.owner_etablissement_id=? AND p.statut='ACCEPTEE'
                  AND p.capacite_proposee IS NOT NULL AND p.capacite_acceptee IS NULL
                ORDER BY notification_at DESC,p.id DESC LIMIT 6
            ");
            $s->execute([$notificationEid]);$stageNotifications=array_merge($stageNotifications,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($academic&&contextPermission(['stage.view','stage.manage'])){
            $s=$pdo->prepare("
                SELECT COUNT(*)
                FROM stage_applications a
                JOIN stage_campaigns c ON c.id=a.campaign_id
                WHERE c.owner_etablissement_id=? AND a.statut='SOUMISE'
            ");
            $s->execute([$notificationEid]);$academicNewApplications=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('C:',a.id) notification_key,
                       'Nouvelle candidature' title,
                       CONCAT(
                           COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,a.academic_enrollment_id))),
                           ' · ',COALESCE(h.nom,'Hôpital')
                       ) subtitle,
                       CONCAT(c.code,' — ',c.titre,' · ',COALESCE(DATE_FORMAT(COALESCE(a.submitted_at,a.created_at),'%d/%m/%Y %H:%i'),'')) meta,
                       '".BASE_URL."/views/stages/candidatures.php' href,
                       COALESCE(a.submitted_at,a.created_at) notification_at
                FROM stage_applications a
                JOIN stage_campaigns c ON c.id=a.campaign_id
                LEFT JOIN etablissements h ON h.id=a.host_etablissement_id
                LEFT JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
                LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
                LEFT JOIN student_profiles sp ON sp.id=se.student_id
                WHERE c.owner_etablissement_id=? AND a.statut='SOUMISE'
                ORDER BY notification_at DESC,a.id DESC LIMIT 6
            ");
            $s->execute([$notificationEid]);$stageNotifications=array_merge($stageNotifications,$s->fetchAll(PDO::FETCH_ASSOC));
        }


        /* Résultats de stage prêts à transmettre côté hôpital. */
        if($host&&contextPermission('stage.manage')){
            try{
                $s=$pdo->prepare("
                    SELECT COUNT(DISTINCT c.id)
                    FROM stage_evaluations ev
                    JOIN stage_rotations r ON r.id=ev.rotation_id
                    JOIN stage_assignments a ON a.id=r.assignment_id
                    JOIN stage_admissions ad ON ad.id=a.admission_id
                    JOIN stage_reservations sr ON sr.id=ad.reservation_id
                    JOIN stage_applications app ON app.id=sr.application_id
                    JOIN stage_campaigns c ON c.id=app.campaign_id
                    WHERE r.host_etablissement_id=?
                      AND ev.statut IN('VALIDEE','FINALISEE')
                      AND NOT EXISTS(
                          SELECT 1
                          FROM stage_result_transmissions t
                          WHERE t.campaign_id=c.id
                            AND t.host_etablissement_id=?
                            AND t.statut IN('ENVOYE','RECU','VALIDE','ARCHIVE')
                      )
                ");
                $s->execute([$notificationEid,$notificationEid]);
                $hostResultReadyCount=(int)$s->fetchColumn();

                if($hostResultReadyCount>0){
                    $s=$pdo->prepare("
                        SELECT DISTINCT CONCAT('HR:',c.id) notification_key,
                               'Résultats de stage prêts' title,
                               CONCAT(c.code,' — ',c.titre) subtitle,
                               CONCAT('À transmettre à ',COALESCE(u.nom,'Université')) meta,
                               '".BASE_URL."/views/espace-hopital/resultats-stage.php' href,
                               MAX(COALESCE(ev.validated_at,ev.finalized_at,ev.updated_at,ev.created_at)) notification_at
                        FROM stage_evaluations ev
                        JOIN stage_rotations r ON r.id=ev.rotation_id
                        JOIN stage_assignments a ON a.id=r.assignment_id
                        JOIN stage_admissions ad ON ad.id=a.admission_id
                        JOIN stage_reservations sr ON sr.id=ad.reservation_id
                        JOIN stage_applications app ON app.id=sr.application_id
                        JOIN stage_campaigns c ON c.id=app.campaign_id
                        LEFT JOIN etablissements u ON u.id=c.owner_etablissement_id
                        WHERE r.host_etablissement_id=?
                          AND ev.statut IN('VALIDEE','FINALISEE')
                          AND NOT EXISTS(
                              SELECT 1
                              FROM stage_result_transmissions t
                              WHERE t.campaign_id=c.id
                                AND t.host_etablissement_id=?
                                AND t.statut IN('ENVOYE','RECU','VALIDE','ARCHIVE')
                          )
                        GROUP BY c.id,c.code,c.titre,u.nom
                        ORDER BY notification_at DESC,c.id DESC
                        LIMIT 6
                    ");
                    $s->execute([$notificationEid,$notificationEid]);
                    $stageNotifications=array_merge($stageNotifications,$s->fetchAll(PDO::FETCH_ASSOC));
                }
            }catch(Throwable $e){error_log('[HEADER RESULT HOST NOTIFICATIONS] '.$e->getMessage());}
        }

        /* Résultats transmis à recevoir côté université. */
        if($academic&&contextPermission(['stage.view','stage.manage','campaign.university.view'])){
            try{
                $s=$pdo->prepare("SELECT COUNT(*) FROM stage_result_transmissions WHERE university_etablissement_id=? AND statut='ENVOYE'");
                $s->execute([$notificationEid]);
                $universityResultCount=(int)$s->fetchColumn();

                $s=$pdo->prepare("
                    SELECT CONCAT('UR:',t.id) notification_key,
                           'Résultats de stage reçus' title,
                           CONCAT(COALESCE(h.nom,'Hôpital'),' · ',c.code,' — ',c.titre) subtitle,
                           CONCAT('Envoyé le ',COALESCE(DATE_FORMAT(t.transmitted_at,'%d/%m/%Y %H:%i'),'')) meta,
                           '".BASE_URL."/views/espace-etablissement/resultats-stage.php' href,
                           COALESCE(t.transmitted_at,t.created_at) notification_at
                    FROM stage_result_transmissions t
                    JOIN stage_campaigns c ON c.id=t.campaign_id
                    LEFT JOIN etablissements h ON h.id=t.host_etablissement_id
                    WHERE t.university_etablissement_id=?
                      AND t.statut='ENVOYE'
                    ORDER BY notification_at DESC,t.id DESC
                    LIMIT 6
                ");
                $s->execute([$notificationEid]);
                $stageNotifications=array_merge($stageNotifications,$s->fetchAll(PDO::FETCH_ASSOC));
            }catch(Throwable $e){error_log('[HEADER RESULT UNIVERSITY NOTIFICATIONS] '.$e->getMessage());}
        }

        usort($stageNotifications,fn($a,$b)=>strcmp((string)$b['notification_at'],(string)$a['notification_at']));
        $stageNotifications=array_slice($stageNotifications,0,8);
    }catch(Throwable $e){error_log('[HEADER NOTIFICATIONS] '.$e->getMessage());}
}


/* =========================================================
   NOTIFICATIONS ÉTUDIANT — OFFRES DE STAGE ÉLIGIBLES
   ---------------------------------------------------------
   Même logique métier que student-stage-options.php :
   - inscription établissement ACTIF
   - inscription académique EN_COURS
   - même établissement / année / promotion
   - campagne UNIVERSITAIRE OUVERTE
   - type de stage actif
   - au moins un hôpital ACCEPTE avec capacité retenue > 0
   - aucune réservation/admission/stage/clôture déjà engagée
========================================================= */
if($isStudent){
    try{
        $s=$pdo->prepare("
            SELECT id
            FROM student_profiles
            WHERE user_id=?
              AND statut='ACTIF'
            LIMIT 1
        ");
        $s->execute([(int)($_SESSION['user_id']??0)]);
        $studentNotificationId=(int)$s->fetchColumn();

        if($studentNotificationId){
            $studentEligibility="
                FROM student_enrollments se
                JOIN student_academic_enrollments ae
                  ON ae.enrollment_id=se.id
                 AND ae.statut='EN_COURS'
                JOIN stage_campaigns c
                  ON c.owner_etablissement_id=se.etablissement_id
                 AND c.annee_academique_id=ae.annee_academique_id
                 AND c.type_campagne='UNIVERSITAIRE'
                 AND c.statut='OUVERTE'
                JOIN stage_types st
                  ON st.id=c.stage_type_id
                 AND st.actif=1
                JOIN stage_campaign_promotions cp
                  ON cp.campaign_id=c.id
                 AND cp.promotion_id=ae.promotion_id
                WHERE se.student_id=?
                  AND se.statut='ACTIF'
                  AND EXISTS(
                      SELECT 1
                      FROM stage_campaign_participations sp
                      JOIN etablissements h ON h.id=sp.host_etablissement_id
                      WHERE sp.university_campaign_id=c.id
                        AND sp.statut='ACCEPTEE'
                        AND COALESCE(NULLIF(sp.capacite_acceptee,0),NULLIF(sp.capacite_allouee,0),0)>0
                        AND h.type_etablissement='HOPITAL'
                        AND h.statut IN('VALIDE','ACTIF')
                  )
                  AND NOT EXISTS(
                      SELECT 1
                      FROM stage_applications sa
                      LEFT JOIN stage_reservations sr ON sr.application_id=sa.id
                      LEFT JOIN stage_admissions ad ON ad.reservation_id=sr.id
                      LEFT JOIN stage_assignments ass ON ass.admission_id=ad.id
                      WHERE sa.campaign_id=c.id
                        AND sa.academic_enrollment_id=ae.id
                        AND (
                            (
                                sr.statut='RESERVEE_TEMPORAIREMENT'
                                AND (sr.expires_at IS NULL OR sr.expires_at>NOW())
                            )
                            OR sr.statut='EN_ATTENTE_PAIEMENT'
                            OR sr.statut='CONFIRMEE'
                            OR ad.id IS NOT NULL
                            OR (ass.id IS NOT NULL AND ass.statut<>'ANNULEE')
                        )
                  )
                  AND NOT EXISTS(
                      SELECT 1
                      FROM stage_completions sc
                      WHERE sc.student_id=se.student_id
                        AND sc.campaign_id=c.id
                        AND sc.statut IN('EN_PREPARATION','PRET','VALIDE')
                  )
            ";

            $s=$pdo->prepare("SELECT COUNT(DISTINCT c.id) ".$studentEligibility);
            $s->execute([$studentNotificationId]);
            $studentNewOffers=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT DISTINCT
                    c.id,
                    c.code,
                    c.titre,
                    c.date_debut notification_at,
                    st.libelle
                ".$studentEligibility."
                ORDER BY c.date_debut DESC,c.id DESC
                LIMIT 8
            ");
            $s->execute([$studentNotificationId]);

            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $x){
                $stageNotifications[]=[
                    'notification_key'=>'S:'.$x['id'],
                    'title'=>'Nouvelle offre de stage disponible',
                    'subtitle'=>$x['code'].' — '.$x['titre'],
                    'meta'=>$x['libelle'].' · Début '.date('d/m/Y',strtotime($x['notification_at'])),
                    'href'=>BASE_URL.'/views/espace-etudiant/stages.php',
                    'notification_at'=>$x['notification_at']
                ];
            }

            usort(
                $stageNotifications,
                fn($a,$b)=>strcmp((string)$b['notification_at'],(string)$a['notification_at'])
            );
            $stageNotifications=array_slice($stageNotifications,0,8);
        }
    }catch(Throwable $e){
        error_log('[HEADER STUDENT NOTIFICATIONS] '.$e->getMessage());
    }
}

$stageNotificationCount=$hostNewSolicitations+$academicNewOffers+$academicNewApplications+$hostExpectedInterns+$hostReadyAssignments+$hostResultReadyCount+$universityResultCount+$studentNewOffers;

if($isStudent)$notificationPrimaryHref=BASE_URL.'/views/espace-etudiant/stages.php';
elseif($universityResultCount>0)$notificationPrimaryHref=BASE_URL.'/views/espace-etablissement/resultats-stage.php';
elseif($academicNewApplications>0)$notificationPrimaryHref=BASE_URL.'/views/stages/candidatures.php';
elseif($academicNewOffers>0)$notificationPrimaryHref=BASE_URL.'/views/stages/d4-partenaires.php';
elseif($hostResultReadyCount>0)$notificationPrimaryHref=BASE_URL.'/views/espace-hopital/resultats-stage.php';
elseif($hostReadyAssignments>0)$notificationPrimaryHref=BASE_URL.'/views/espace-hopital/affectations.php';
elseif($hostExpectedInterns>0)$notificationPrimaryHref=BASE_URL.'/views/espace-hopital/stagiaires-attendus.php';
elseif($hostNewSolicitations>0)$notificationPrimaryHref=BASE_URL.'/views/espace-hopital/sollicitations-d4.php';
elseif($academic)$notificationPrimaryHref=BASE_URL.'/views/stages/candidatures.php';
elseif($host)$notificationPrimaryHref=BASE_URL.'/views/espace-hopital/sollicitations-d4.php';

$spaceName=match(true){
    $isSuper=>'Administration nationale',
    $isMinistry=>'Ministère de la Santé',
    $isOrder=>'Ordre des Médecins',
    $isStudent=>'Espace stagiaire',
    $academic && $host=>'Espace mixte',
    $host=>'Espace accueil',
    $academic=>'Espace établissement',
    default=>'STAGIA-RDC'
};

if(!$isSuper && !$isNational && !$isStudent && $etablissementNom!=='')
    $spaceName.=' — '.$etablissementNom;

/* Titre court affiché à côté du bouton menu.
   - Étudiant : on affiche l'espace stagiaire, pas le nom de l'université.
   - Admin / hôpital / université : on affiche le nom de l'établissement. */
if($isStudent){
    $topbarSpaceTitle='Espace stagiaire';
}elseif($isSuper || $isNational){
    $topbarSpaceTitle=$spaceName;
}else{
    $topbarSpaceTitle=trim((string)($etablissementNom?:$spaceName));
}
if($topbarSpaceTitle==='')$topbarSpaceTitle='STAGIA-RDC';

/* La navigation académique vient exclusivement du modèle STAGIA appliqué
   à l'établissement. En absence de configuration explicite, on n'active
   aucun niveau par défaut. */
$unitEnabled=$academic && (bool)($academicSettings['unite_academique_active']??false);
$departmentEnabled=$academic && (bool)($academicSettings['departement_active']??false);
$programEnabled=$academic && (bool)($academicSettings['filiere_active']??false);
$optionEnabled=$academic && (bool)($academicSettings['option_specialite_active']??false);
$promotionEnabled=$academic && (bool)($academicSettings['promotion_active']??false);

$academicUnitTypes=[];$academicUnitCodes=[];$unitMenuLabel='Unités académiques';
if($unitEnabled&&($academicEid=(int)($_SESSION['etablissement_id']??0))>0){
    try{
        $academicUnitTypes=academicUnitTypes($pdo,$academicEid);
        foreach($academicUnitTypes as $u){
            $code=strtoupper(trim((string)($u['type_unite']??'')));
            if($code!=='')$academicUnitCodes[$code]=(string)($u['libelle']??$code);
        }
        if(!$academicUnitCodes)$unitEnabled=false;
        elseif(count($academicUnitCodes)===1){
            $code=array_key_first($academicUnitCodes);
            $unitMenuLabel=match($code){
                'FACULTE'=>'Facultés','SECTION'=>'Sections','ECOLE'=>'Écoles',
                'INSTITUT'=>'Instituts','CENTRE'=>'Centres',default=>'Unités académiques'
            };
        }
    }catch(Throwable $e){
        error_log('[ACADEMIC MENU] '.$e->getMessage());
        $unitEnabled=false;
    }
}

$academicPages=['annees','facultes','departements','filieres','promotions','matieres'];
$studentPages=['etudiants','etudiant-create','etudiant-import'];

$stagePages=[
    'stage-types',
    'training-plans',
    'stage-conventions',
    'stage-documents-models',
    'stage-documents-archives',
    'stages-campagnes',
    'stages-d4-partenaires',
    'stages-d4-placements',
    'stages-demandes',
    'stages-candidatures',
    'university-results',
    'stages-affectations',
    'stages-suivi',
    'hospital-campaigns',
    'hospital-solicitations',
    'hospital-capacities',
    'admin-stage-supervision'
];

$adminPages=[
    'admin-utilisateurs',
    'utilisateurs',
    'hopital-utilisateurs',
    'admin-roles',
    'admin-permissions',
    'admin-affectations',
    'admin-statistiques',
    'admin-parametres'
];

$documentAdminPages=[
    'document-settings',
    'stage-documents-models',
    'stage-documents-archives'
];

/* Pages du sous-menu Suivi côté structure d’accueil.
   Le sous-menu reste ouvert quand l'utilisateur consulte l'une de ces pages. */
$hostSuiviPages=[
    'chef-service-stagiaires',
    'encadreur-mes-rotations',
    'hospital-logbook',
    'hopital-presences',
    'hospital-tasks',
    'hospital-feedbacks',
    'hopital-evaluations',
    'hospital-results'
];

$superGestionPages=['etablissements','adhesions','stagiaires'];

$configPages=[
    'config-modeles-academiques',
    'config-modele-structure',
    'config-types-etablissements',
    'config-types-unites'
];

$quickAcademicPages=[
    'config-quick-units',
    'config-quick-departments',
    'config-quick-programs',
    'config-quick-options',
    'config-quick-promotions'
];

$hostTemplatePages=['config-host-services'];

$studentStagePages=[
    'student-stages',
    'student-applications',
    'student-reservations',
    'student-internships',
    'student-logbook',
    'my-payments'
];

function stagiaMenuTitle(string $label):void{
    echo '<div class="menu-title">'.htmlspecialchars($label).'</div>';
}

function stagiaNav(
    string $href,
    string $icon,
    string $label,
    string $page,
    string $activePage,
    int $badge=0
):void{
    /* Page active dans la sidebar. */
    $active=$page===$activePage?'active':'';

    /* Pages dont le badge peut être mis à jour en temps réel par JavaScript. */
    $livePages=[
        'hospital-solicitations',
        'hospital-stagiaires',
        'hospital-affectations',
        'chef-service-stagiaires',
        'encadreur-mes-rotations',
        'hospital-logbook',
        'hopital-presences',
        'hospital-tasks',
        'hospital-feedbacks',
        'hopital-evaluations',
        'hospital-results',
        'stages-d4-partenaires',
        'stages-candidatures',
        'university-results',
        'student-stages'
    ];

    $live=in_array($page,$livePages,true);

    /*
       Badge sidebar :
       - pour les pages live, le span existe même à zéro afin que l'Ajax puisse le remplir ;
       - pour les autres pages, le badge n'apparaît que si la valeur est > 0.
    */
    if($live){
        $badgeHtml='<span class="sidebar-notification-badge '.($badge>0?'':'d-none').'" data-stage-badge="'.htmlspecialchars($page).'">'.($badge>99?'99+':$badge).'</span>';
    }else{
        $badgeHtml=$badge>0?'<span class="sidebar-notification-badge">'.($badge>99?'99+':$badge).'</span>':'';
    }

    echo '<a href="'.htmlspecialchars($href).'" class="'.$active.'"><i class="bi '.htmlspecialchars($icon).'"></i><span>'.htmlspecialchars($label).'</span>'.$badgeHtml.'</a>';
}

function stagiaSubmenu(
    string $id,
    string $icon,
    string $label,
    array $pages,
    string $activePage,
    array $items
):void{
    if(!$items)return;

    $open=in_array($activePage,$pages,true);

    echo '<button type="button"
        class="sidebar-parent '.($open?'active-parent':'').'"
        data-bs-toggle="collapse"
        data-bs-target="#'.$id.'"
        aria-expanded="'.($open?'true':'false').'">

        <i class="bi '.htmlspecialchars($icon).'"></i>
        <span>'.htmlspecialchars($label).'</span>
        <i class="bi bi-chevron-down submenu-arrow ms-auto"></i>
    </button>

    <div id="'.$id.'"
        class="collapse submenu '.($open?'show':'').'"
        data-bs-parent="#sidebarMenu">';

    foreach($items as $i)
        stagiaNav($i[0],$i[1],$i[2],$i[3],$activePage,(int)($i[4]??0));

    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">

<title><?= htmlspecialchars($pageTitle) ?> | STAGIA-RDC</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

<link rel="stylesheet"
      href="<?= BASE_URL ?>/assets/css/style.css?v=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>">
<?php if($activePage==='communication'): ?>
<link rel="stylesheet"
      href="<?= BASE_URL ?>/assets/css/communication.css?v=<?= filemtime(__DIR__.'/../assets/css/communication.css') ?>">
<?php endif; ?>


<style>
.sidebar-notification-badge{margin-left:auto;min-width:20px;height:20px;padding:0 6px;border-radius:20px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:inline-flex;align-items:center;justify-content:center}.notification-bell{position:relative}.notification-count{position:absolute;top:-6px;right:-7px;min-width:18px;height:18px;padding:0 5px;border-radius:12px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #0b0b0b}.notification-menu{width:360px;max-width:calc(100vw - 24px);padding:0;overflow:hidden}.notification-head{padding:12px 14px;border-bottom:1px solid #e5e7eb;font-weight:700}.notification-item{display:block;padding:11px 14px;border-bottom:1px solid #f1f5f9;color:#0f172a;text-decoration:none}.notification-item:hover{background:#fff7ed}.notification-item strong{display:block;font-size:13px}.notification-item small{display:block;color:#64748b;margin-top:2px}.notification-footer{display:block;padding:10px 14px;text-align:center;text-decoration:none;font-size:12px;font-weight:600}
.sidebar-slogan{display:block;margin-top:6px;padding:0 14px;color:rgba(255,255,255,.88);font-size:11px;line-height:1.35;font-weight:500}.topbar-space-title{margin-left:14px;color:#fff;font-size:15px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:50vw}.sidebar-toggle i{transition:.15s ease}.sidebar-backdrop{display:none}@media(max-width:991.98px){body.sidebar-open{overflow:hidden}.sidebar{z-index:1040}.sidebar-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.52);z-index:1030;opacity:0;pointer-events:none;display:block;transition:.2s ease}.app-body.sidebar-open .sidebar-backdrop{opacity:1;pointer-events:auto}.topbar-space-title{font-size:13px;max-width:58vw}.sidebar-toggle{width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center}}
.sidebar-close-mobile{
    display:none;
}

.sidebar-mobile-overlay{
    display:none;
}

@media(max-width:991.98px){
    .sidebar-close-mobile{
        display:flex;
        position:absolute;
        top:14px;
        right:14px;
        width:34px;
        height:34px;
        border:0;
        border-radius:10px;
        align-items:center;
        justify-content:center;
        background:rgba(255,255,255,.14);
        color:#fff;
        font-size:18px;
        z-index:1101;
    }

    .sidebar-close-mobile:hover{
        background:rgba(255,255,255,.22);
    }

    .sidebar-mobile-overlay{
        display:none;
        position:fixed;
        inset:0;
        background:rgba(15,23,42,.55);
        z-index:1040;
    }

    body.sidebar-open-mobile .sidebar-mobile-overlay{
        display:block;
    }

    body.sidebar-open-mobile .sidebar{
        transform:translateX(0);
        z-index:1050;
    }

    body.sidebar-open-mobile{
        overflow:hidden;
    }
}
</style>

</head>

<body class="app-body">
<script>window.STAGIA_BASE_URL=<?=json_encode(BASE_URL,JSON_UNESCAPED_SLASHES)?>;</script>

<aside class="sidebar" id="sidebar">

<div class="sidebar-brand">
    <button class="sidebar-close-mobile" id="sidebarCloseMobile" type="button" aria-label="Fermer le menu">
    <i class="bi bi-x-lg"></i>
</button>

    <a href="<?= BASE_URL ?>/dashboard.php" class="sidebar-brand-link">
        <img src="<?= BASE_URL ?>/assets/img/logo.png"
             class="sidebar-logo"
             alt="Logo STAGIA-RDC">
    </a>

    <small class="sidebar-slogan">
        <?= htmlspecialchars($sidebarSlogan) ?>
    </small>

</div>

<nav class="sidebar-menu" id="sidebarMenu">

<?php if($isSuper): ?>

<?php
stagiaNav(
    BASE_URL.'/views/admin/dashboard.php',
    'bi-grid',
    'Tableau de bord',
    'dashboard',
    $activePage
);

stagiaMenuTitle('GESTION');

stagiaSubmenu(
    'submenuGestion',
    'bi-folder2-open',
    'Gestion générale',
    $superGestionPages,
    $activePage,
    [
        [
            BASE_URL.'/views/etablissements/index.php',
            'bi-buildings',
            'Établissements',
            'etablissements'
        ],
        [
            BASE_URL.'/views/adhesions/index.php',
            'bi-inbox',
            'Demandes d\'adhésion',
            'adhesions'
        ],
        [
            '#',
            'bi-people',
            'Stagiaires',
            'stagiaires'
        ]
    ]
);

stagiaMenuTitle('STAGES');

stagiaNav(
    BASE_URL.'/views/admin/stages-supervision.php',
    'bi-eye',
    'Supervision des stages',
    'admin-stage-supervision',
    $activePage
);

stagiaMenuTitle('ADMINISTRATION');

stagiaSubmenu(
    'submenuSysteme',
    'bi-shield-lock',
    'Administration',
    $adminPages,
    $activePage,
    [
        [
            BASE_URL.'/views/admin/utilisateurs/index.php',
            'bi-people',
            'Utilisateurs',
            'admin-utilisateurs'
        ],
        [
            BASE_URL.'/views/admin/roles/index.php',
            'bi-person-badge',
            'Rôles',
            'admin-roles'
        ],
        [
            BASE_URL.'/views/admin/permissions/index.php',
            'bi-shield-check',
            'Permissions',
            'admin-permissions'
        ],
        [
            BASE_URL.'/views/admin/affectations/index.php',
            'bi-person-check',
            'Affectations de rôles',
            'admin-affectations'
        ],
        [
            BASE_URL.'/views/admin/statistiques/index.php',
            'bi-bar-chart',
            'Statistiques',
            'admin-statistiques'
        ],
        [
            BASE_URL.'/views/admin/parametres/index.php',
            'bi-sliders',
            'Paramètres',
            'admin-parametres'
        ]
    ]
);

stagiaMenuTitle('CONFIGURATION');

stagiaSubmenu(
    'submenuConfiguration',
    'bi-sliders2',
    'Configuration STAGIA',
    $configPages,
    $activePage,
    [
        [
            BASE_URL.'/views/configuration/modeles-academiques.php',
            'bi-diagram-3',
            'Structures académiques par type',
            'config-modeles-academiques'
        ],
        [
            BASE_URL.'/views/configuration/types-etablissements.php',
            'bi-buildings',
            'Types d\'établissements',
            'config-types-etablissements'
        ],
        [
            BASE_URL.'/views/configuration/types-unites.php',
            'bi-diagram-2',
            'Types d\'unités académiques',
            'config-types-unites'
        ]
    ]
);

stagiaSubmenu(
    'submenuReferentielAcademique',
    'bi-mortarboard',
    'Référentiel académique',
    $quickAcademicPages,
    $activePage,
    [
        [
            BASE_URL.'/views/configuration/referentiel-academique.php?entity=UNIT',
            'bi-buildings',
            'Unités / Facultés',
            'config-quick-units'
        ],
        [
            BASE_URL.'/views/configuration/referentiel-academique.php?entity=DEPARTMENT',
            'bi-diagram-2',
            'Départements',
            'config-quick-departments'
        ],
        [
            BASE_URL.'/views/configuration/referentiel-academique.php?entity=PROGRAM',
            'bi-mortarboard',
            'Filières / Programmes',
            'config-quick-programs'
        ],
        [
            BASE_URL.'/views/configuration/referentiel-academique.php?entity=OPTION',
            'bi-signpost-split',
            'Options / Spécialités',
            'config-quick-options'
        ],
        [
            BASE_URL.'/views/configuration/referentiel-academique.php?entity=PROMOTION',
            'bi-layers',
            'Promotions / Niveaux',
            'config-quick-promotions'
        ]
    ]
);

stagiaSubmenu(
    'submenuStructuresAccueil',
    'bi-hospital',
    'Structures d\'accueil',
    $hostTemplatePages,
    $activePage,
    [
        [
            BASE_URL.'/views/configuration/services-unites-types.php',
            'bi-diagram-3',
            'Services / Unités',
            'config-host-services'
        ]
    ]
);
?>

<?php elseif($isNational): ?>

<?php
$nationalLabel=$isOrder?'SUPERVISION MÉDICALE':'PILOTAGE NATIONAL';

stagiaNav(
    BASE_URL.'/views/national/dashboard.php',
    'bi-speedometer2',
    'Tableau de bord',
    'national-dashboard',
    $activePage
);

stagiaMenuTitle($nationalLabel);

stagiaNav(
    BASE_URL.'/views/national/dashboard.php#repartition',
    'bi-buildings',
    'Établissements',
    'national-establishments',
    $activePage
);

stagiaNav(
    BASE_URL.'/views/national/dashboard.php#repartition',
    'bi-people',
    'Effectifs étudiants',
    'national-students',
    $activePage
);

stagiaNav(
    BASE_URL.'/views/national/dashboard.php#annees',
    'bi-calendar3',
    'Par année académique',
    'national-years',
    $activePage
);

stagiaMenuTitle('RAPPORTS');

stagiaNav(
    BASE_URL.'/views/admin/statistiques/index.php',
    'bi-bar-chart-line',
    'Rapports & statistiques',
    'admin-statistiques',
    $activePage
);

if(contextPermission('analytics.export')){
    stagiaNav(
        BASE_URL.'/actions/national/export-etudiants.php',
        'bi-file-earmark-spreadsheet',
        'Exporter les agrégats',
        'national-export',
        $activePage
    );
}
?>

<?php elseif($isStudent): ?>

<?php
stagiaNav(
    BASE_URL.'/views/espace-etudiant/dashboard.php',
    'bi-grid',
    'Tableau de bord',
    'student-dashboard',
    $activePage
);

stagiaMenuTitle('MON DOSSIER');

stagiaNav(
    BASE_URL.'/views/espace-etudiant/profil.php',
    'bi-person',
    'Mon profil',
    'student-profile',
    $activePage
);

stagiaNav(
    BASE_URL.'/views/espace-etudiant/parcours.php',
    'bi-mortarboard',
    'Mon parcours',
    'student-academic',
    $activePage
);

stagiaNav(
    BASE_URL.'/views/espace-etudiant/documents.php',
    'bi-file-earmark-text',
    'Mes documents',
    'student-documents',
    $activePage
);

stagiaNav(
    BASE_URL.'/views/espace-etudiant/notes.php',
    'bi-journal-check',
    'Mes notes',
    'student-notes',
    $activePage
);

/* Menus étudiant toujours visibles : un nouveau stagiaire peut accéder aux modules
   même si ses données académiques ou permissions fines ne sont pas encore complètes. */
if($isStudent){

    stagiaMenuTitle('STAGES');

    stagiaSubmenu(
        'submenuStudentStages',
        'bi-briefcase',
        'Mes stages',
        $studentStagePages,
        $activePage,
        [
            [
                BASE_URL.'/views/espace-etudiant/stages.php',
                'bi-megaphone',
                'Offres de stage',
                'student-stages',
                $studentNewOffers
            ],
            [
                BASE_URL.'/views/espace-etudiant/candidatures.php',
                'bi-send',
                'Mes candidatures',
                'student-applications'
            ],
            [
                BASE_URL.'/views/espace-etudiant/reservations.php',
                'bi-calendar-check',
                'Mes réservations',
                'student-reservations'
            ],
            [
                BASE_URL.'/views/espace-etudiant/mes-stages.php',
                'bi-briefcase-fill',
                'Mes stages',
                'student-internships'
            ],
            [
                BASE_URL.'/views/espace-etudiant/journal-stage.php',
                'bi-journal-medical',
                'Mon journal de stage',
                'student-logbook'
            ],
            [
                BASE_URL.'/views/paiements/index.php',
                'bi-credit-card',
                'Mes paiements',
                'my-payments'
            ]
        ]
    );

    stagiaMenuTitle('SUIVI');

    stagiaNav(
        BASE_URL.'/views/espace-etudiant/presences.php',
        'bi-calendar2-check',
        'Mes présences',
        'student-attendance',
        $activePage
    );

    /* Progression individuelle issue des tâches assignées par l'encadreur. */
    stagiaNav(
        BASE_URL.'/views/espace-etudiant/taches.php',
        'bi-list-check',
        'Mes tâches',
        'student-tasks',
        $activePage
    );

    /* Retours publiés par les encadreurs et explicitement visibles au stagiaire. */
    stagiaNav(
        BASE_URL.'/views/espace-etudiant/feedbacks.php',
        'bi-chat-left-text',
        'Mes retours',
        'student-feedbacks',
        $activePage
    );

    stagiaNav(
        BASE_URL.'/views/espace-etudiant/evaluations.php',
        'bi-clipboard-check',
        'Mes évaluations',
        'student-evaluations',
        $activePage
    );
}
?>

<?php else: ?>

<?php
/* =========================================================
   ESPACE HÔPITAL PUR — RBAC PAR PROFIL
   CHEF_SERVICE est autonome : il reçoit les stagiaires de son service,
   organise les rotations et choisit les encadreurs / maîtres de stage.
========================================================= */
if($host && !$academic){

    /* =========================================================
       RÔLES HÔPITAL — espace accueil pur
       ---------------------------------------------------------
       CHEF_SERVICE est autonome.
       ENCADREUR / EVALUATEUR_CLINIQUE voient uniquement leurs rotations.
    ========================================================= */
    $hAdmin=$roleUpper==='ADMIN_ACCUEIL';
    $hCoord=$roleUpper==='COORDINATEUR_STAGES';
    $hChef=$roleUpper==='CHEF_SERVICE'||preg_match('/ROLE_CHEF_SERVICE$/',$roleUpper);
    $hEnc=(bool)$isMaitreStageLike;
    $hPoint=$roleUpper==='POINTEUR';
    $hFinance=$roleUpper==='GESTIONNAIRE_FINANCIER_HOSPITALIER';
    $hAuthority=$roleUpper==='AUTORITE_HOSPITALIERE';

    $eidHeader=(int)($_SESSION['etablissement_id']??0);
    $uidHeader=(int)($_SESSION['user_id']??0);

    /* Badge Chef de service : stagiaires reçus dans son périmètre. */
    $chefReceivedCount=$hChef
        ?chefServiceStagiaireCount($pdo,$eidHeader,$uidHeader,false)
        :0;

    /* Badge Encadreur : rotations planifiées ou actives qui lui sont confiées. */
    $encadreurRotationsCount=0;

    if($hEnc&&$eidHeader&&$uidHeader){
        try{
            $s=$pdo->prepare("
                SELECT COUNT(DISTINCT r.id)
                FROM stage_rotations r
                JOIN stage_rotation_supervisors rs
                  ON rs.rotation_id=r.id
                 AND rs.user_id=?
                 AND rs.actif=1
                WHERE r.host_etablissement_id=?
                  AND r.statut IN('PLANIFIEE','ACTIVE')
            ");
            $s->execute([$uidHeader,$eidHeader]);
            $encadreurRotationsCount=(int)$s->fetchColumn();
        }catch(Throwable $e){
            error_log('[HEADER ENCADREUR ROTATIONS COUNT] '.$e->getMessage());
            $encadreurRotationsCount=0;
        }
    }

    /* =========================================================
       BADGES SUIVI CLINIQUE
       ---------------------------------------------------------
       Ces compteurs alimentent les badges rouges du sous-menu Suivi.
       Ils restent protégés par try/catch afin de ne jamais casser
       l'affichage du header si une table optionnelle évolue.
    ========================================================= */
    $chefEvaluationCount=0;
    $encadreurEvaluationCount=0;
    $clinicalLogbookCount=0;
    $clinicalAttendanceCount=0;
    $clinicalTaskCount=0;
    $clinicalFeedbackCount=0;

    if($eidHeader&&$uidHeader){
        if($hChef){
            try{
                $scopeHeader=chefServiceScope($pdo,$eidHeader,$uidHeader);

                $p=[$eidHeader];
                $w=chefServiceWhere('a',$scopeHeader,$p);
                $s=$pdo->prepare("
                    SELECT COUNT(DISTINCT e.id)
                    FROM stage_evaluations e
                    JOIN stage_rotations r ON r.id=e.rotation_id
                    JOIN stage_assignments a ON a.id=r.assignment_id
                    WHERE a.host_etablissement_id=? $w
                      AND e.statut='SOUMISE'
                ");
                $s->execute($p);
                $chefEvaluationCount=(int)$s->fetchColumn();

                $p=[$eidHeader];
                $w=chefServiceWhere('a',$scopeHeader,$p);
                $s=$pdo->prepare("
                    SELECT COUNT(DISTINCT le.id)
                    FROM stage_logbook_entries le
                    JOIN stage_rotations r ON r.id=le.rotation_id
                    JOIN stage_assignments a ON a.id=r.assignment_id
                    WHERE a.host_etablissement_id=? $w
                      AND le.statut='SOUMIS'
                ");
                $s->execute($p);
                $clinicalLogbookCount=(int)$s->fetchColumn();

                $todayHeader=date('Y-m-d');
                $p=[$eidHeader];
                $w=chefServiceWhere('a',$scopeHeader,$p);
                $s=$pdo->prepare("
                    SELECT COUNT(DISTINCT r.id)
                    FROM stage_rotations r
                    JOIN stage_assignments a ON a.id=r.assignment_id
                    WHERE a.host_etablissement_id=? $w
                      AND r.statut='ACTIVE'
                      AND ? BETWEEN r.date_debut AND r.date_fin
                      AND NOT EXISTS(
                          SELECT 1
                          FROM stage_attendances att
                          WHERE att.rotation_id=r.id
                            AND att.date_presence=?
                      )
                ");
                $p[]=$todayHeader;
                $p[]=$todayHeader;
                $s->execute($p);
                $clinicalAttendanceCount=(int)$s->fetchColumn();
            }catch(Throwable $e){
                error_log('[HEADER CHEF SUIVI COUNT] '.$e->getMessage());
                $chefEvaluationCount=0;
                $clinicalLogbookCount=0;
                $clinicalAttendanceCount=0;
            }
        }

        if($hEnc){
            /* Badge Encadreur : évaluations retournées / brouillons à corriger. */
            try{
                $s=$pdo->prepare("
                    SELECT COUNT(DISTINCT e.id)
                    FROM stage_evaluations e
                    JOIN stage_rotations r ON r.id=e.rotation_id
                    JOIN stage_rotation_supervisors rs
                      ON rs.rotation_id=r.id
                     AND rs.user_id=?
                     AND rs.actif=1
                    WHERE r.host_etablissement_id=?
                      AND e.evaluator_user_id=?
                      AND e.statut='BROUILLON'
                ");
                $s->execute([$uidHeader,$eidHeader,$uidHeader]);
                $encadreurEvaluationCount=(int)$s->fetchColumn();
            }catch(Throwable $e){
                error_log('[HEADER ENCADREUR EVALUATION COUNT] '.$e->getMessage());
                $encadreurEvaluationCount=0;
            }

            try{
                $s=$pdo->prepare("
                    SELECT COUNT(DISTINCT le.id)
                    FROM stage_logbook_entries le
                    JOIN stage_rotation_supervisors rs
                      ON rs.rotation_id=le.rotation_id
                     AND rs.user_id=?
                     AND rs.actif=1
                    WHERE le.host_etablissement_id=?
                      AND le.statut='SOUMIS'
                ");
                $s->execute([$uidHeader,$eidHeader]);
                $clinicalLogbookCount=(int)$s->fetchColumn();
            }catch(Throwable $e){
                error_log('[HEADER ENCADREUR LOGBOOK COUNT] '.$e->getMessage());
                $clinicalLogbookCount=0;
            }

            try{
                $todayHeader=date('Y-m-d');
                $s=$pdo->prepare("
                    SELECT COUNT(DISTINCT r.id)
                    FROM stage_rotations r
                    JOIN stage_rotation_supervisors rs
                      ON rs.rotation_id=r.id
                     AND rs.user_id=?
                     AND rs.actif=1
                    WHERE r.host_etablissement_id=?
                      AND r.statut='ACTIVE'
                      AND ? BETWEEN r.date_debut AND r.date_fin
                      AND NOT EXISTS(
                          SELECT 1
                          FROM stage_attendances att
                          WHERE att.rotation_id=r.id
                            AND att.date_presence=?
                      )
                ");
                $s->execute([$uidHeader,$eidHeader,$todayHeader,$todayHeader]);
                $clinicalAttendanceCount=(int)$s->fetchColumn();
            }catch(Throwable $e){
                error_log('[HEADER ENCADREUR ATTENDANCE COUNT] '.$e->getMessage());
                $clinicalAttendanceCount=0;
            }
        }
    }

    /* La cloche globale doit aussi intégrer les notifications de suivi clinique. */
    $stageNotificationCount += $chefEvaluationCount+$encadreurEvaluationCount+$clinicalLogbookCount+$clinicalAttendanceCount+$clinicalTaskCount+$clinicalFeedbackCount;

    if(!$hPoint)
        stagiaNav(BASE_URL.'/views/espace-hopital/dashboard.php','bi-grid','Tableau de bord','hopital-dashboard',$activePage);

    if(in_array($role,['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE'],true)){
        stagiaMenuTitle('MON ÉTABLISSEMENT');
        stagiaNav(BASE_URL.'/views/etablissements/etablissement.php','bi-building','Mon établissement','etablissement',$activePage);
    }

    /* =========================================================
       STAGES — ORGANISATION HOSPITALIÈRE
       ---------------------------------------------------------
       Règles :
       - ADMIN_ACCUEIL / COORDINATEUR_STAGES / AUTORITE_HOSPITALIERE
         peuvent voir les outils d'organisation selon leurs permissions.
       - ENCADREUR et POINTEUR ne voient jamais ces menus administratifs.
       - Les éléments structurants sont regroupés dans un sous-menu afin
         de garder une sidebar courte et lisible.
    ========================================================= */
    if($hAdmin||$hCoord||$hAuthority){

        $organisationStageItems=[];
        $operationStageItems=[];

        /* ----- Configuration / préparation des stages ----- */
        if(contextPermission('stage.manage'))
            $organisationStageItems[]=[
                BASE_URL.'/views/stages/types-stage.php',
                'bi-tags',
                'Types de stage',
                'stage-types'
            ];

        if(contextPermission('campaign.hosting.view')){
            $organisationStageItems[]=[
                BASE_URL.'/views/espace-hopital/campagnes-accueil.php',
                'bi-calendar3',
                'Campagnes d’accueil',
                'hospital-campaigns'
            ];

            $organisationStageItems[]=[
                BASE_URL.'/views/stages/plans-formation.php',
                'bi-list-check',
                'Plans de formation',
                'training-plans'
            ];
        }

        /* Conventions liées aux placements : préparation / signatures / PDF signé. */
        if(contextPermission(['stage.manage','campaign.hosting.view'])){
            $organisationStageItems[]=[
                BASE_URL.'/views/stages/conventions.php',
                'bi-file-earmark-sign',
                'Conventions de stage',
                'stage-conventions'
            ];
}

        if(contextPermission('capacity.hosting.view'))
            $organisationStageItems[]=[
                BASE_URL.'/views/espace-hopital/capacites.php',
                'bi-bar-chart-steps',
                'Capacités d’accueil',
                'hospital-capacities'
            ];

        /* ----- Opérations hospitalières ----- */
        if(contextPermission('campaign.hosting.respond'))
            $operationStageItems[]=[
                BASE_URL.'/views/espace-hopital/sollicitations-d4.php',
                'bi-bell-fill',
                'Sollicitations',
                'hospital-solicitations',
                $hostNewSolicitations
            ];

        if(($hAdmin||$hCoord)&&contextPermission('stage.manage')){
           

            $operationStageItems[]=[
                BASE_URL.'/views/espace-hopital/stagiaires-attendus.php',
                'bi-people',
                'Stagiaires attendus',
                'hospital-stagiaires',
                $hostExpectedInterns
            ];
        }

        /* ----- Rendu du bloc STAGES ----- */
        if($organisationStageItems||$operationStageItems){
            stagiaMenuTitle('STAGES');

            if($organisationStageItems)
                stagiaSubmenu(
                    'submenuOrganisationStagesHopital',
                    'bi-diagram-3',
                    'Organisation des stages',
                    [
                        'stage-types',
                        'hospital-campaigns',
                        'training-plans',
                        'stage-conventions',
                        'hospital-capacities'
                    ],
                    $activePage,
                    $organisationStageItems
                );

            foreach($operationStageItems as $i)
                stagiaNav($i[0],$i[1],$i[2],$i[3],$activePage,(int)($i[4]??0));
        }
    }

    /* Structure : administration et coordination, pas maître de stage. */
    if(($hAdmin||$hCoord||$hAuthority||$hChef)&&($hChef||contextPermission('host.view'))){
        stagiaMenuTitle('STRUCTURE D’ACCUEIL');
        stagiaNav(BASE_URL.'/views/espace-hopital/services-unites.php','bi-diagram-3','Services / unités','hospital-services',$activePage);

        /* Affectation au service : réservée au Chef de service.
           L'administration envoie d'abord le stagiaire vers une coordination/département
           depuis la page « Stagiaires attendus ». */
        if($hChef)
            stagiaNav(
                BASE_URL.'/views/espace-hopital/affectations.php',
                'bi-geo-alt',
                'Affectations au service',
                'hospital-affectations',
                $activePage,
                $hostReadyAssignments
            );

        if($hAdmin||$hCoord||$hChef)
            stagiaNav(BASE_URL.'/views/espace-hopital/rotations.php','bi-arrow-repeat',$hChef?'Rotations du service':'Rotations','hospital-rotations',$activePage);
    }

    /* =========================================================
       SUIVI CLINIQUE / OPÉRATIONNEL
       ---------------------------------------------------------
       - Chef de service : stagiaires reçus + suivi du service.
       - Encadreur : ses stagiaires / rotations confiées uniquement.
       - Admin / Coordination : suivi global selon permissions.
    ========================================================= */
    $suiviItems=[];
    $hClinical=$hAdmin||$hCoord||$hEnc||$hChef||$hAuthority;

    /* Chef de service : reçoit les stagiaires affectés à son service. */
    if($hChef){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/chef-service-stagiaires.php',
            'bi-person-check',
            'Stagiaires reçus',
            'chef-service-stagiaires',
            $chefReceivedCount
        ];
    }

    /* Encadreur / Maître de stage : voit uniquement les rotations qui lui sont confiées. */
    if($hEnc){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/mes-rotations.php',
            'bi-person-lines-fill',
            'Mes stagiaires / rotations',
            'encadreur-mes-rotations',
            $encadreurRotationsCount
        ];
    }

    /* Journaux de stage. */
    if(!$hPoint&&$hClinical&&($hChef||$hEnc||contextPermission('supervision.hosting.view'))){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/journaux-stage.php',
            'bi-journal-medical',
            'Journaux de stage',
            'hospital-logbook',
            $clinicalLogbookCount
        ];
    }

    /* Présences. */
    if(!$hPoint&&($hChef||$hEnc||contextPermission(['attendance.hosting.view','attendance.manage']))){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/presences.php',
            'bi-calendar-check',
            'Présences',
            'hopital-presences',
            $clinicalAttendanceCount
        ];
    }

    /* Tâches et progression. */
    if(!$hPoint&&$hClinical&&($hChef||$hEnc||contextPermission('supervision.hosting.view')||contextPermission('stage.manage'))){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/taches-progression.php',
            'bi-list-check',
            'Tâches & progression',
            'hospital-tasks',
            $clinicalTaskCount
        ];
    }

    /* Feedbacks et observations. */
    if(!$hPoint&&$hClinical&&($hChef||$hEnc||contextPermission('supervision.hosting.view')||contextPermission('stage.manage'))){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/feedbacks.php',
            'bi-chat-left-text',
            'Feedback & observations',
            'hospital-feedbacks',
            $clinicalFeedbackCount
        ];
    }

    /* Évaluations. */
    if(!$hPoint&&($hChef||$hEnc||contextPermission(['evaluation.view','evaluation.manage']))){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/evaluations.php',
            'bi-clipboard-check',
            'Évaluations',
            'hopital-evaluations',
            $hChef?$chefEvaluationCount:($hEnc?$encadreurEvaluationCount:0)
        ];
    }

    /* Clôture des stages : réservé administration / coordination. */
    if(($hAdmin||$hCoord)&&contextPermission('stage.manage'))
    $suiviItems[]=[
        BASE_URL.'/views/espace-hopital/resultats-stage.php',
        'bi-send-check',
        'Résultats de stage',
        'hospital-results',
        $hostResultReadyCount
    ];

    if($suiviItems){
        stagiaMenuTitle('SUIVI');
        stagiaSubmenu('submenuSuivi','bi-clipboard-check','Suivi',$hostSuiviPages,$activePage,$suiviItems);
    }

    if(($hAdmin||$hFinance||$hAuthority)&&contextPermission('payment.view')){
        stagiaMenuTitle('FINANCE');
        stagiaNav(BASE_URL.'/views/espace-hopital/paiements-recus.php','bi-credit-card','Paiements reçus','hospital-payments',$activePage);
    }

    /* Documents officiels : paramètres / modèles / archives. */
    $documentAdminItems=[];

    if(in_array($roleUpper,['ADMIN_ACCUEIL'],true)){
        $documentAdminItems[]=[
            BASE_URL.'/views/etablissements/parametres-documents.php',
            'bi-sliders',
            'Paramètres documents',
            'document-settings'
        ];
    }

    if(in_array($roleUpper,['ADMIN_ACCUEIL','COORDINATEUR_STAGES'],true)){
        $documentAdminItems[]=[
            BASE_URL.'/views/documents/modeles-stage.php',
            'bi-file-earmark-text',
            'Modèles documents',
            'stage-documents-models'
        ];
        $documentAdminItems[]=[
            BASE_URL.'/views/documents/archives.php',
            'bi-archive',
            'Archives documents',
            'stage-documents-archives'
        ];
    }

    if($documentAdminItems){
        stagiaMenuTitle('ADMINISTRATION');
        stagiaSubmenu(
            'submenuDocumentsOfficiels',
            'bi-file-earmark-text',
            'Documents officiels',
            $documentAdminPages,
            $activePage,
            $documentAdminItems
        );
    }

    /* Seul l'admin hospitalier gère comptes/paramètres. */
    if($hAdmin){
        $localAdminItems=[];
        if(contextPermission('user.view'))
            $localAdminItems[]=[BASE_URL.'/views/administration-etablissement/utilisateurs.php','bi-people','Utilisateurs','hopital-utilisateurs'];
        if(contextPermission('user.role.assign'))
            $localAdminItems[]=[BASE_URL.'/views/admin/affectations/index.php','bi-person-check','Affectations de rôles','admin-affectations'];
        if(contextPermission('report.view'))
            $localAdminItems[]=[BASE_URL.'/views/admin/statistiques/index.php','bi-bar-chart','Statistiques','admin-statistiques'];
        if(contextPermission('system.settings.manage'))
            $localAdminItems[]=[BASE_URL.'/views/admin/parametres/index.php','bi-sliders','Paramètres','admin-parametres'];

        if($localAdminItems){
            if(!$documentAdminItems)stagiaMenuTitle('ADMINISTRATION');
            stagiaSubmenu('submenuAdministration','bi-gear','Administration',$adminPages,$activePage,$localAdminItems);
        }
    }

}else{

/* DASHBOARD */

if($host && !$academic){

    stagiaNav(
        BASE_URL.'/views/espace-hopital/dashboard.php',
        'bi-grid',
        'Tableau de bord',
        'hopital-dashboard',
        $activePage
    );

}else{

    stagiaNav(
        BASE_URL.'/views/espace-etablissement/dashboard.php',
        'bi-grid',
        'Tableau de bord',
        'dashboard',
        $activePage
    );
}


/* MON ÉTABLISSEMENT */

if($etablissementNom!==''){

    stagiaMenuTitle('MON ÉTABLISSEMENT');

    stagiaNav(
        BASE_URL.'/views/etablissements/etablissement.php',
        'bi-building',
        'Mon établissement',
        'etablissement',
        $activePage
    );
}


/* GESTION ACADÉMIQUE */

if($academic && contextPermission(['academic.view','academic.manage'])){

    $academicItems=[
        [
            BASE_URL.'/views/academique/annees.php',
            'bi-calendar3',
            'Années académiques',
            'annees'
        ]
    ];

    if($unitEnabled)
        $academicItems[]=[
            BASE_URL.'/views/academique/facultes.php',
            'bi-buildings',
            $unitMenuLabel,
            'facultes'
        ];

    if($departmentEnabled)
        $academicItems[]=[
            BASE_URL.'/views/academique/departements.php',
            'bi-diagram-2',
            'Départements',
            'departements'
        ];

    if($programEnabled)
        $academicItems[]=[
            BASE_URL.'/views/academique/filieres.php',
            'bi-mortarboard',
            'Filières / Programmes',
            'filieres'
        ];

    if($promotionEnabled)
        $academicItems[]=[
            BASE_URL.'/views/academique/promotions.php',
            'bi-layers',
            'Promotions',
            'promotions'
        ];

   

    stagiaMenuTitle('GESTION ACADÉMIQUE');

    stagiaSubmenu(
        'submenuAcademique',
        'bi-mortarboard',
        'Organisation académique',
        $academicPages,
        $activePage,
        $academicItems
    );
}


/* ÉTUDIANTS */

if($academic && contextPermission(['student.view','student.manage'])){

    stagiaSubmenu(
        'submenuEtudiants',
        'bi-people',
        'Étudiants',
        $studentPages,
        $activePage,
        [
            [
                BASE_URL.'/views/etudiants/index.php',
                'bi-list-ul',
                'Liste des étudiants',
                'etudiants'
            ],
            [
                BASE_URL.'/views/etudiants/import.php',
                'bi-file-earmark-arrow-up',
                'Importer les étudiants',
                'etudiant-import'
            ]
        ]
    );
}


/* =========================================================
   STAGES — ESPACE ACADÉMIQUE / ÉTABLISSEMENT MIXTE
   ---------------------------------------------------------
   Les éléments de préparation sont regroupés dans
   « Organisation des stages » :
   - Types de stage
   - Campagnes universitaires
   - Plans de formation

   Les autres liens restent des opérations de suivi / placement.
========================================================= */
if(
    $academic &&
    contextPermission([
        'stage.view',
        'stage.manage',
        'campaign.university.view',
        'campaign.university.create',
        'campaign.university.update',
        'campaign.d4.participation.view',
        'placement.university.view'
    ])
){
    $organisationStageItems=[];
    $operationStageItems=[];

    /* ----- Configuration / préparation académique ----- */
    if(contextPermission(['campaign.university.create','campaign.university.update']))
        $organisationStageItems[]=[
            BASE_URL.'/views/stages/types-stage.php',
            'bi-tags',
            'Types de stage',
            'stage-types'
        ];

    if(contextPermission('campaign.university.view')){
        $organisationStageItems[]=[
            BASE_URL.'/views/stages/campagnes.php',
            'bi-calendar3',
            'Session de stage',
            'stages-campagnes'
        ];

        $organisationStageItems[]=[
            BASE_URL.'/views/stages/plans-formation.php',
            'bi-list-check',
            'Plans de formation',
            'training-plans'
        ];
    }

    /* Conventions administratives basées sur les placements confirmés. */
    if(contextPermission(['stage.manage','campaign.university.update','placement.university.view'])){
        $organisationStageItems[]=[
            BASE_URL.'/views/stages/conventions.php',
            'bi-file-earmark-sign',
            'Conventions de stage',
            'stage-conventions'
        ];
}

    /* ----- Partenariats / placements ----- */
    if(contextPermission('campaign.d4.participation.view'))
        $operationStageItems[]=[
            BASE_URL.'/views/stages/d4-partenaires.php',
            'bi-hospital',
            'Hôpitaux Partenaires',
            'stages-d4-partenaires',
            $academicNewOffers
        ];

    if(contextPermission('placement.university.view'))
        $operationStageItems[]=[
            BASE_URL.'/views/stages/placements-d4.php',
            'bi-people-fill',
            'Affectation / Placements',
            'stages-d4-placements'
        ];

    /* ----- Opérations académiques courantes ----- */
    if(contextPermission(['stage.view','stage.manage'])){
        $operationStageItems[]=[
            BASE_URL.'/views/stages/candidatures.php',
            'bi-file-earmark-person',
            'Candidatures',
            'stages-candidatures',
            $academicNewApplications
        ];

        $operationStageItems[]=[
            BASE_URL.'/views/espace-etablissement/resultats-stage.php',
            'bi-send-check',
            'Résultats reçus',
            'university-results',
            $universityResultCount
        ];

        $operationStageItems[]=[
            BASE_URL.'/views/stages/suivi.php',
            'bi-clipboard-check',
            'Suivi académique',
            'stages-suivi'
        ];
    }

    /* ----- Rendu du bloc STAGES ----- */
    if($organisationStageItems||$operationStageItems){
        stagiaMenuTitle('STAGES');

        if($organisationStageItems)
            stagiaSubmenu(
                'submenuOrganisationStagesAcademique',
                'bi-diagram-3',
                'Organisation des stages',
                [
                    'stage-types',
                    'stages-campagnes',
                    'training-plans',
                    'stage-conventions',
                ],
                $activePage,
                $organisationStageItems
            );

        foreach($operationStageItems as $i)
            stagiaNav($i[0],$i[1],$i[2],$i[3],$activePage,(int)($i[4]??0));
    }
}


/* STRUCTURE D'ACCUEIL */

if($host && !$isMaitreStageLike && contextPermission(['host.view','host.manage'])){

    stagiaMenuTitle('STRUCTURE D’ACCUEIL');

    stagiaNav(
        BASE_URL.'/views/espace-hopital/services-unites.php',
        'bi-diagram-3',
        'Services / unités',
        'hospital-services',
        $activePage
    );

    if(contextPermission('stage.manage')){

        stagiaNav(
            BASE_URL.'/views/espace-hopital/affectations.php',
            'bi-geo-alt',
            'Affectations',
            'hospital-affectations',
            $activePage
        );

        stagiaNav(
            BASE_URL.'/views/espace-hopital/rotations.php',
            'bi-arrow-repeat',
            'Rotations',
            'hospital-rotations',
            $activePage
        );
    }
}


/* SUIVI - SOUS-MENU */

if(
    $host &&
    ($isMaitreStageLike || contextPermission([
        'attendance.manage',
        'evaluation.manage',
        'stage.manage'
    ]))
){

    $suiviItems=[];

    if($isMaitreStageLike){
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/mes-rotations.php',
            'bi-person-lines-fill',
            'Mes stagiaires / rotations',
            'encadreur-mes-rotations'
        ];
    }

if(!$isMaitreStageLike && contextPermission('stage.manage'))
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/journaux-stage.php',
            'bi-journal-medical',
            'Journaux de stage',
            'hospital-logbook'
        ];

    if(contextPermission('attendance.manage'))
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/presences.php',
            'bi-calendar-check',
            'Présences',
            'hopital-presences'
        ];

    if(contextPermission(['supervision.hosting.view','stage.manage']))
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/taches-progression.php',
            'bi-list-check',
            'Tâches & progression',
            'hospital-tasks'
        ];

    if(contextPermission(['supervision.hosting.view','stage.manage']))
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/feedbacks.php',
            'bi-chat-left-text',
            'Feedback & observations',
            'hospital-feedbacks'
        ];

    if($isMaitreStageLike || contextPermission('evaluation.manage'))
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/evaluations.php',
            'bi-clipboard-check',
            'Évaluations',
            'hopital-evaluations'
        ];

    if(!$isMaitreStageLike && contextPermission('stage.manage'))
        $suiviItems[]=[
            BASE_URL.'/views/espace-hopital/resultats-stage.php',
            'bi-send-check',
            'Résultats de stage',
            'hospital-results',
            $hostResultReadyCount
        ];

    stagiaMenuTitle('SUIVI');

    stagiaSubmenu(
        'submenuSuivi',
        'bi-clipboard-check',
        'Suivi',
        $hostSuiviPages,
        $activePage,
        $suiviItems
    );
}


/* FINANCE */

if($host && contextPermission('payment.view')){

    stagiaMenuTitle('FINANCE');

    stagiaNav(
        BASE_URL.'/views/espace-hopital/paiements-recus.php',
        'bi-credit-card',
        'Paiements reçus',
        'hospital-payments',
        $activePage
    );
}

/* =========================================================
   FINANCE — UNIVERSITÉ / ÉTABLISSEMENT ACADÉMIQUE
========================================================= */

if(
    $academic &&
    in_array(
        $role,
        ['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],
        true
    )
){
    stagiaMenuTitle('FINANCE');

    stagiaNav(
        BASE_URL.'/views/stages/paiements-test.php',
        'bi-credit-card',
        'Paiements',
        'stages-payments-test',
        $activePage
    );
}


/* ADMINISTRATION LOCALE */

$documentAdminItems=[];

if(in_array($roleUpper,['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL'],true)){
    $documentAdminItems[]=[
        BASE_URL.'/views/etablissements/parametres-documents.php',
        'bi-sliders',
        'Paramètres documents',
        'document-settings'
    ];
}

if(in_array($roleUpper,['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES'],true)){
    $documentAdminItems[]=[
        BASE_URL.'/views/documents/modeles-stage.php',
        'bi-file-earmark-text',
        'Modèles documents',
        'stage-documents-models'
    ];
    $documentAdminItems[]=[
        BASE_URL.'/views/documents/archives.php',
        'bi-archive',
        'Archives documents',
        'stage-documents-archives'
    ];
}

if($documentAdminItems){
    stagiaMenuTitle('ADMINISTRATION');
    stagiaSubmenu(
        'submenuDocumentsOfficiels',
        'bi-file-earmark-text',
        'Documents officiels',
        $documentAdminPages,
        $activePage,
        $documentAdminItems
    );
}

$localAdminItems=[];

$localUserPage=
    $role==='ADMIN_ACCUEIL'
        ?'hopital-utilisateurs'
        :'utilisateurs';

if(contextPermission('user.view'))
    $localAdminItems[]=[
        BASE_URL.'/views/administration-etablissement/utilisateurs.php',
        'bi-people',
        'Utilisateurs',
        $localUserPage
    ];

if(contextPermission('user.role.assign'))
    $localAdminItems[]=[
        BASE_URL.'/views/admin/affectations/index.php',
        'bi-person-check',
        'Affectations de rôles',
        'admin-affectations'
    ];

if(contextPermission('report.view'))
    $localAdminItems[]=[
        BASE_URL.'/views/admin/statistiques/index.php',
        'bi-bar-chart',
        'Statistiques',
        'admin-statistiques'
    ];

if(contextPermission('system.settings.manage'))
    $localAdminItems[]=[
        BASE_URL.'/views/admin/parametres/index.php',
        'bi-sliders',
        'Paramètres',
        'admin-parametres'
    ];

if($localAdminItems){

    if(!$documentAdminItems)stagiaMenuTitle('ADMINISTRATION');

    stagiaSubmenu(
        'submenuAdministration',
        'bi-gear',
        'Administration',
        $adminPages,
        $activePage,
        $localAdminItems
    );
}
?>

<?php
}
?>

<?php endif; ?>

<?php
stagiaMenuTitle('COLLABORATION');
stagiaNav(
    BASE_URL.'/views/communication/index.php',
    'bi-chat-square-text',
    'Communication',
    'communication',
    $activePage
);
?>

</nav>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="app-main">

<header class="topbar">

<button class="sidebar-toggle"
        id="sidebarToggle"
        type="button"
        aria-label="Ouvrir le menu"
        aria-expanded="false">

    <i class="bi bi-list"></i>

</button>

<div class="topbar-space-title">
    <?= htmlspecialchars($topbarSpaceTitle) ?>
</div>

<div class="ms-auto d-flex align-items-center gap-3">

<div class="dropdown">
<button class="top-icon notification-bell" id="stageNotificationBell" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
    <i id="stageNotificationIcon" class="bi <?= $stageNotificationCount>0?'bi-bell-fill':'bi-bell' ?>"></i>
    <span id="stageNotificationCount" class="notification-count <?= $stageNotificationCount>0?'':'d-none' ?>"><?= $stageNotificationCount>99?'99+':$stageNotificationCount ?></span>
</button>
<div class="dropdown-menu dropdown-menu-end notification-menu">
    <div class="notification-head" id="stageNotificationHead"><i class="bi bi-bell me-1"></i> Notifications<?= $stageNotificationCount?' ('.$stageNotificationCount.')':'' ?></div>
    <div id="stageNotificationItems">
    <?php if($stageNotifications): foreach($stageNotifications as $n): ?>
        <a class="notification-item" data-notification-key="<?= htmlspecialchars($n['notification_key']) ?>" href="<?= htmlspecialchars($n['href']) ?>">
            <strong><?= htmlspecialchars($n['title']) ?></strong>
            <small><?= htmlspecialchars($n['subtitle']) ?></small>
            <small><?= htmlspecialchars($n['meta']) ?></small>
        </a>
    <?php endforeach; else: ?>
        <div class="p-4 text-center text-muted small"><i class="bi bi-check2-circle d-block fs-4 mb-1"></i>Aucune nouvelle notification.</div>
    <?php endif; ?>
    </div>
    <a class="notification-footer" id="stageNotificationFooter" href="<?= htmlspecialchars($notificationPrimaryHref) ?>">Voir les notifications</a>
</div>
</div>
<div class="dropdown">

<button class="user-menu dropdown-toggle"
        type="button"
        data-bs-toggle="dropdown"
        aria-expanded="false">

    <span class="user-avatar">
        <?= htmlspecialchars($initiale) ?>
    </span>

    <span class="d-none d-sm-block text-start">

        <strong>
            <?= htmlspecialchars($nomComplet) ?>
        </strong>

        <small>
            <?= htmlspecialchars($_SESSION['role_nom']??'') ?>
        </small>

    </span>

</button>

<ul class="dropdown-menu dropdown-menu-end">

    <li>
        <a class="dropdown-item" href="#">
            <i class="bi bi-person me-2"></i>
            Mon profil
        </a>
    </li>

    <li>
        <a class="dropdown-item" href="<?= BASE_URL ?>/views/paiements/index.php">
            <i class="bi bi-credit-card me-2"></i>
            Mes paiements
        </a>
    </li>

    <li>
        <hr class="dropdown-divider">
    </li>

    <li>
        <a class="dropdown-item text-danger"
           href="<?= BASE_URL ?>/logout.php">

            <i class="bi bi-box-arrow-right me-2"></i>
            Déconnexion

        </a>
    </li>

</ul>

</div>
</div>

</header>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const body=document.body;
    const sidebar=document.getElementById('sidebar');
    const toggle=document.getElementById('sidebarToggle');
    const closeBtn=document.getElementById('sidebarCloseMobile');
    const overlay=document.getElementById('sidebarMobileOverlay');

    if(!sidebar || !toggle)return;

    const toggleIcon=toggle.querySelector('i');

    function isMobile(){
        return window.matchMedia('(max-width: 991.98px)').matches;
    }

    function setOpen(open){
        body.classList.toggle('sidebar-open-mobile',open);
        sidebar.classList.toggle('show',open);
        sidebar.classList.toggle('active',open);
        if(toggleIcon)toggleIcon.className=open?'bi bi-x-lg':'bi bi-list';
    }

    toggle.onclick=function(e){
        if(!isMobile())return;
        e.preventDefault();
        e.stopPropagation();
        setOpen(!body.classList.contains('sidebar-open-mobile'));
    };

    if(closeBtn){
        closeBtn.onclick=function(e){
            e.preventDefault();
            e.stopPropagation();
            setOpen(false);
        };
    }

    if(overlay){
        overlay.onclick=function(){
            setOpen(false);
        };
    }

    document.addEventListener('keydown',function(e){
        if(e.key==='Escape')setOpen(false);
    });

    window.addEventListener('resize',function(){
        if(!isMobile())setOpen(false);
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    const BASE=window.STAGIA_BASE_URL||'',esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    const countEl=document.getElementById('stageNotificationCount'),icon=document.getElementById('stageNotificationIcon'),
          head=document.getElementById('stageNotificationHead'),items=document.getElementById('stageNotificationItems'),
          footer=document.getElementById('stageNotificationFooter');
    if(!countEl||!items)return;

    const seen=new Set([...document.querySelectorAll('[data-notification-key]')].map(x=>x.dataset.notificationKey));
    let first=true,busy=false;

    function badge(page,count){
        const el=document.querySelector(`[data-stage-badge="${page}"]`);if(!el)return;
        el.textContent=count>99?'99+':count;el.classList.toggle('d-none',!count);
    }

    async function refresh(){
        if(busy||document.hidden)return;busy=true;
        try{
            const r=await fetch(BASE+'/actions/stages/stage-notification-feed.php',{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
            const j=await r.json();if(!j.success)return;
            const d=j.data||{},count=Number(d.count||0),list=d.items||[];
            countEl.textContent=count>99?'99+':count;countEl.classList.toggle('d-none',count===0);
            icon.className='bi '+(count?'bi-bell-fill':'bi-bell');
            head.innerHTML='<i class="bi bi-bell me-1"></i> Notifications'+(count?` (${count})`:'');
            badge('hospital-solicitations',Number(d.host_count||0));
            badge('hospital-stagiaires',Number(d.expected_count||0));
            badge('hospital-affectations',Number(d.ready_assignment_count||0));
            badge('chef-service-stagiaires',Number(d.chef_service_count||0));
            badge('hospital-logbook',Number(d.logbook_count||0));
            badge('hopital-presences',Number(d.attendance_count||0));
            badge('hospital-tasks',Number(d.task_count||0));
            badge('hospital-feedbacks',Number(d.feedback_count||0));
            badge('hopital-evaluations',Number(d.evaluation_count||d.chef_evaluation_count||d.encadreur_evaluation_count||0));
            badge('hospital-results',Number(d.host_result_ready_count||0));
            if(Object.prototype.hasOwnProperty.call(d,'encadreur_rotations_count'))
                badge('encadreur-mes-rotations',Number(d.encadreur_rotations_count||0));
            badge('stages-d4-partenaires',Number(d.academic_count||0));
            badge('stages-candidatures',Number(d.application_count||0));
            badge('university-results',Number(d.university_result_count||0));
            badge('student-stages',Number(d.student_offer_count||0));
            footer.href=d.primary_href||'#';

            items.innerHTML=list.length?list.map(n=>`
                <a class="notification-item" data-notification-key="${esc(n.key)}" href="${esc(n.href)}">
                    <strong>${esc(n.title)}</strong><small>${esc(n.subtitle)}</small><small>${esc(n.meta)}</small>
                </a>`).join('')
                :'<div class="p-4 text-center text-muted small"><i class="bi bi-check2-circle d-block fs-4 mb-1"></i>Aucune nouvelle notification.</div>';

            if(!first){
                for(const n of list)if(!seen.has(String(n.key))){
                    seen.add(String(n.key));
                    if(window.STAGIA?.toast)STAGIA.toast(n.title,'info');
                }
            }else list.forEach(n=>seen.add(String(n.key)));

            first=false;
            window.dispatchEvent(new CustomEvent('stagia:stage-notifications',{detail:d}));
        }catch(e){}finally{busy=false;}
    }

    setTimeout(refresh,500);
    setInterval(refresh,2000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});

    window.addEventListener('stagia:force-notification-refresh',refresh);

});
</script>

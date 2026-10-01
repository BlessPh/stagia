<?php
/** Construction centralisée des notifications de cycle de stage. */
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/permissions.php';
require_once __DIR__.'/academic-structure.php';
require_once __DIR__.'/host-structure.php';

/** Produit le périmètre de données visible par le rôle et l'établissement courant. */
function stagiaNotifScope(PDO $pdo,int $eid,string $role,int $uid):array{
    if($role!=='COORDINATEUR_STAGES')return ['',[]];

    $s=$pdo->prepare("
        SELECT DISTINCT ra.scope_id
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=? AND r.role_code='COORDINATEUR_STAGES'
          AND ra.scope_type='UNIT' AND ra.actif=1
          AND (ra.etablissement_id IS NULL OR ra.etablissement_id=?)
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
    ");
    $s->execute([$uid,$eid]);
    $ids=array_values(array_filter(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN))));

    return $ids
        ?[' AND ad.coordination_unit_id IN('.implode(',',array_fill(0,count($ids),'?')).')',$ids]
        :[' AND 1=0',[]];
}

/** Retourne la base SQL des éléments de stage pouvant déclencher une notification. */
function stagiaNotifReadySql(string $scopeWhere=''):string{
    return "
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications app ON app.id=sr.application_id AND app.host_etablissement_id=ad.host_etablissement_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN promotions pr ON pr.id=ae.promotion_id
        LEFT JOIN host_units cu ON cu.id=ad.coordination_unit_id
        LEFT JOIN stage_campaign_participations part ON part.id=app.participation_id
        LEFT JOIN stagia_hospital_level_fees sf ON sf.host_etablissement_id=ad.host_etablissement_id
             AND sf.academic_level_id=pr.academic_level_id AND sf.actif=1
        LEFT JOIN stage_invoices inv ON inv.reservation_id=sr.id AND inv.host_etablissement_id=ad.host_etablissement_id
        LEFT JOIN (
            SELECT invoice_id,COALESCE(SUM(montant),0) paid_amount
            FROM stage_payments
            WHERE statut='VALIDE'
            GROUP BY invoice_id
        ) pay ON pay.invoice_id=inv.id
        WHERE ad.host_etablissement_id=?
          AND ad.statut IN('ADMIS','EN_COURS')
          AND ad.coordination_unit_id IS NOT NULL
          $scopeWhere
          AND NOT EXISTS(
              SELECT 1 FROM stage_assignments ass
              WHERE ass.admission_id=ad.id
                AND ass.host_etablissement_id=ad.host_etablissement_id
                AND ass.statut IN('PLANIFIEE','ACTIVE')
          )
          AND (
              NOT(
                  (COALESCE(part.frais_requis,0)=1 AND COALESCE(part.montant_frais,0)>0)
                  OR COALESCE(sf.amount,0)>0
                  OR COALESCE(inv.montant,0)>0
              )
              OR inv.statut='PAYEE'
              OR COALESCE(pay.paid_amount,0)>=COALESCE(inv.montant,0)
          )
    ";
}

/** Agrège les alertes du cycle de stage destinées à la session active. */
function stagiaNotificationsBuild(PDO $pdo):array{
    $eid=(int)($_SESSION['etablissement_id']??0);
    $uid=(int)($_SESSION['user_id']??0);
    $role=$_SESSION['role_code']??'';
    $academic=function_exists('contextAcademicEnabled')?contextAcademicEnabled():false;
    $host=function_exists('contextHostEnabled')?contextHostEnabled():false;
    $isStudent=$role==='STAGIAIRE';

    $items=[];
    $hostCount=$expectedCount=$readyAssignmentCount=$academicCount=$applicationCount=$studentOfferCount=0;
    $primaryHref='#';

    if($eid>0){
        if($host&&contextPermission('campaign.hosting.respond')){
            $s=$pdo->prepare("SELECT COUNT(*) FROM stage_campaign_participations WHERE host_etablissement_id=? AND statut='SOLLICITEE'");
            $s->execute([$eid]);$hostCount=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('H:',p.id) notification_key,CONCAT('Nouvelle sollicitation — ',u.nom) title,
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
            $s->execute([$eid]);$items=array_merge($items,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($host&&contextPermission(['admission.hosting.view','stage.manage'])){
            $s=$pdo->prepare("SELECT COUNT(*) FROM stage_admissions WHERE host_etablissement_id=? AND statut='ATTENDU'");
            $s->execute([$eid]);$expectedCount=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('E:',a.id) notification_key,'Nouveau stagiaire attendu' title,
                       CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,app.academic_enrollment_id))),' · ',c.code) subtitle,
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
            $s->execute([$eid]);$items=array_merge($items,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($host&&contextPermission('stage.manage')){
            [$scopeWhere,$scopeParams]=stagiaNotifScope($pdo,$eid,$role,$uid);
            $readySql=stagiaNotifReadySql($scopeWhere);

            $s=$pdo->prepare("SELECT COUNT(*) ".$readySql);
            $s->execute(array_merge([$eid],$scopeParams));
            $readyAssignmentCount=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('AS:',ad.id) notification_key,'Stagiaire prêt à affecter' title,
                       CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,app.academic_enrollment_id))),' · ',COALESCE(cu.nom,'Coordination')) subtitle,
                       CONCAT(c.code,' — ',c.titre,' · ',COALESCE(DATE_FORMAT(COALESCE(ad.coordination_sent_at,ad.admitted_at,ad.created_at),'%d/%m/%Y %H:%i'),'')) meta,
                       '".BASE_URL."/views/espace-hopital/affectations.php' href,
                       COALESCE(ad.coordination_sent_at,ad.admitted_at,ad.created_at) notification_at
                ".$readySql."
                ORDER BY notification_at DESC,ad.id DESC LIMIT 6
            ");
            $s->execute(array_merge([$eid],$scopeParams));
            $items=array_merge($items,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($academic&&contextPermission('campaign.university.view')){
            $s=$pdo->prepare("
                SELECT COUNT(*)
                FROM stage_campaign_participations p
                JOIN stage_campaigns c ON c.id=p.university_campaign_id
                WHERE c.owner_etablissement_id=? AND p.statut='ACCEPTEE'
                  AND p.capacite_proposee IS NOT NULL AND p.capacite_acceptee IS NULL
            ");
            $s->execute([$eid]);$academicCount=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('A:',p.id) notification_key,CONCAT('Nouvelle offre — ',h.nom) title,
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
            $s->execute([$eid]);$items=array_merge($items,$s->fetchAll(PDO::FETCH_ASSOC));
        }

        if($academic&&contextPermission(['stage.view','stage.manage'])){
            $s=$pdo->prepare("
                SELECT COUNT(*) FROM stage_applications a
                JOIN stage_campaigns c ON c.id=a.campaign_id
                WHERE c.owner_etablissement_id=? AND a.statut='SOUMISE'
            ");
            $s->execute([$eid]);$applicationCount=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT CONCAT('C:',a.id) notification_key,'Nouvelle candidature' title,
                       CONCAT(COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Étudiant #',COALESCE(se.student_id,a.academic_enrollment_id))),' · ',COALESCE(h.nom,'Structure d’accueil')) subtitle,
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
            $s->execute([$eid]);$items=array_merge($items,$s->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    if($isStudent){
        $s=$pdo->prepare("SELECT id FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");
        $s->execute([$uid]);$sid=(int)$s->fetchColumn();

        if($sid){
            $studentEligibility="
                FROM student_enrollments se
                JOIN student_academic_enrollments ae ON ae.enrollment_id=se.id AND ae.statut='EN_COURS'
                JOIN stage_campaigns c ON c.owner_etablissement_id=se.etablissement_id
                 AND c.annee_academique_id=ae.annee_academique_id
                 AND c.type_campagne='UNIVERSITAIRE' AND c.statut='OUVERTE'
                JOIN stage_types st ON st.id=c.stage_type_id AND st.actif=1
                JOIN stage_campaign_promotions cp ON cp.campaign_id=c.id AND cp.promotion_id=ae.promotion_id
                WHERE se.student_id=? AND se.statut='ACTIF'
                  AND EXISTS(
                      SELECT 1 FROM stage_campaign_participations sp
                      JOIN etablissements h ON h.id=sp.host_etablissement_id
                      WHERE sp.university_campaign_id=c.id
                        AND sp.statut='ACCEPTEE'
                        AND COALESCE(NULLIF(sp.capacite_acceptee,0),NULLIF(sp.capacite_allouee,0),0)>0
                        AND h.statut IN('VALIDE','ACTIF')
                  )
            ";

            $s=$pdo->prepare("SELECT COUNT(DISTINCT c.id) ".$studentEligibility);
            $s->execute([$sid]);$studentOfferCount=(int)$s->fetchColumn();

            $s=$pdo->prepare("
                SELECT DISTINCT CONCAT('S:',c.id) notification_key,'Nouvelle offre de stage disponible' title,
                       CONCAT(c.code,' — ',c.titre) subtitle,
                       CONCAT(st.libelle,' · Début ',DATE_FORMAT(c.date_debut,'%d/%m/%Y')) meta,
                       '".BASE_URL."/views/espace-etudiant/stages.php' href,
                       c.date_debut notification_at
                ".$studentEligibility."
                ORDER BY c.date_debut DESC,c.id DESC LIMIT 8
            ");
            $s->execute([$sid]);$items=array_merge($items,$s->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    usort($items,fn($a,$b)=>strcmp((string)$b['notification_at'],(string)$a['notification_at']));
    $items=array_slice($items,0,8);

    $count=$hostCount+$expectedCount+$readyAssignmentCount+$academicCount+$applicationCount+$studentOfferCount;

    if($isStudent)$primaryHref=BASE_URL.'/views/espace-etudiant/stages.php';
    elseif($applicationCount>0)$primaryHref=BASE_URL.'/views/stages/candidatures.php';
    elseif($academicCount>0)$primaryHref=BASE_URL.'/views/stages/d4-partenaires.php';
    elseif($expectedCount>0)$primaryHref=BASE_URL.'/views/espace-hopital/stagiaires-attendus.php';
    elseif($readyAssignmentCount>0)$primaryHref=BASE_URL.'/views/espace-hopital/affectations.php';
    elseif($hostCount>0)$primaryHref=BASE_URL.'/views/espace-hopital/sollicitations-d4.php';
    elseif($host)$primaryHref=BASE_URL.'/views/espace-hopital/sollicitations-d4.php';
    elseif($academic)$primaryHref=BASE_URL.'/views/stages/candidatures.php';

    return [
        'count'=>$count,
        'host_count'=>$hostCount,
        'expected_count'=>$expectedCount,
        'ready_assignment_count'=>$readyAssignmentCount,
        'academic_count'=>$academicCount,
        'application_count'=>$applicationCount,
        'student_offer_count'=>$studentOfferCount,
        'primary_href'=>$primaryHref,
        'items'=>$items
    ];
}

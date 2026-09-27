<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-d4-reservation.php';

try{
    requirePermission($pdo,'reservation.self.view');
    $userId=(int)($_SESSION['user_id']??0);$student=d4StudentProfile($pdo,$userId);

    $s=$pdo->prepare("
        SELECT DISTINCT
            c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
            c.date_debut campaign_start,c.date_fin campaign_end,
            aa.libelle annee_libelle,
            ae.id academic_enrollment_id,
            pr.nom promotion_nom,l.code niveau_code,
            p.id participation_id,p.host_etablissement_id,
            p.capacite_acceptee,p.frais_requis,p.montant_frais,p.devise,p.conditions,
            e.code host_code,e.nom host_nom,e.province,e.ville,
            hc.code host_campaign_code,hc.titre host_campaign_title,
            cp.capacite_totale,cp.reserve_hospitaliere,
            (
                SELECT COUNT(*) FROM stage_reservations r
                WHERE r.participation_id=p.id
                  AND (
                    r.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                    OR (r.statut='RESERVEE_TEMPORAIREMENT' AND (r.expires_at IS NULL OR r.expires_at>NOW()))
                  )
            ) reserved_count
        FROM student_academic_enrollments ae
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN annees_academiques aa
          ON aa.id=ae.annee_academique_id
         AND aa.etablissement_id=se.etablissement_id
        JOIN promotions pr ON pr.id=ae.promotion_id
        JOIN academic_levels l ON l.id=pr.academic_level_id
        JOIN stage_campaign_promotions scp ON scp.promotion_id=ae.promotion_id
        JOIN stage_campaigns c
          ON c.id=scp.campaign_id
         AND c.annee_academique_id=ae.annee_academique_id
         AND c.owner_etablissement_id=se.etablissement_id
         AND c.type_campagne='UNIVERSITAIRE'
         AND c.statut='OUVERTE'
        JOIN stage_types st ON st.id=c.stage_type_id AND st.code='MEDICAL_D4'
        JOIN stage_campaign_participations p
          ON p.university_campaign_id=c.id
         AND p.statut='ACCEPTEE'
         AND p.capacite_acceptee IS NOT NULL
         AND p.capacite_acceptee>0
        JOIN stage_campaigns hc ON hc.id=p.host_campaign_id
                               AND hc.type_campagne='ACCUEIL'
                               AND hc.statut NOT IN('ANNULEE','TERMINEE')
        JOIN stage_capacity_pools cp ON cp.host_campaign_id=hc.id
        JOIN etablissements e ON e.id=p.host_etablissement_id
                             AND e.type_etablissement='HOPITAL'
                             AND e.statut IN('VALIDE','ACTIF')
        WHERE se.student_id=? AND se.statut='ACTIF' AND ae.statut='EN_COURS'
          AND NOT EXISTS(
              SELECT 1
              FROM stage_applications a2
              LEFT JOIN stage_reservations r2 ON r2.application_id=a2.id
              LEFT JOIN stage_placements pl2 ON pl2.reservation_id=r2.id AND pl2.statut='CONFIRME'
              LEFT JOIN stage_admissions ad2 ON ad2.reservation_id=r2.id AND ad2.statut<>'ANNULE'
              WHERE a2.campaign_id=c.id AND a2.academic_enrollment_id=ae.id
                AND (
                    (r2.statut='RESERVEE_TEMPORAIREMENT' AND (r2.expires_at IS NULL OR r2.expires_at>NOW()))
                    OR r2.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                    OR pl2.id IS NOT NULL OR ad2.id IS NOT NULL
                )
          )
          AND NOT EXISTS(
              SELECT 1 FROM stage_completions sc
              WHERE sc.campaign_id=c.id AND sc.student_id=se.student_id
                AND sc.statut IN('EN_PREPARATION','PRET','VALIDE')
          )
        ORDER BY c.date_debut,e.nom
    ");
    $s->execute([(int)$student['id']]);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$x){
        $x['capacite_acceptee']=(int)$x['capacite_acceptee'];
        $x['reserved_count']=(int)$x['reserved_count'];
        $x['places_restantes']=max(0,$x['capacite_acceptee']-$x['reserved_count']);
        $x['frais_requis']=(int)$x['frais_requis'];
    }unset($x);

    $s=$pdo->prepare("
        SELECT r.id,r.uuid,r.statut,r.expires_at,r.confirmed_at,
               a.campaign_id,a.host_etablissement_id,a.participation_id,
               c.code campaign_code,c.titre campaign_title,e.nom host_nom,
               p.frais_requis,p.montant_frais,p.devise,
               sp.id placement_id,sp.statut placement_status
        FROM stage_reservations r
        JOIN stage_applications a ON a.id=r.application_id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN etablissements e ON e.id=a.host_etablissement_id
        JOIN stage_campaign_participations p ON p.id=r.participation_id
        LEFT JOIN stage_placements sp ON sp.reservation_id=r.id
        WHERE se.student_id=?
        ORDER BY r.created_at DESC
    ");
    $s->execute([(int)$student['id']]);$reservations=$s->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',['student'=>$student,'items'=>$items,'reservations'=>$reservations]);
}catch(Throwable $e){jsonResponse(false,'Erreur Choix D4 : '.$e->getMessage(),[],500);}

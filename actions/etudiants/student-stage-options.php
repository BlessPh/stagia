<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);

try{
    $userId=(int)($_SESSION['user_id']??0);
    if(!$userId)jsonResponse(false,'Session étudiant invalide.',[],401);

    $st=$pdo->prepare("SELECT id,stagia_code,nom,postnom,prenom FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");
    $st->execute([$userId]);
    $student=$st->fetch(PDO::FETCH_ASSOC);
    if(!$student)jsonResponse(false,'Profil étudiant introuvable.',[],404);
    $studentId=(int)$student['id'];

    /* Une session disparaît dès qu'un choix existe déjà pour l'étudiant. */
    $sql="
        SELECT DISTINCT c.id campaign_id,c.code,c.titre,c.date_debut,c.date_fin,
               ae.id academic_enrollment_id,aa.libelle annee_academique,
               p.nom promotion,f.nom filiere
        FROM student_enrollments se
        JOIN student_academic_enrollments ae ON ae.enrollment_id=se.id AND ae.statut='EN_COURS'
        JOIN stage_campaigns c ON c.owner_etablissement_id=se.etablissement_id
             AND c.annee_academique_id=ae.annee_academique_id
             AND c.type_campagne='UNIVERSITAIRE' AND c.statut='OUVERTE'
        JOIN stage_types stt ON stt.id=c.stage_type_id AND stt.actif=1 AND stt.code='MEDICAL_D4'
        JOIN stage_campaign_promotions cp ON cp.campaign_id=c.id AND cp.promotion_id=ae.promotion_id
        JOIN annees_academiques aa ON aa.id=ae.annee_academique_id
        JOIN promotions p ON p.id=ae.promotion_id
        JOIN filieres f ON f.id=p.filiere_id
        WHERE se.student_id=? AND se.statut='ACTIF'
          AND EXISTS(
              SELECT 1 FROM stage_campaign_participations sp
              JOIN stage_campaigns hc ON hc.id=sp.host_campaign_id
                   AND hc.type_campagne='ACCUEIL' AND hc.statut NOT IN('ANNULEE','TERMINEE')
              JOIN stage_capacity_pools cpool ON cpool.host_campaign_id=hc.id
              JOIN etablissements h ON h.id=sp.host_etablissement_id
              WHERE sp.university_campaign_id=c.id AND sp.statut='ACCEPTEE'
                AND COALESCE(sp.capacite_acceptee,0)>0
                AND h.type_etablissement='HOPITAL' AND h.statut IN('VALIDE','ACTIF')
          )
          AND NOT EXISTS(
              SELECT 1 FROM stage_applications sa
              LEFT JOIN stage_reservations sr ON sr.application_id=sa.id
              LEFT JOIN stage_placements pl ON pl.reservation_id=sr.id AND pl.statut='CONFIRME'
              LEFT JOIN stage_admissions ad ON ad.reservation_id=sr.id AND ad.statut<>'ANNULE'
              LEFT JOIN stage_assignments ass ON ass.admission_id=ad.id
              WHERE sa.campaign_id=c.id AND sa.academic_enrollment_id=ae.id
                AND (
                    (sr.statut='RESERVEE_TEMPORAIREMENT' AND (sr.expires_at IS NULL OR sr.expires_at>NOW()))
                    OR sr.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                    OR pl.id IS NOT NULL OR ad.id IS NOT NULL
                    OR (ass.id IS NOT NULL AND ass.statut<>'ANNULEE')
                )
          )
          AND NOT EXISTS(
              SELECT 1 FROM stage_completions sc
              WHERE sc.student_id=se.student_id AND sc.campaign_id=c.id
                AND sc.statut IN('EN_PREPARATION','PRET','VALIDE')
          )
        ORDER BY c.date_debut DESC,c.id DESC";
    $st=$pdo->prepare($sql);$st->execute([$studentId]);
    $campaigns=$st->fetchAll(PDO::FETCH_ASSOC);

    $hsql="
        SELECT sp.id participation_id,sp.host_etablissement_id,
               sp.capacite_acceptee capacite_allouee,
               sp.frais_requis,sp.montant_frais,sp.devise,sp.conditions,
               h.nom hopital,h.ville,h.province,
               GREATEST(
                   sp.capacite_acceptee
                   - COALESCE((
                       SELECT COUNT(*) FROM stage_reservations sr
                       WHERE sr.participation_id=sp.id
                         AND (sr.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                              OR (sr.statut='RESERVEE_TEMPORAIREMENT' AND (sr.expires_at IS NULL OR sr.expires_at>NOW())))
                   ),0),0
               ) places_disponibles
        FROM stage_campaign_participations sp
        JOIN stage_campaigns hc ON hc.id=sp.host_campaign_id
             AND hc.type_campagne='ACCUEIL' AND hc.statut NOT IN('ANNULEE','TERMINEE')
        JOIN stage_capacity_pools cpool ON cpool.host_campaign_id=hc.id
        JOIN etablissements h ON h.id=sp.host_etablissement_id
        WHERE sp.university_campaign_id=? AND sp.statut='ACCEPTEE'
          AND sp.capacite_acceptee IS NOT NULL AND sp.capacite_acceptee>0
          AND h.type_etablissement='HOPITAL' AND h.statut IN('VALIDE','ACTIF')
        ORDER BY h.nom";
    $hs=$pdo->prepare($hsql);
    foreach($campaigns as &$c){$hs->execute([$c['campaign_id']]);$c['hopitaux']=$hs->fetchAll(PDO::FETCH_ASSOC);}unset($c);

    jsonResponse(true,'',['student'=>$student,'campaigns'=>$campaigns]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur chargement stages : '.$e->getMessage(),[],500);
}

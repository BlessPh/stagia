<?php
/**
 * Endpoint AJAX de consultation des rotations rattachées aux affectations d'accueil actives.
 * Il actualise les statuts temporels avant de composer les données affichées dans l'interface.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* La planification globale des rotations est réservée à l'accueil. */
requireAjaxRole(['ADMIN_ACCUEIL']);

try{
    /* L'établissement courant borne toutes les synchronisations et les données retournées. */
    $hostId=currentEtablissementId($pdo);

    if(!$hostId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    /* Synchronisation automatique */
    $stmt=$pdo->prepare("
        UPDATE stage_rotations
        SET statut='TERMINEE',
            ended_at=COALESCE(ended_at,NOW())
        WHERE host_etablissement_id=?
          AND statut IN('PLANIFIEE','ACTIVE')
          AND date_fin<CURDATE()
    ");
    $stmt->execute([$hostId]);

    $stmt=$pdo->prepare("
        UPDATE stage_rotations
        SET statut='ACTIVE',
            started_at=COALESCE(started_at,NOW())
        WHERE host_etablissement_id=?
          AND statut='PLANIFIEE'
          AND date_debut<=CURDATE()
          AND date_fin>=CURDATE()
    ");
    $stmt->execute([$hostId]);

    /* Affectations de l'hôpital */
    $stmt=$pdo->prepare("
        SELECT
            a.id AS assignment_id,
            a.statut AS assignment_statut,
            a.date_debut AS assignment_start,
            a.date_fin AS assignment_end,

            sp.stagia_code,
            sp.nom,
            sp.postnom,
            sp.prenom,

            c.code AS campaign_code,
            c.titre AS campaign_title,

            uni.code AS university_code,
            uni.nom AS university_name,

            hu.code AS assigned_unit_code,
            hu.nom AS assigned_unit_name

        FROM stage_assignments a

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments sae
            ON sae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=sae.enrollment_id

        INNER JOIN student_profiles sp
            ON sp.id=se.student_id

        INNER JOIN stage_campaigns c
            ON c.id=sa.campaign_id

        INNER JOIN etablissements uni
            ON uni.id=c.owner_etablissement_id

        INNER JOIN host_units hu
            ON hu.id=a.host_unit_id

        WHERE a.host_etablissement_id=?
          AND a.statut IN('ACTIVE','PLANIFIEE')

        ORDER BY sp.nom,sp.prenom
    ");

    $stmt->execute([$hostId]);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Rotations d'une affectation */
    /* Cette requête est réutilisée pour charger les rotations et leur encadreur principal. */
    $rotationStmt=$pdo->prepare("
        SELECT
            r.id,
            r.uuid,
            r.assignment_id,
            r.host_unit_id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut,
            r.objectifs,
            r.observation,
            r.started_at,
            r.ended_at,

            hu.code AS unit_code,
            hu.nom AS unit_name,
            hu.type AS unit_type,

            parent.nom AS parent_name,

            (
                SELECT GROUP_CONCAT(
                    CONCAT_WS(' ',u.nom,u.postnom,u.prenom)
                    SEPARATOR ', '
                )
                FROM stage_rotation_supervisors rs
                INNER JOIN users u ON u.id=rs.user_id
                WHERE rs.rotation_id=r.id
                  AND rs.actif=1
            ) AS supervisors,

            (
                SELECT rs.user_id
                FROM stage_rotation_supervisors rs
                WHERE rs.rotation_id=r.id
                  AND rs.actif=1
                  AND rs.principal=1
                LIMIT 1
            ) AS supervisor_id

        FROM stage_rotations r

        INNER JOIN host_units hu
            ON hu.id=r.host_unit_id

        LEFT JOIN host_units parent
            ON parent.id=hu.parent_id

        WHERE r.assignment_id=?
          AND r.host_etablissement_id=?

        ORDER BY r.sequence_no,r.date_debut
    ");

    $stats=[
        'stagiaires'=>count($items),
        'rotations'=>0,
        'actives'=>0,
        'planifiees'=>0
    ];

    /* Chaque affectation est enrichie de ses rotations et les compteurs sont calculés. */
    foreach($items as &$x){
        $x['assignment_id']=(int)$x['assignment_id'];

        $rotationStmt->execute([
            $x['assignment_id'],
            $hostId
        ]);

        $x['rotations']=$rotationStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach($x['rotations'] as &$r){
            $r['id']=(int)$r['id'];
            $r['host_unit_id']=(int)$r['host_unit_id'];
            $r['sequence_no']=(int)$r['sequence_no'];
            $r['supervisor_id']=$r['supervisor_id']!==null
                ?(int)$r['supervisor_id']
                :null;

            $stats['rotations']++;

            if($r['statut']==='ACTIVE')
                $stats['actives']++;

            if($r['statut']==='PLANIFIEE')
                $stats['planifiees']++;
        }
        unset($r);
    }
    unset($x);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>$stats
    ]);

}catch(Throwable $e){
    jsonResponse(
        false,
        'Erreur rotations : '.$e->getMessage(),
        [],
        500
    );
}

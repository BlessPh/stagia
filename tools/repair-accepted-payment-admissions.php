<?php
/**
 * Audit des anciens dossiers acceptés qui doivent encore être placés.
 *
 * Cet outil créait historiquement une admission dès que la candidature était
 * acceptée ou payée. Ce comportement est désormais interdit : seule la
 * confirmation d'un placement universitaire peut créer l'admission ATTENDU.
 * L'outil est conservé comme rapport en lecture seule pour repérer les dossiers
 * à traiter depuis l'écran de placement D4.
 */

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/permissions.php';

requireRole(['SUPER_ADMIN','ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

$role=strtoupper((string)($_SESSION['role_code']??''));
$eid=function_exists('currentEtablissementId')?(int)currentEtablissementId($pdo):0;
$params=[];
$where="a.statut='ACCEPTEE'
        AND r.statut='CONFIRMEE'
        AND a.host_etablissement_id IS NOT NULL
        AND NOT EXISTS(
            SELECT 1
            FROM stage_placements pl
            WHERE pl.application_id=a.id
              AND pl.statut='CONFIRME'
        )";

if($role!=='SUPER_ADMIN'){
    if(!$eid){
        http_response_code(403);
        exit('Aucun établissement associé.');
    }
    $where.=' AND c.owner_etablissement_id=?';
    $params[]=$eid;
}

try{
    $s=$pdo->prepare("
        SELECT
            a.id application_id,
            r.id reservation_id,
            a.host_etablissement_id,
            CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom) etudiant
        FROM stage_applications a
        JOIN stage_campaigns c ON c.id=a.campaign_id
        JOIN stage_reservations r ON r.application_id=a.id
        JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN student_profiles sp ON sp.id=se.student_id
        WHERE $where
        ORDER BY a.id ASC
        LIMIT 500
    ");
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);

    echo '<!doctype html><meta charset="utf-8"><title>Audit des placements en attente</title>';
    echo '<div style="font-family:Arial;padding:30px;max-width:900px;margin:auto">';
    echo '<h2>Placements universitaires en attente</h2>';
    echo '<p>Cet audit est en lecture seule. Il ne crée aucune admission.</p>';
    echo '<p>Dossiers prêts à placer : <strong>'.count($rows).'</strong></p>';
    if($rows){
        echo '<table border="1" cellpadding="7" cellspacing="0"><thead><tr>';
        echo '<th>Candidature</th><th>Réservation</th><th>Étudiant</th><th>Hôpital</th>';
        echo '</tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td>'.(int)$row['application_id'].'</td><td>'.(int)$row['reservation_id'].'</td>';
            echo '<td>'.htmlspecialchars((string)$row['etudiant'],ENT_QUOTES,'UTF-8').'</td>';
            echo '<td>'.(int)$row['host_etablissement_id'].'</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<p>Utilisez le module universitaire de placement D4 pour confirmer ces dossiers.</p>';
    echo '</div>';
}catch(Throwable $e){
    http_response_code(500);
    echo 'Erreur audit : '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');
}

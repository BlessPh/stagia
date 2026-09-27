<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['STAGIAIRE']);

try{
    $stmt=$pdo->prepare("
        SELECT id FROM student_profiles
        WHERE user_id=? AND statut='ACTIF'
        LIMIT 1
    ");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $studentId=(int)$stmt->fetchColumn();

    if(!$studentId)
        jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $month=trim($_GET['month']??date('Y-m'));

    if($month && !preg_match('/^\d{4}-\d{2}$/',$month))
        jsonResponse(false,'Période invalide.',[],422);

    $stmt=$pdo->prepare("
        SELECT
            att.id,att.date_presence,att.heure_arrivee,att.heure_depart,
            att.statut,att.minutes_retard,att.source,
            att.justification,att.observation,

            r.id AS rotation_id,r.sequence_no,r.date_debut,r.date_fin,
            hu.code AS unit_code,hu.nom AS unit_name,
            parent.nom AS parent_name,
            h.code AS host_code,h.nom AS host_name

        FROM stage_attendances att
        INNER JOIN stage_rotations r ON r.id=att.rotation_id
        INNER JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN host_units parent ON parent.id=hu.parent_id
        INNER JOIN etablissements h ON h.id=att.host_etablissement_id

        WHERE att.student_id=?
          AND DATE_FORMAT(att.date_presence,'%Y-%m')=?

        ORDER BY att.date_presence DESC,att.id DESC
    ");

    $stmt->execute([$studentId,$month]);
    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats=[
        'total'=>count($items),
        'presents'=>0,'retards'=>0,'absents'=>0,
        'justifies'=>0,'gardes'=>0,'minutes_retard'=>0
    ];

    foreach($items as &$x){
        $x['id']=(int)$x['id'];
        $x['rotation_id']=(int)$x['rotation_id'];
        $x['sequence_no']=(int)$x['sequence_no'];
        $x['minutes_retard']=(int)$x['minutes_retard'];

        switch($x['statut']){
            case 'PRESENT': $stats['presents']++; break;
            case 'RETARD':
                $stats['retards']++;
                $stats['minutes_retard']+=$x['minutes_retard'];
                break;
            case 'ABSENT': $stats['absents']++; break;
            case 'JUSTIFIE': $stats['justifies']++; break;
            case 'GARDE': $stats['gardes']++; break;
        }
    }
    unset($x);

    $presences=$stats['presents']+$stats['retards']+$stats['gardes'];
    $stats['taux']=$stats['total']
        ?round(($presences/$stats['total'])*100,1)
        :0;

    jsonResponse(true,'',[
        'month'=>$month,
        'items'=>$items,
        'stats'=>$stats
    ]);

}catch(Throwable $e){
    jsonResponse(false,'Erreur présences : '.$e->getMessage(),[],500);
}
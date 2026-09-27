<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
requireAjaxRole(['STAGIAIRE']);

try{
    $uid=(int)($_SESSION['user_id']??0);
    if(!$uid)jsonResponse(false,'Session étudiant invalide.',[],401);

    $stmt=$pdo->prepare("SELECT id FROM student_profiles WHERE user_id=? AND statut='ACTIF' LIMIT 1");
    $stmt->execute([$uid]);$studentId=(int)$stmt->fetchColumn();
    if(!$studentId)jsonResponse(false,'Profil étudiant introuvable.',[],404);

    $where=["att.student_id=?"];$params=[$studentId];
    $from=trim($_GET['from']??'');$to=trim($_GET['to']??'');$status=strtoupper(trim($_GET['status']??''));
    if($from&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$where[]="att.date_presence>=?";$params[]=$from;}
    if($to&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$where[]="att.date_presence<=?";$params[]=$to;}
    if($status&&in_array($status,['PRESENT','RETARD','ABSENT','JUSTIFIE','GARDE'],true)){$where[]="att.statut=?";$params[]=$status;}

    $stmt=$pdo->prepare("SELECT att.id,att.date_presence,att.heure_arrivee,att.heure_depart,att.statut,att.minutes_retard,att.source,att.justification,att.observation,att.validated_at,
               r.sequence_no,r.date_debut,r.date_fin,hu.nom unit_name,hu.code unit_code,h.nom host_name
        FROM stage_attendances att
        JOIN stage_rotations r ON r.id=att.rotation_id
        LEFT JOIN host_units hu ON hu.id=r.host_unit_id
        LEFT JOIN etablissements h ON h.id=att.host_etablissement_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY att.date_presence DESC,att.id DESC");
    $stmt->execute($params);$items=$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats=['total'=>count($items),'presents'=>0,'retards'=>0,'absents'=>0,'justifies'=>0,'gardes'=>0];
    foreach($items as &$x){
        $x['id']=(int)$x['id'];$x['sequence_no']=(int)($x['sequence_no']??0);$x['minutes_retard']=(int)($x['minutes_retard']??0);
        if($x['statut']==='PRESENT')$stats['presents']++;elseif($x['statut']==='RETARD')$stats['retards']++;elseif($x['statut']==='ABSENT')$stats['absents']++;elseif($x['statut']==='JUSTIFIE')$stats['justifies']++;elseif($x['statut']==='GARDE')$stats['gardes']++;
    }unset($x);
    jsonResponse(true,'',['items'=>$items,'stats'=>$stats]);
}catch(Throwable $e){
    error_log('[STUDENT ATTENDANCE LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur présences étudiant : '.$e->getMessage(),[],500);
}

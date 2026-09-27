<?php
/**
 * Endpoint AJAX de consultation universitaire des relevés de résultats transmis par les hôpitaux.
 * Chaque transmission est enrichie de ses lignes stagiaires et des indicateurs de suivi par statut.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-results.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

try{
    /* Les transmissions sont impérativement filtrées par l'établissement universitaire de la session. */
    $eid=stageResultHostId($pdo);
    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);

    $q=trim((string)($_GET['q']??''));
    $where=['t.university_etablissement_id=?'];
    $params=[$eid];
    if($q!==''){
        $where[]='(c.code LIKE ? OR c.titre LIKE ? OR h.nom LIKE ? OR sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ?)';
        $like='%'.$q.'%';array_push($params,$like,$like,$like,$like,$like,$like);
    }

    $s=$pdo->prepare("
        SELECT DISTINCT
            t.id,t.uuid,t.campaign_id,t.host_etablissement_id,t.university_etablissement_id,
            t.statut,t.transmitted_at,t.received_at,t.validated_at,t.archived_at,t.observation,
            c.code campaign_code,c.titre campaign_title,c.date_debut,c.date_fin,
            h.nom host_name
        FROM stage_result_transmissions t
        JOIN stage_campaigns c ON c.id=t.campaign_id
        JOIN etablissements h ON h.id=t.host_etablissement_id
        LEFT JOIN stage_result_items ri ON ri.transmission_id=t.id
        LEFT JOIN student_profiles sp ON sp.id=ri.student_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY COALESCE(t.transmitted_at,t.created_at) DESC,t.id DESC
    ");
    $s->execute($params);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Les lignes de transmission sont récupérées séparément pour enrichir chaque relevé. */
    $item=$pdo->prepare("
        SELECT
            ri.*,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
            a.statut assignment_status
        FROM stage_result_items ri
        JOIN student_profiles sp ON sp.id=ri.student_id
        LEFT JOIN stage_assignments a ON a.id=ri.assignment_id
        WHERE ri.transmission_id=?
        ORDER BY sp.nom,sp.postnom,sp.prenom,ri.id
    ");

    $stats=['total'=>count($rows),'envoyes'=>0,'recus'=>0,'valides'=>0,'archives'=>0];

    /* Identifiants et métriques sont typés avant renvoi au tableau de résultats. */
    foreach($rows as &$x){
        foreach(['id','campaign_id','host_etablissement_id','university_etablissement_id'] as $f)$x[$f]=(int)$x[$f];

        $item->execute([$x['id']]);
        $x['items']=$item->fetchAll(PDO::FETCH_ASSOC);

        foreach($x['items'] as &$it){
            foreach(['id','transmission_id','assignment_id','student_id','rotation_count','presence_count','present_count','late_count','absent_count','justified_count','guard_count','journal_count','validated_journal_count','evaluation_count'] as $f)
                $it[$f]=(int)$it[$f];
            $it['presence_rate']=(float)$it['presence_rate'];
            $it['final_score']=(float)$it['final_score'];
            $it['student_name']=stageResultName($it);
        }
        unset($it);

        if($x['statut']==='ENVOYE')$stats['envoyes']++;
        elseif($x['statut']==='RECU')$stats['recus']++;
        elseif($x['statut']==='VALIDE')$stats['valides']++;
        elseif($x['statut']==='ARCHIVE')$stats['archives']++;
    }
    unset($x);

    jsonResponse(true,'',['items'=>$rows,'stats'=>$stats]);
}catch(Throwable $e){
    error_log('[UNIVERSITY RESULTS LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur résultats reçus : '.$e->getMessage(),[],500);
}

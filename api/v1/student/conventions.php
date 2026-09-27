<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];
try{
    $s=$pdo->prepare("SELECT sc.uuid,sc.reference,sc.titre,sc.version,sc.statut,sc.signed_at,sc.date_emission,pl.date_debut,pl.date_fin,c.code campaign_code,c.titre campaign_title,st.code stage_type_code,st.libelle stage_type_label,host.code host_code,host.nom host_name
        FROM stage_conventions sc JOIN stage_placements pl ON pl.id=sc.placement_id JOIN stage_campaigns c ON c.id=pl.campaign_id LEFT JOIN stage_types st ON st.id=c.stage_type_id JOIN etablissements host ON host.id=pl.host_etablissement_id
        WHERE pl.student_id=? AND sc.document_id IS NOT NULL AND sc.statut IN('SIGNEE','ARCHIVEE') ORDER BY COALESCE(sc.signed_at,sc.created_at) DESC,sc.id DESC");
    $s->execute([$studentId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){
        $item['version']=(int)$item['version'];
        $endpoint=rtrim(BASE_URL,'/').'/api/v1/student/conventions/'.$item['uuid'].'/file';
        $item['file_endpoint']=$endpoint;$item['download_endpoint']=$endpoint.'?download=1';$item['requires_bearer']=true;
    }unset($item);
    apiResponse(true,'',['items'=>$items,'total'=>count($items)]);
}catch(Throwable $e){apiResponse(false,'Erreur conventions : '.$e->getMessage(),[],500);}

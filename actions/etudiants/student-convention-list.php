<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['STAGIAIRE']);

try{
    $userId=(int)($_SESSION['user_id']??0);
    if(!$userId)jsonResponse(false,'Utilisateur non identifié.',[],401);

    $s=$pdo->prepare("
        SELECT
            sc.uuid,sc.reference,sc.titre,sc.version,sc.signed_at,sc.date_emission,
            pl.date_debut,pl.date_fin,
            c.code campaign_code,c.titre campaign_title,
            st.libelle stage_type_label,
            host.nom host_name
        FROM stage_conventions sc
        INNER JOIN stage_placements pl ON pl.id=sc.placement_id
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        INNER JOIN stage_types st ON st.id=c.stage_type_id
        INNER JOIN student_profiles sp ON sp.id=pl.student_id
        INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
        WHERE sp.user_id=?
          AND sc.document_id IS NOT NULL
          AND sc.statut IN('SIGNEE','ARCHIVEE')
        ORDER BY COALESCE(sc.signed_at,sc.created_at) DESC,sc.id DESC
    ");
    $s->execute([$userId]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);

    $items=[];
    foreach($rows as $x){
        $items[]=[
            'uuid'=>$x['uuid'],
            'reference'=>$x['reference'],
            'type_document'=>'CONVENTION_STAGE',
            'statut'=>'GENERE',
            'campaign_code'=>$x['campaign_code'],
            'campaign_title'=>$x['campaign_title'],
            'host_name'=>$x['host_name'],
            'stage_type_label'=>$x['stage_type_label'],
            'unit_name'=>null,
            'date_debut'=>$x['date_debut'],
            'date_fin'=>$x['date_fin'],
            'note_finale'=>null,
            'generated_at'=>$x['signed_at']?:$x['date_emission'],
            'version'=>(int)$x['version'],
            'title'=>$x['titre']
        ];
    }

    jsonResponse(true,'',['items'=>$items,'total'=>count($items)]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur conventions : '.$e->getMessage(),[],500);
}

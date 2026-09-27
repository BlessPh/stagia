<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-capacity.php';

try{
    requirePermission($pdo,'capacity.hosting.view');
    if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

    $eid=(int)($_SESSION['etablissement_id']??0);
    $s=$pdo->prepare("
        SELECT
            c.id,c.code,c.titre,c.date_debut,c.date_fin,c.statut,
            cp.id capacity_pool_id,cp.capacite_totale,cp.reserve_hospitaliere,
            COALESCE((
                SELECT SUM(COALESCE(p.capacite_acceptee,p.capacite_allouee))
                FROM stage_campaign_participations p
                WHERE p.host_campaign_id=c.id
                  AND p.statut='ACCEPTEE'
                  AND (p.capacite_acceptee IS NOT NULL OR p.capacite_allouee IS NOT NULL)
            ),0) allocated_total,
            COALESCE((
                SELECT COUNT(*)
                FROM stage_reservations r
                JOIN stage_campaign_participations p2 ON p2.id=r.participation_id
                WHERE p2.host_campaign_id=c.id
                  AND (
                    r.statut IN('EN_ATTENTE_PAIEMENT','CONFIRMEE')
                    OR (r.statut='RESERVEE_TEMPORAIREMENT' AND (r.expires_at IS NULL OR r.expires_at>NOW()))
                  )
            ),0) active_reservations,
            COALESCE((
                SELECT COUNT(*)
                FROM stage_campaign_participations p3
                WHERE p3.host_campaign_id=c.id
            ),0) participations_count
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id AND st.code='MEDICAL_D4'
        LEFT JOIN stage_capacity_pools cp ON cp.host_campaign_id=c.id
        WHERE c.owner_etablissement_id=?
          AND c.type_campagne='ACCUEIL'
        ORDER BY c.created_at DESC,c.id DESC
    ");
    $s->execute([$eid]);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$x){
        foreach(['capacite_totale','reserve_hospitaliere','allocated_total','active_reservations','participations_count'] as $k)
            $x[$k]=(int)($x[$k]??0);
        $x['capacity_defined']=$x['capacity_pool_id']!==null;
        $x['allocatable_total']=max(0,$x['capacite_totale']-$x['reserve_hospitaliere']);
        $x['allocation_available']=max(0,$x['allocatable_total']-$x['allocated_total']);
        $x['global_remaining']=max(0,$x['capacite_totale']-$x['active_reservations']);
    }
    unset($x);

    jsonResponse(true,'',[
        'items'=>$items,
        'permissions'=>['manage'=>hasPermission($pdo,'capacity.hosting.manage')]
    ]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur Capacités D4 : '.$e->getMessage(),[],500);
}

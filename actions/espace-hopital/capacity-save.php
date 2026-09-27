<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-capacity.php';

requirePermission($pdo,'capacity.hosting.manage');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$campaignId=(int)($_POST['host_campaign_id']??0);
$total=(int)($_POST['capacite_totale']??-1);
$reserve=(int)($_POST['reserve_hospitaliere']??-1);

if(!$eid||!$campaignId||$total<0||$reserve<0)
    jsonResponse(false,'Campagne et capacités invalides.',[],422);
if($reserve>$total)
    jsonResponse(false,'La réserve hospitalière ne peut pas dépasser la capacité totale.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT c.id,c.code,c.titre,c.statut
        FROM stage_campaigns c
        JOIN stage_types st ON st.id=c.stage_type_id AND st.code='MEDICAL_D4'
        WHERE c.id=? AND c.owner_etablissement_id=? AND c.type_campagne='ACCUEIL'
        LIMIT 1 FOR UPDATE
    ");
    $s->execute([$campaignId,$eid]);
    $c=$s->fetch(PDO::FETCH_ASSOC);
    if(!$c)throw new RuntimeException("Campagne d'accueil D4 introuvable.");
    if($c['statut']==='ANNULEE')throw new RuntimeException('Une campagne annulée ne peut plus recevoir de capacité.');

    $old=capacityPool($pdo,$campaignId,true);
    $allocated=capacityAllocatedTotal($pdo,$campaignId);
    $allocatable=$total-$reserve;

    if($allocated>$allocatable){
        throw new RuntimeException(
            "Capacité insuffisante : $allocated place(s) sont déjà allouées. " .
            "La capacité allouable après réserve doit être au moins égale à $allocated."
        );
    }

    $activeReservations=capacityActiveReservations($pdo,$campaignId);
    if($activeReservations>$allocated){
        throw new RuntimeException('Incohérence détectée : les réservations actives dépassent les allocations.');
    }

    $actor=(int)($_SESSION['user_id']??0)?:null;
    if($old){
        $pdo->prepare("
            UPDATE stage_capacity_pools
            SET capacite_totale=?,reserve_hospitaliere=?,updated_by_user_id=?
            WHERE id=?
        ")->execute([$total,$reserve,$actor,(int)$old['id']]);
        $poolId=(int)$old['id'];
        $event='CAPACITY_UPDATED';
    }else{
        $pdo->prepare("
            INSERT INTO stage_capacity_pools(
                host_campaign_id,capacite_totale,reserve_hospitaliere,
                created_by_user_id,updated_by_user_id
            ) VALUES(?,?,?,?,?)
        ")->execute([$campaignId,$total,$reserve,$actor,$actor]);
        $poolId=(int)$pdo->lastInsertId();
        $event='CAPACITY_CREATED';
    }

    capacityHistory(
        $pdo,$campaignId,$poolId,$event,
        $old?(int)$old['capacite_totale']:null,$total,
        $old?(int)$old['reserve_hospitaliere']:null,$reserve,
        $allocated,
        ['active_reservations'=>$activeReservations,'allocatable_total'=>$allocatable],
        $actor
    );

    $pdo->commit();
    jsonResponse(true,
        "Capacité enregistrée : $total place(s), dont $reserve en réserve hospitalière. " .
        ($allocatable-$allocated)." place(s) restent allouables aux universités."
    );
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/stage-d4-participation.php';

requirePermission($pdo,'campaign.hosting.respond');
verifyAjaxCsrf();

$eid=(int)($_SESSION['etablissement_id']??0);
$id=(int)($_POST['id']??0);
$decision=strtoupper(trim((string)($_POST['decision']??'')));

if(!$eid||!$id||!in_array($decision,['ACCEPTER','REFUSER'],true))
    jsonResponse(false,'Décision invalide.',[],422);

try{
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT
            p.*,
            uc.titre university_campaign_title
        FROM stage_campaign_participations p
        JOIN stage_campaigns uc ON uc.id=p.university_campaign_id
        WHERE p.id=?
          AND p.host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id,$eid]);
    $p=$s->fetch(PDO::FETCH_ASSOC);

    if(!$p)throw new RuntimeException('Sollicitation introuvable.');

    if(!in_array($p['statut'],['SOLLICITEE','EN_ETUDE','ACCEPTEE'],true))
        throw new RuntimeException('Cette sollicitation ne peut plus recevoir cette décision.');

    if($p['statut']==='ACCEPTEE' && !empty($p['capacite_acceptee']))
        throw new RuntimeException("L'université a déjà retenu cette offre ; elle ne peut plus être modifiée.");

    $actor=(int)($_SESSION['user_id']??0)?:null;
    $old=$p['statut'];

    if($decision==='REFUSER'){
        $reason=trim((string)($_POST['motif_refus']??''));
        if($reason==='')throw new RuntimeException('Le motif du refus est obligatoire.');

        $pdo->prepare("
            UPDATE stage_campaign_participations
            SET
                statut='REFUSEE',
                host_campaign_id=NULL,
                capacite_proposee=NULL,
                capacite_acceptee=NULL,
                capacite_allouee=NULL,
                conditions=NULL,
                motif_refus=?,
                frais_requis=0,
                montant_frais=NULL,
                devise=NULL,
                responded_by_user_id=?,
                responded_at=NOW(),
                finalized_by_user_id=NULL,
                finalized_at=NULL
            WHERE id=?
        ")->execute([$reason,$actor,$id]);

        d4ParticipationHistory(
            $pdo,$id,'REFUS',
            $old,'REFUSEE',
            ['motif'=>$reason],
            $actor
        );

        $message='Sollicitation refusée.';
    }else{
        $hostCampaignId=(int)($_POST['host_campaign_id']??0);
        $capacity=(int)($_POST['capacite_proposee']??0);
        $conditions=trim((string)($_POST['conditions']??''));
        $feeRequired=(int)(($_POST['frais_requis']??'0')==='1');
        $amount=$feeRequired?normalizedMoney($_POST['montant_frais']??null):null;
        $currency=$feeRequired?strtoupper(trim((string)($_POST['devise']??'USD'))):null;

        if(!$hostCampaignId||$capacity<1)
            throw new RuntimeException("La campagne d'accueil et la capacité proposée par l'hôpital sont obligatoires.");

        $hc=hostD4Campaign($pdo,$hostCampaignId,$eid,true);

        if($hc['statut']!=='OUVERTE')
            throw new RuntimeException("La campagne d'accueil choisie doit être ouverte.");

        if(
            strtotime((string)$p['date_debut'])<strtotime($hc['date_debut']) ||
            strtotime((string)$p['date_fin'])>strtotime($hc['date_fin'])
        ){
            throw new RuntimeException(
                "La campagne d'accueil sélectionnée ne couvre pas toute la période demandée par l'université."
            );
        }

        if($feeRequired && ($amount===null||$amount<0))
            throw new RuntimeException('Montant des frais invalide.');

        if($feeRequired && $currency==='')
            throw new RuntimeException('La devise est obligatoire.');

        $pdo->prepare("
            UPDATE stage_campaign_participations
            SET
                statut='ACCEPTEE',
                host_campaign_id=?,
                capacite_proposee=?,
                capacite_acceptee=NULL,
                capacite_allouee=NULL,
                conditions=?,
                motif_refus=NULL,
                frais_requis=?,
                montant_frais=?,
                devise=?,
                responded_by_user_id=?,
                responded_at=NOW(),
                finalized_by_user_id=NULL,
                finalized_at=NULL
            WHERE id=?
        ")->execute([
            $hostCampaignId,$capacity,$conditions?:null,
            $feeRequired,$amount,$currency,$actor,$id
        ]);

        d4ParticipationHistory(
            $pdo,$id,'OFFRE_HOPITAL',
            $old,'ACCEPTEE',
            [
                'host_campaign_id'=>$hostCampaignId,
                'capacite_proposee'=>$capacity,
                'conditions'=>$conditions?:null,
                'frais_requis'=>(bool)$feeRequired,
                'montant_frais'=>$amount,
                'devise'=>$currency
            ],
            $actor
        );

        $message=
            "Sollicitation acceptée. L'hôpital propose $capacity place(s). ".
            "L'université devra retenir l'offre avant l'allocation effective de capacité.";
    }

    $pdo->commit();
    jsonResponse(true,$message);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

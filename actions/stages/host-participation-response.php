<?php
/**
 * Endpoint AJAX de réponse à une sollicitation universitaire : étude, refus ou acceptation.
 * L'acceptation vérifie la capacité disponible et peut créer automatiquement une campagne d'accueil adaptée.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES']);
verifyAjaxCsrf();

/** Génère l'UUID d'une campagne d'accueil créée automatiquement. */
function hostUuidV4():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

try{
    /* La sollicitation est verrouillée avant toute décision afin d'éviter deux réponses concurrentes. */
    $hostId=currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0)?:null;
    $id=(int)($_POST['id']??0);
    $decision=strtoupper(trim((string)($_POST['decision']??'')));
    $decision=['ACCEPTEE'=>'ACCEPTER','REFUSEE'=>'REFUSER','EN_ETUDE'=>'ETUDIER'][$decision]??$decision;

    if(!$hostId||!$id||!in_array($decision,['ETUDIER','ACCEPTER','REFUSER'],true))
        jsonResponse(false,'Décision invalide.',[],422);

    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT p.*,c.stage_type_id,c.titre university_campaign_title,c.date_debut campaign_start,c.date_fin campaign_end
        FROM stage_campaign_participations p
        JOIN stage_campaigns c ON c.id=p.university_campaign_id AND c.type_campagne='UNIVERSITAIRE'
        WHERE p.id=? AND p.host_etablissement_id=? LIMIT 1 FOR UPDATE
    ");
    $s->execute([$id,$hostId]);$r=$s->fetch(PDO::FETCH_ASSOC);
    if(!$r)throw new RuntimeException('Sollicitation introuvable.');

    /* Le passage en étude ne modifie pas les capacités ni le rattachement de campagne. */
    if($decision==='ETUDIER'){
        if($r['statut']!=='SOLLICITEE')throw new RuntimeException("Cette sollicitation n'est plus nouvelle.");
        $pdo->prepare("UPDATE stage_campaign_participations SET statut='EN_ETUDE' WHERE id=?")->execute([$id]);
        $pdo->commit();jsonResponse(true,'Sollicitation mise en étude.');
    }

    /* Un refus nécessite un motif, enregistré avec l'auteur et la date de réponse. */
    if($decision==='REFUSER'){
        if(!in_array($r['statut'],['SOLLICITEE','EN_ETUDE'],true))throw new RuntimeException('Cette sollicitation ne peut plus être refusée.');
        $motif=trim((string)($_POST['motif_refus']??''));
        if($motif==='')throw new RuntimeException('Le motif du refus est obligatoire.');
        $pdo->prepare("UPDATE stage_campaign_participations SET statut='REFUSEE',motif_refus=?,responded_by_user_id=?,responded_at=NOW() WHERE id=?")
            ->execute([$motif,$uid,$id]);
        $pdo->commit();jsonResponse(true,'Sollicitation refusée.');
    }

    if(!in_array($r['statut'],['SOLLICITEE','EN_ETUDE','ACCEPTEE'],true)||($r['statut']==='ACCEPTEE'&&!empty($r['capacite_acceptee'])))
        throw new RuntimeException('Cette sollicitation ne peut plus être modifiée.');

    $capacity=(int)($_POST['capacite_proposee']??$_POST['capacite_allouee']??0);
    if($capacity<1)throw new RuntimeException('La capacité proposée doit être supérieure à zéro.');

    $conditions=trim((string)($_POST['conditions']??''));
    $fees=!empty($_POST['frais_requis'])?1:0;
    $amountRaw=str_replace(',','.',trim((string)($_POST['montant_frais']??'')));
    $currency=strtoupper(trim((string)($_POST['devise']??'USD')));

    if($fees&&!preg_match('/^\d+(?:\.\d{1,2})?$/',$amountRaw))throw new RuntimeException('Montant des frais invalide.');
    $amount=$fees?(float)$amountRaw:null;
    if($fees&&$amount<=0)throw new RuntimeException('Le montant des frais doit être supérieur à zéro.');
    if($fees&&!in_array($currency,['USD','CDF','EUR'],true))throw new RuntimeException('Devise invalide.');
    if(!$fees)$currency=null;

    $hostCampaignId=(int)($_POST['host_campaign_id']??$r['host_campaign_id']??0);
    $candidate=null;

    /* Cette fonction verrouille une campagne candidate et calcule son quota réellement disponible. */
    $lockCampaign=function(int $cid)use($pdo,$hostId,$r,$id,$capacity){
        $q=$pdo->prepare("
            SELECT c.id,c.stage_type_id,c.code,c.titre,cp.capacite_totale,cp.reserve_hospitaliere
            FROM stage_campaigns c
            JOIN stage_capacity_pools cp ON cp.host_campaign_id=c.id
            WHERE c.id=? AND c.owner_etablissement_id=? AND c.type_campagne='ACCUEIL'
              AND c.statut IN('BROUILLON','EN_PREPARATION','OUVERTE')
            LIMIT 1 FOR UPDATE
        ");
        $q->execute([$cid,$hostId]);$c=$q->fetch(PDO::FETCH_ASSOC);
        if(!$c||(int)$c['stage_type_id']!==(int)$r['stage_type_id'])return null;

        $a=$pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(capacite_allouee,capacite_proposee,0)),0)
            FROM stage_campaign_participations
            WHERE host_campaign_id=? AND statut='ACCEPTEE' AND id<>?
        ");
        $a->execute([$cid,$id]);
        $available=(int)$c['capacite_totale']-(int)$c['reserve_hospitaliere']-(int)$a->fetchColumn();

        return $available>=$capacity?array_merge($c,['available'=>$available]):null;
    };

    if($hostCampaignId)$candidate=$lockCampaign($hostCampaignId);

    /* Sans campagne compatible, une nouvelle session d'accueil est créée avec la capacité demandée. */
    if(!$candidate){
        $q=$pdo->prepare("
            SELECT c.id
            FROM stage_campaigns c
            JOIN stage_capacity_pools cp ON cp.host_campaign_id=c.id
            WHERE c.owner_etablissement_id=? AND c.type_campagne='ACCUEIL' AND c.stage_type_id=?
              AND c.statut IN('BROUILLON','EN_PREPARATION','OUVERTE')
              AND (c.date_fin IS NULL OR c.date_fin>=?) AND (c.date_debut IS NULL OR c.date_debut<=?)
            ORDER BY FIELD(c.statut,'OUVERTE','EN_PREPARATION','BROUILLON'),c.id DESC
        ");
        $q->execute([$hostId,(int)$r['stage_type_id'],$r['campaign_start'],$r['campaign_end']]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $cid)if($candidate=$lockCampaign((int)$cid))break;
    }

    $auto=false;

    if(!$candidate){
        $title='Accueil - '.mb_substr((string)$r['university_campaign_title'],0,170);
        $pdo->prepare("
            INSERT INTO stage_campaigns(uuid,stage_type_id,owner_etablissement_id,type_campagne,titre,date_debut,date_fin,statut,created_by_user_id)
            VALUES(?,?,?,'ACCUEIL',?,?,?,'EN_PREPARATION',?)
        ")->execute([hostUuidV4(),(int)$r['stage_type_id'],$hostId,$title,$r['campaign_start'],$r['campaign_end'],$uid]);

        $hostCampaignId=(int)$pdo->lastInsertId();
        $code='ACC-'.str_pad((string)$hostCampaignId,6,'0',STR_PAD_LEFT);
        $pdo->prepare("UPDATE stage_campaigns SET code=? WHERE id=?")->execute([$code,$hostCampaignId]);
        $pdo->prepare("INSERT INTO stage_capacity_pools(host_campaign_id,capacite_totale,reserve_hospitaliere) VALUES(?,?,0)")
            ->execute([$hostCampaignId,$capacity]);

        $candidate=['id'=>$hostCampaignId,'code'=>$code,'titre'=>$title,'available'=>$capacity];
        $auto=true;
    }else $hostCampaignId=(int)$candidate['id'];

    /* La participation acceptée est reliée à la campagne et conserve les conditions négociées. */
    $pdo->prepare("
        UPDATE stage_campaign_participations
        SET host_campaign_id=?,statut='ACCEPTEE',capacite_proposee=?,capacite_allouee=?,
            conditions=?,motif_refus=NULL,frais_requis=?,montant_frais=?,devise=?,
            responded_by_user_id=?,responded_at=NOW()
        WHERE id=?
    ")->execute([$hostCampaignId,$capacity,$capacity,$conditions?:null,$fees,$amount,$currency,$uid,$id]);

    $pdo->commit();

    jsonResponse(true,$auto?'Sollicitation acceptée. Session d’accueil créée automatiquement.':'Sollicitation acceptée et rattachée à une session d’accueil.',[
        'host_campaign_id'=>$hostCampaignId,
        'host_campaign_code'=>$candidate['code']??null,
        'capacite_proposee'=>$capacity,
        'auto_created'=>$auto
    ]);
}catch(Throwable $e){
    /* Toute erreur annule l'allocation et la campagne automatique éventuelle. */
    if($pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,$e->getMessage(),[],422);
}

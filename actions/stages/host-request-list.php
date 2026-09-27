<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES']);

$hostId=currentEtablissementId($pdo);
$status=trim((string)($_GET['statut']??''));
$allowed=['SOLLICITEE','EN_ETUDE','ACCEPTEE','REFUSEE','ANNULEE'];

if(!$hostId)jsonResponse(false,'Aucun établissement associé.',[],403);
if($status!==''&&!in_array($status,$allowed,true))jsonResponse(false,'Statut invalide.',[],422);

try{
    $where=['p.host_etablissement_id=?'];$params=[$hostId];

    if($status!==''){$where[]='p.statut=?';$params[]=$status;}
    else $where[]="p.statut IN('SOLLICITEE','EN_ETUDE')";

    $s=$pdo->prepare("
        SELECT p.id,p.university_campaign_id,p.host_campaign_id,p.statut,p.capacite_demandee,
               p.capacite_proposee,p.capacite_acceptee,p.capacite_allouee,p.conditions,p.motif_refus,
               p.frais_requis,p.montant_frais,p.devise,p.responded_at,
               c.stage_type_id,c.code university_campaign_code,c.titre university_campaign_title,c.date_debut,c.date_fin,
               st.code stage_type_code,st.libelle stage_type_libelle,
               e.nom university_name,e.ville university_city,
               hc.code host_campaign_code,hc.titre host_campaign_title
        FROM stage_campaign_participations p
        JOIN stage_campaigns c ON c.id=p.university_campaign_id AND c.type_campagne='UNIVERSITAIRE'
        JOIN stage_types st ON st.id=c.stage_type_id
        JOIN etablissements e ON e.id=c.owner_etablissement_id
        LEFT JOIN stage_campaigns hc ON hc.id=p.host_campaign_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY FIELD(p.statut,'SOLLICITEE','EN_ETUDE','ACCEPTEE','REFUSEE','ANNULEE'),p.id DESC
    ");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$x)
        foreach(['id','stage_type_id','capacite_demandee','capacite_proposee','capacite_acceptee','capacite_allouee','frais_requis'] as $f)
            if(array_key_exists($f,$x)&&$x[$f]!==null)$x[$f]=(int)$x[$f];
    unset($x);

    $s=$pdo->prepare("
        SELECT COUNT(*) total,
               COALESCE(SUM(statut='SOLLICITEE'),0) new_count,
               COALESCE(SUM(statut='EN_ETUDE'),0) studying,
               COALESCE(SUM(statut='ACCEPTEE'),0) accepted
        FROM stage_campaign_participations
        WHERE host_etablissement_id=? AND statut<>'ANNULEE'
    ");
    $s->execute([$hostId]);$k=$s->fetch(PDO::FETCH_ASSOC)?:[];

    $s=$pdo->prepare("
        SELECT c.id,c.code,c.titre,c.stage_type_id,c.date_debut,c.date_fin,c.statut,
               COALESCE(cp.capacite_totale,0) capacite_totale,
               COALESCE(cp.reserve_hospitaliere,0) reserve_hospitaliere,
               COALESCE((
                    SELECT SUM(COALESCE(p.capacite_allouee,p.capacite_proposee,0))
                    FROM stage_campaign_participations p
                    WHERE p.host_campaign_id=c.id AND p.statut='ACCEPTEE'
               ),0) capacite_allouee
        FROM stage_campaigns c
        LEFT JOIN stage_capacity_pools cp ON cp.host_campaign_id=c.id
        WHERE c.owner_etablissement_id=? AND c.type_campagne='ACCUEIL'
          AND c.statut IN('BROUILLON','EN_PREPARATION','OUVERTE')
        ORDER BY FIELD(c.statut,'OUVERTE','EN_PREPARATION','BROUILLON'),c.id DESC
    ");
    $s->execute([$hostId]);$campaigns=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($campaigns as &$c){
        foreach(['id','stage_type_id','capacite_totale','reserve_hospitaliere','capacite_allouee'] as $f)$c[$f]=(int)$c[$f];
        $c['capacite_disponible']=max(0,$c['capacite_totale']-$c['reserve_hospitaliere']-$c['capacite_allouee']);
    }unset($c);

    jsonResponse(true,'',[
        'items'=>$items,
        'host_campaigns'=>$campaigns,
        'kpi'=>[
            'total'=>(int)($k['total']??0),
            'new_count'=>(int)($k['new_count']??0),
            'studying'=>(int)($k['studying']??0),
            'accepted'=>(int)($k['accepted']??0)
        ]
    ]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur sollicitations : '.$e->getMessage(),[],500);
}
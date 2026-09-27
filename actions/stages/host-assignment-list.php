<?php
/**
 * Endpoint AJAX de préparation du tableau d'affectation des stagiaires dans l'établissement d'accueil.
 * Il adapte la requête aux colonnes disponibles et au périmètre réel du rôle connecté.
 */
ini_set('display_errors','0');
if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
ob_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/** Envoie une réponse JSON propre, après suppression de toute sortie PHP parasite. */
function stgAssignSend(bool $ok,string $msg='',array $data=[],int $code=200):void{
    while(ob_get_level()>0)ob_end_clean();
    http_response_code($code);
    echo json_encode(['success'=>$ok,'message'=>$msg,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
/** Vérifie une fois par requête l'existence d'une table pour compatibilité de schéma. */
function stgAssignTable(PDO $pdo,string $t):bool{
    static $c=[]; if(isset($c[$t]))return $c[$t];
    try{$s=$pdo->prepare('SHOW TABLES LIKE ?');$s->execute([$t]);return $c[$t]=(bool)$s->fetchColumn();}
    catch(Throwable $e){return $c[$t]=false;}
}
/** Lit et mémorise les colonnes d'une table lorsque plusieurs versions de schéma coexistent. */
function stgAssignCols(PDO $pdo,string $t):array{
    static $c=[]; if(isset($c[$t]))return $c[$t];
    try{$r=$pdo->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);$x=[];foreach($r as $v)$x[$v['Field']]=true;return $c[$t]=$x;}
    catch(Throwable $e){return $c[$t]=[];}
}
function stgAssignHas(PDO $pdo,string $t,string $col):bool{return isset(stgAssignCols($pdo,$t)[$col]);}

/** Produit l'expression SQL compatible avec les variantes historiques de la colonne de rôle. */
function stgAssignRoleExpr(PDO $pdo):string{
    $c=stgAssignCols($pdo,'roles');
    if(isset($c['code'],$c['role_code']))return "COALESCE(NULLIF(r.code,''),NULLIF(r.role_code,''))";
    if(isset($c['code']))return 'r.code';
    if(isset($c['role_code']))return 'r.role_code';
    return "''";
}

/** Retourne les coordinations accessibles ; null signifie accès global de l'administrateur d'accueil. */
function stgAssignScopedCoordIds(PDO $pdo,int $hostId,int $userId,string $roleUpper):?array{
    if($roleUpper==='ADMIN_ACCUEIL')return null; // accès global

    $codes=[];
    if($roleUpper==='COORDINATEUR_STAGES')$codes=['COORDINATEUR_STAGES'];
    elseif($roleUpper==='CHEF_SERVICE'||preg_match('/ROLE_CHEF_SERVICE$/',$roleUpper))$codes=['CHEF_SERVICE','ENCADREUR_CHEF_SERVICE','RESPONSABLE_SERVICE'];
    else return [];

    $expr=stgAssignRoleExpr($pdo);
    $in=implode(',',array_fill(0,count($codes),'?'));
    $sql="
        SELECT DISTINCT ra.scope_id
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=?
          AND $expr IN($in)
          AND ra.scope_type='UNIT'
          AND ra.scope_id IS NOT NULL
          AND ra.scope_id>0
          AND ra.actif=1
          AND (ra.revoked_at IS NULL)
          AND (ra.etablissement_id=? OR ra.etablissement_id IS NULL)
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
    ";
    $s=$pdo->prepare($sql);
    $s->execute(array_merge([$userId],$codes,[$hostId]));
    $ids=array_values(array_unique(array_filter(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)))));
    if(!$ids)return [];

    $inIds=implode(',',array_fill(0,count($ids),'?'));
    $s=$pdo->prepare("
        SELECT DISTINCT
            CASE
                WHEN parent_id IS NOT NULL AND UPPER(type) IN('SERVICE','UNITE','UNITÉ') THEN parent_id
                ELSE id
            END coord_id
        FROM host_units
        WHERE host_etablissement_id=?
          AND actif=1
          AND id IN($inIds)
    ");
    $s->execute(array_merge([$hostId],$ids));
    $coordIds=array_values(array_unique(array_filter(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)))));
    return $coordIds?:$ids;
}

try{
    /* Les rôles admis sont ensuite restreints aux unités réellement affectées à l'utilisateur. */
    requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE']);

    $hostId=currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);
    if(!$hostId)stgAssignSend(false,'Aucun établissement associé.',[],403);

    $assCols=stgAssignCols($pdo,'stage_assignments');
    $endedSet=isset($assCols['ended_at'])?", ended_at=COALESCE(ended_at,NOW())":'';

    /* Synchronisation de statut : les affectations planifiées ou échues sont mises à jour à la lecture. */
    $pdo->prepare("UPDATE stage_assignments SET statut='ACTIVE'
        WHERE host_etablissement_id=? AND statut='PLANIFIEE'
          AND date_debut<=CURDATE() AND (date_fin IS NULL OR date_fin>=CURDATE())")->execute([$hostId]);

    $pdo->prepare("UPDATE stage_assignments SET statut='TERMINEE' $endedSet
        WHERE host_etablissement_id=? AND statut IN('PLANIFIEE','ACTIVE')
          AND date_fin IS NOT NULL AND date_fin<CURDATE()")->execute([$hostId]);

    $pdo->prepare("UPDATE stage_admissions ad
        JOIN stage_assignments a ON a.admission_id=ad.id AND a.statut='ACTIVE'
        SET ad.statut='EN_COURS'
        WHERE ad.host_etablissement_id=? AND ad.statut='ADMIS'")->execute([$hostId]);

    /* Fragment de filtre SQL qui applique le périmètre de coordination de l'acteur. */
    $scopeWhere='';$scopeParams=[];
    $roleUpper=strtoupper(trim((string)($_SESSION['role_code']??'')));
    $coordScope=stgAssignScopedCoordIds($pdo,$hostId,$userId,$roleUpper);
    if(is_array($coordScope)){
        if(!$coordScope){
            $scopeWhere=' AND 1=0';
        }else{
            $scopeWhere=' AND ad.coordination_unit_id IN('.implode(',',array_fill(0,count($coordScope),'?')).')';
            $scopeParams=$coordScope;
        }
    }

    /* Compatibilité avec les installations dont certains modules ou colonnes sont facultatifs. */
    $hasFilieres=stgAssignTable($pdo,'filieres')&&stgAssignHas($pdo,'promotions','filiere_id');
    $filiereJoin=$hasFilieres?'LEFT JOIN filieres f ON f.id=pr.filiere_id':'';
    $filiereSelect=$hasFilieres?'f.nom filiere':"'' filiere";

    $hasSf=stgAssignTable($pdo,'stagia_hospital_level_fees')&&stgAssignHas($pdo,'stagia_hospital_level_fees','amount');
    $sfJoin=$hasSf?"LEFT JOIN stagia_hospital_level_fees sf ON sf.host_etablissement_id=ad.host_etablissement_id AND sf.academic_level_id=pr.academic_level_id AND sf.actif=1":'';
    $sfSelect=$hasSf?'COALESCE(sf.amount,0) frais_stagia_montant':'0 frais_stagia_montant';

    $hasPay=stgAssignTable($pdo,'stage_payments')&&stgAssignHas($pdo,'stage_payments','invoice_id')&&stgAssignHas($pdo,'stage_payments','montant');
    $payJoin=$hasPay?"LEFT JOIN (
            SELECT invoice_id,COUNT(*) validated_count,COALESCE(SUM(montant),0) validated_amount
            FROM stage_payments WHERE statut='VALIDE' GROUP BY invoice_id
        ) pay ON pay.invoice_id=inv.id":"LEFT JOIN (SELECT NULL invoice_id,0 validated_count,0 validated_amount) pay ON 1=0";

    /* Requête consolidée : admission, stagiaire, campagne, coordination, affectation et paiement. */
    $sql="
        SELECT ad.id admission_id,ad.statut admission_status,ad.statut admission_statut,ad.admitted_at,
               ad.coordination_unit_id,ad.coordination_sent_at,
               sr.id reservation_id,sr.statut reservation_status,
               sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
               pr.nom promotion,pr.academic_level_id,$filiereSelect,
               c.id campaign_id,c.code campaign_code,c.titre campaign_title,c.date_debut campaign_start,c.date_fin campaign_end,
               uni.id university_id,uni.code university_code,uni.nom university_name,
               cu.code coordination_code,cu.nom coordination_name,cu.type coordination_type,
               a.id assignment_id,a.uuid assignment_uuid,a.host_unit_id,a.statut assignment_statut,
               a.date_debut,a.date_fin,a.observation,a.assigned_at,
               hu.code unit_code,hu.nom unit_name,hu.nom host_unit_name,hu.type unit_type,parent.nom parent_name,
               COALESCE(part.frais_requis,0) frais_stage_requis,
               COALESCE(part.montant_frais,0) frais_stage_montant,
               $sfSelect,
               inv.id invoice_id,inv.reference invoice_reference,inv.montant invoice_amount,
               inv.devise invoice_devise,inv.statut invoice_status,inv.paid_at invoice_paid_at,
               COALESCE(pay.validated_count,0) validated_payment_count,
               COALESCE(pay.validated_amount,0) validated_payment_amount
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id AND sr.statut='CONFIRMEE'
        JOIN stage_applications sa ON sa.id=sr.application_id AND sa.statut='ACCEPTEE'
        JOIN stage_placements pl ON pl.id=ad.placement_id
            AND pl.reservation_id=sr.id
            AND pl.statut='CONFIRME'
        JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        JOIN student_enrollments se ON se.id=sae.enrollment_id
        JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN promotions pr ON pr.id=sae.promotion_id
        $filiereJoin
        JOIN stage_campaigns c ON c.id=sa.campaign_id
        JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        LEFT JOIN host_units cu ON cu.id=ad.coordination_unit_id
        LEFT JOIN stage_campaign_participations part ON part.id=sa.participation_id
        $sfJoin
        LEFT JOIN stage_invoices inv ON inv.reservation_id=sr.id AND inv.host_etablissement_id=ad.host_etablissement_id
        $payJoin
        LEFT JOIN stage_assignments a ON a.id=(
            SELECT a2.id
            FROM stage_assignments a2
            WHERE a2.admission_id=ad.id
              AND a2.host_etablissement_id=ad.host_etablissement_id
              AND a2.statut IN('ACTIVE','PLANIFIEE')
            ORDER BY FIELD(a2.statut,'ACTIVE','PLANIFIEE'),a2.date_debut DESC,a2.id DESC
            LIMIT 1
        )
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id
        LEFT JOIN host_units parent ON parent.id=hu.parent_id
        WHERE ad.host_etablissement_id=?
          AND ad.statut IN('ADMIS','EN_COURS')
          AND ad.coordination_unit_id IS NOT NULL
          $scopeWhere
        ORDER BY cu.nom,ad.admitted_at DESC,sp.nom,sp.prenom
    ";

    $s=$pdo->prepare($sql);
    $s->execute(array_merge([$hostId],$scopeParams));
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    $stats=['total'=>0,'affectes'=>0,'non_affectes'=>0,'actifs'=>0,'selectionnables'=>0,'paiement_ok'=>0,'paiement_bloque'=>0];

    /* Normalisation des types et calcul des drapeaux utilisés par les boutons de l'interface. */
    foreach($items as &$x){
        foreach(['admission_id','student_id','campaign_id','university_id','coordination_unit_id','host_unit_id','assignment_id','academic_level_id','invoice_id'] as $f)
            $x[$f]=isset($x[$f])&&$x[$f]!==null?(int)$x[$f]:null;

        $stageFee=((int)($x['frais_stage_requis']??0)===1&&(float)($x['frais_stage_montant']??0)>0);
        $stagiaFee=(float)($x['frais_stagia_montant']??0)>0;
        $invoiceAmount=(float)($x['invoice_amount']??0);
        $required=$stageFee||$stagiaFee||$invoiceAmount>0||$x['reservation_status']==='EN_ATTENTE_PAIEMENT';
        $paid=(!$required)||($x['invoice_status']==='PAYEE')||((int)($x['validated_payment_count']??0)>0&&(float)($x['validated_payment_amount']??0)>=$invoiceAmount&&$invoiceAmount>0);

        $x['payment_required']=$required?1:0;
        $x['payment_validated']=$paid?1:0;
        $x['payment_status']=$required?($x['invoice_status']?:($x['reservation_status']==='EN_ATTENTE_PAIEMENT'?'EN_ATTENTE_PAIEMENT':'NON_PAYE')):'NON_REQUIS';
        $x['payment_amount']=$invoiceAmount;
        $x['payment_devise']=$x['invoice_devise']?:'USD';
        $x['frais_requis']=$required?1:0;
        $x['montant_frais']=$invoiceAmount?:((float)($x['frais_stage_montant']??0)+(float)($x['frais_stagia_montant']??0));

        $assigned=!empty($x['assignment_id']);
        $x['bulk_selectable']=(!$assigned&&!empty($x['coordination_unit_id'])&&$paid);
        $x['can_assign']=$x['bulk_selectable'];

        $stats['total']++;
        if($assigned)$stats['affectes']++;else $stats['non_affectes']++;
        if(($x['assignment_statut']??'')==='ACTIVE')$stats['actifs']++;
        if($x['bulk_selectable'])$stats['selectionnables']++;
        if($paid)$stats['paiement_ok']++;else $stats['paiement_bloque']++;
    }unset($x);

    stgAssignSend(true,'',['items'=>$items,'stats'=>$stats]);
}catch(Throwable $e){
    error_log('[HOST ASSIGNMENT LIST SAFE] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    stgAssignSend(false,'Erreur chargement affectations : '.$e->getMessage(),[],500);
}

<?php
/*
 * STAGIA-RDC — Conventions de stage
 * ---------------------------------
 * Le module ne duplique ni l'étudiant ni les établissements :
 * - placement -> étudiant / hôpital / campagne
 * - campagne -> université / type de stage / condition financière
 * - student_documents -> fichier PDF signé
 */

/** Génère l'UUID d'une convention. */
function conventionUuidV4():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

/** Résout l'établissement courant pour le module conventions. */
function conventionEtablissementId():int{
    return (int)($_SESSION['etablissement_id']??0);
}

/** Retourne l'utilisateur connecté sous forme entière. */
function conventionUserId():int{
    return (int)($_SESSION['user_id']??0);
}

/** Vérifie le droit de gestion côté établissement académique. */
function conventionCanAcademicManage():bool{
    return contextAcademicEnabled() &&
        contextPermission(['stage.manage','campaign.university.update','placement.university.view']);
}

/** Vérifie le droit de gestion côté structure d'accueil. */
function conventionCanHostManage():bool{
    return contextHostEnabled() &&
        contextPermission(['stage.manage','campaign.hosting.view','campaign.hosting.update','campaign.hosting.publish']);
}

/** Indique si le rôle courant peut consulter les conventions. */
function conventionCanView():bool{
    return conventionCanAcademicManage() || conventionCanHostManage();
}

/** Exige le droit de consultation pour une page HTML. */
function requireConventionView(PDO $pdo):void{
    if(!conventionCanView()){
        http_response_code(403);
        exit('Accès refusé.');
    }
}

/** Exige le droit de consultation pour un endpoint AJAX. */
function requireConventionAjax():void{
    if(!conventionCanView())
        throw new RuntimeException('Accès refusé.');
}

/** Produit le filtre SQL et ses paramètres selon le côté académique ou accueil. */
function conventionScope(PDO $pdo,string $campaignAlias='c',string $placementAlias='pl'):array{
    $eid=conventionEtablissementId();
    if(!$eid)throw new RuntimeException('Établissement introuvable dans la session.');

    $parts=[];$params=[];
    if(contextAcademicEnabled()){
        $parts[]="$campaignAlias.owner_etablissement_id=?";
        $params[]=$eid;
    }
    if(contextHostEnabled()){
        $parts[]="$placementAlias.host_etablissement_id=?";
        $params[]=$eid;
    }
    if(!$parts)throw new RuntimeException('Contexte établissement invalide.');
    return ['('.implode(' OR ',$parts).')',$params];
}

/** Extrait les paramètres de frais nécessaires à la convention. */
function conventionFinancial(array $placement):array{
    $cfg=json_decode((string)($placement['configuration']??''),true);
    if(!is_array($cfg))$cfg=[];

    $f=$cfg['financial']??[];
    if(!is_array($f))$f=[];

    $mode=strtoupper((string)($f['mode']??''));
    if(!in_array($mode,['GRATUIT','PAYANT'],true)){
        $mode=!empty($cfg['payment_expected'])?'PAYANT':'GRATUIT';
    }

    return [
        'mode'=>$mode,
        'amount'=>$mode==='PAYANT'?(string)($f['amount']??''):null,
        'currency'=>$mode==='PAYANT'?(string)($f['currency']??'USD'):null
    ];
}

/** Charge un placement complet, éventuellement verrouillé pour une écriture transactionnelle. */
function conventionLoadPlacement(PDO $pdo,int $placementId,bool $forUpdate=false):array{
    $sql="
        SELECT
            pl.id,pl.uuid,pl.campaign_id,pl.academic_enrollment_id,
            pl.student_id,pl.host_etablissement_id,pl.statut placement_status,
            pl.date_debut placement_start,pl.date_fin placement_end,
            c.code campaign_code,c.titre campaign_title,c.configuration,
            c.owner_etablissement_id university_id,c.stage_type_id,
            st.libelle stage_type_label,
            sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
            uni.nom university_name,host.nom host_name,
            sae.enrollment_id,se.matricule
        FROM stage_placements pl
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        INNER JOIN stage_types st ON st.id=c.stage_type_id
        INNER JOIN student_profiles sp ON sp.id=pl.student_id
        INNER JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
        LEFT JOIN student_academic_enrollments sae ON sae.id=pl.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=sae.enrollment_id
        WHERE pl.id=?
        LIMIT 1
    ";
    if($forUpdate)$sql.=" FOR UPDATE";
    $s=$pdo->prepare($sql);$s->execute([$placementId]);
    $p=$s->fetch(PDO::FETCH_ASSOC);
    if(!$p)throw new RuntimeException('Placement introuvable.');

    $eid=conventionEtablissementId();
    $allowed=
        (contextAcademicEnabled() && (int)$p['university_id']===$eid) ||
        (contextHostEnabled() && (int)$p['host_etablissement_id']===$eid);

    if(!$allowed)throw new RuntimeException("Ce placement n'appartient pas à votre périmètre.");
    return $p;
}

/** Fige les informations d'un placement dans un snapshot de convention. */
function conventionSnapshot(array $p):array{
    $financial=conventionFinancial($p);
    return [
        'student'=>[
            'id'=>(int)$p['student_id'],
            'stagia_code'=>$p['stagia_code']??null,
            'matricule'=>$p['matricule']??null,
            'nom'=>$p['nom']??null,
            'postnom'=>$p['postnom']??null,
            'prenom'=>$p['prenom']??null
        ],
        'stage'=>[
            'campaign_id'=>(int)$p['campaign_id'],
            'campaign_code'=>$p['campaign_code']??null,
            'campaign_title'=>$p['campaign_title']??null,
            'type'=>$p['stage_type_label']??null,
            'date_debut'=>$p['placement_start']??null,
            'date_fin'=>$p['placement_end']??null
        ],
        'financial'=>$financial,
        'university'=>[
            'id'=>(int)$p['university_id'],
            'name'=>$p['university_name']??null
        ],
        'host'=>[
            'id'=>(int)$p['host_etablissement_id'],
            'name'=>$p['host_name']??null
        ]
    ];
}

/** Génère une référence de convention unique. */
function conventionReference(PDO $pdo):string{
    for($i=0;$i<8;$i++){
        $ref='CONV-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(4)));
        $s=$pdo->prepare("SELECT id FROM stage_conventions WHERE reference=? LIMIT 1");
        $s->execute([$ref]);
        if(!$s->fetchColumn())return $ref;
    }
    throw new RuntimeException('Impossible de générer une référence unique.');
}

/** Charge une convention et son contexte complet d'accès. */
function conventionLoad(PDO $pdo,int $id,bool $forUpdate=false):array{
    $sql="
        SELECT
            sc.*,
            pl.student_id,pl.academic_enrollment_id,pl.host_etablissement_id,
            pl.date_debut placement_start,pl.date_fin placement_end,
            c.owner_etablissement_id university_id,c.code campaign_code,c.titre campaign_title,
            sp.nom,sp.postnom,sp.prenom,sp.stagia_code,
            uni.nom university_name,host.nom host_name,
            sd.chemin document_path,sd.nom_fichier document_name,sd.mime_type document_mime
        FROM stage_conventions sc
        INNER JOIN stage_placements pl ON pl.id=sc.placement_id
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        INNER JOIN student_profiles sp ON sp.id=pl.student_id
        INNER JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
        LEFT JOIN student_documents sd ON sd.id=sc.document_id
        WHERE sc.id=?
        LIMIT 1
    ";
    if($forUpdate)$sql.=" FOR UPDATE";
    $s=$pdo->prepare($sql);$s->execute([$id]);
    $x=$s->fetch(PDO::FETCH_ASSOC);
    if(!$x)throw new RuntimeException('Convention introuvable.');

    $eid=conventionEtablissementId();
    $allowed=
        (contextAcademicEnabled() && (int)$x['university_id']===$eid) ||
        (contextHostEnabled() && (int)$x['host_etablissement_id']===$eid);

    if(!$allowed)throw new RuntimeException("Cette convention n'appartient pas à votre périmètre.");
    return $x;
}

/** Vérifie que l'établissement académique courant possède la convention. */
function conventionIsUniversityOwner(array $x):bool{
    return contextAcademicEnabled() &&
        conventionCanAcademicManage() &&
        (int)$x['university_id']===conventionEtablissementId();
}

/** Vérifie que la structure d'accueil courante possède la convention. */
function conventionIsHostOwner(array $x):bool{
    return contextHostEnabled() &&
        conventionCanHostManage() &&
        (int)$x['host_etablissement_id']===conventionEtablissementId();
}

/** Construit le nom affichable du stagiaire de la convention. */
function conventionStudentName(array $x):string{
    return trim(implode(' ',array_filter([
        $x['nom']??null,$x['postnom']??null,$x['prenom']??null
    ])));
}

<?php
/** Service d'archives : liste, normalise et contrôle les documents sur des schémas de base évolutifs. */
/* =========================================================
   STAGIA-RDC — Archives documents robustes
   ---------------------------------------------------------
   Corrige les compteurs à zéro lorsque certaines colonnes
   n'existent pas encore dans la base (stagia_code, matricule,
   completion_id, etc.).
========================================================= */

if(!function_exists('stagiaArchiveTableExists')){
    /** Vérifie l'existence d'une table d'archive ou de document. */
    function stagiaArchiveTableExists(PDO $pdo,string $table):bool{
        try{$s=$pdo->prepare('SHOW TABLES LIKE ?');$s->execute([$table]);return (bool)$s->fetchColumn();}
        catch(Throwable $e){return false;}
    }
}

if(!function_exists('stagiaDocArchiveTableExists')){
    /** Alias de compatibilité pour les anciens appels documentaires. */
    function stagiaDocArchiveTableExists(PDO $pdo,string $table):bool{
        return stagiaArchiveTableExists($pdo,$table);
    }
}

if(!function_exists('stagiaArchiveColumns')){
    /** Charge et mémorise les colonnes disponibles d'une table. */
    function stagiaArchiveColumns(PDO $pdo,string $table):array{
        static $cache=[];
        if(isset($cache[$table]))return $cache[$table];
        try{
            $rows=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $cols=[];foreach($rows as $r)$cols[(string)$r['Field']]=true;
            return $cache[$table]=$cols;
        }catch(Throwable $e){return $cache[$table]=[];}
    }
}

if(!function_exists('stagiaArchiveHas')){
    /** Indique si une colonne est disponible dans le schéma actuel. */
    function stagiaArchiveHas(PDO $pdo,string $table,string $column):bool{
        $cols=stagiaArchiveColumns($pdo,$table);
        return isset($cols[$column]);
    }
}

if(!function_exists('stagiaArchiveCol')){
    /** Produit une colonne SQL avec alias, en choisissant le premier nom compatible. */
    function stagiaArchiveCol(array $cols,array $names,string $alias,string $default='NULL'):string{
        foreach($names as $n)if(isset($cols[$n]))return '`'.$n.'` AS `'.$alias.'`';
        return $default.' AS `'.$alias.'`';
    }
}

if(!function_exists('stagiaArchiveExpr')){
    /** Produit une expression SQL compatible sans créer d'alias. */
    function stagiaArchiveExpr(array $cols,array $names,string $default='NULL'):string{
        foreach($names as $n)if(isset($cols[$n]))return '`'.$n.'`';
        return $default;
    }
}

if(!function_exists('stagiaArchiveStudentId')){
    /** Résout le profil étudiant associé à un compte utilisateur. */
    function stagiaArchiveStudentId(PDO $pdo,int $userId):int{
        if(!$userId||!stagiaArchiveTableExists($pdo,'student_profiles')||!stagiaArchiveHas($pdo,'student_profiles','user_id'))return 0;
        try{$s=$pdo->prepare('SELECT id FROM student_profiles WHERE user_id=? LIMIT 1');$s->execute([$userId]);return (int)$s->fetchColumn();}
        catch(Throwable $e){return 0;}
    }
}

if(!function_exists('stagiaArchiveNormalizeDate')){
    /** Conserve la portion date/heure affichable d'une valeur de base. */
    function stagiaArchiveNormalizeDate($value):string{return $value?substr((string)$value,0,19):'';}
}

if(!function_exists('stagiaArchiveStatusLabel')){
    /** Normalise les multiples statuts historiques en libellés d'archive. */
    function stagiaArchiveStatusLabel($status):string{
        $s=strtoupper(trim((string)$status));
        if(in_array($s,['ANNULEE','ANNULE','ANNULEE_PAR_ADMIN','CANCELLED','INVALIDEE','INVALIDE','REVOQUEE'],true))return 'ANNULÉ';
        if(in_array($s,['ARCHIVEE','ARCHIVE'],true))return 'ARCHIVÉ';
        if($s==='')return 'VALIDE';
        return 'VALIDE';
    }
}

if(!function_exists('stagiaArchiveVerifyUrl')){
    /** Reconstruit une URL publique de vérification à partir d'un jeton contenu dans une URL document. */
    function stagiaArchiveVerifyUrl(string $url):string{
        if($url==='')return '';
        $token='';
        $parts=parse_url($url);
        if(!empty($parts['query'])){
            parse_str($parts['query'],$q);
            $token=trim((string)($q['token']??''));
        }
        if($token==='')return '';
        $base=defined('BASE_URL')?rtrim(BASE_URL,'/'):'';
        return $base.'/views/public/verify-certificate.php?token='.rawurlencode($token);
    }
}

if(!function_exists('stagiaArchivePush')){
    /** Normalise une entrée de source documentaire avant son ajout à la liste d'archives. */
    function stagiaArchivePush(array &$items,array $x):void{
        $url=(string)($x['url']??'');
        $rawStatus=(string)($x['status']??'');
        $items[]=[
            'key'=>(string)($x['key']??uniqid('DOC-',true)),
            'type'=>(string)($x['type']??'AUTRE'),
            'type_label'=>(string)($x['type_label']??'Document'),
            'reference'=>(string)($x['reference']??''),
            'title'=>(string)($x['title']??''),
            'student_name'=>(string)($x['student_name']??''),
            'student_code'=>(string)($x['student_code']??''),
            'campaign'=>(string)($x['campaign']??''),
            'establishment'=>(string)($x['establishment']??''),
            'date'=>stagiaArchiveNormalizeDate($x['date']??''),
            'status'=>stagiaArchiveStatusLabel($rawStatus),
            'status_raw'=>$rawStatus,
            'url'=>$url,
            'download_url'=>(string)($x['download_url']??''),
            'verify_url'=>(string)($x['verify_url']??stagiaArchiveVerifyUrl($url)),
            'can_archive'=>false,
            'can_cancel'=>false,
            'can_reactivate'=>false
        ];
    }
}

if(!function_exists('stagiaArchiveNameExpr')){
    /** Produit le SQL de nom étudiant adapté aux colonnes réellement disponibles. */
    function stagiaArchiveNameExpr(PDO $pdo):string{
        if(!stagiaArchiveTableExists($pdo,'student_profiles'))return "''";
        $sp=stagiaArchiveColumns($pdo,'student_profiles');
        $parts=[];
        foreach(['nom','postnom','prenom'] as $c)if(isset($sp[$c]))$parts[]='sp.`'.$c.'`';
        if($parts)return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ',".implode(',',$parts).")),''),CONCAT('Étudiant #',COALESCE(cert.student_id,0)))";
        return "CONCAT('Étudiant #',COALESCE(cert.student_id,0))";
    }
}

if(!function_exists('stagiaArchiveStudentCodeExpr')){
    /** Produit le SQL du matricule avec une chaîne de solutions de repli. */
    function stagiaArchiveStudentCodeExpr(PDO $pdo):string{
        if(!stagiaArchiveTableExists($pdo,'student_profiles'))return "''";
        $sp=stagiaArchiveColumns($pdo,'student_profiles');
        $candidates=[];
        foreach(['stagia_code','matricule','matricule_academique','code_etudiant','numero_matricule'] as $c)
            if(isset($sp[$c]))$candidates[]="NULLIF(sp.`$c`,'')";
        $candidates[]="CONCAT('STG-ETU-',LPAD(COALESCE(sp.id,cert.student_id,0),8,'0'))";
        return 'COALESCE('.implode(',',$candidates).')';
    }
}

if(!function_exists('stagiaArchiveCertificateRows')){
    /** Charge les attestations accessibles dans le périmètre d'établissement ou de l'étudiant. */
    function stagiaArchiveCertificateRows(PDO $pdo,int $eid,int $studentId,bool $isStudent):array{
        if(!stagiaArchiveTableExists($pdo,'stage_certificates'))return [];

        $cert=stagiaArchiveColumns($pdo,'stage_certificates');
        $hasComp=stagiaArchiveTableExists($pdo,'stage_completions')&&isset($cert['completion_id']);
        $hasAssign=stagiaArchiveTableExists($pdo,'stage_assignments');
        $hasCampaign=stagiaArchiveTableExists($pdo,'stage_campaigns');
        $hasEst=stagiaArchiveTableExists($pdo,'etablissements');
        $hasStudent=stagiaArchiveTableExists($pdo,'student_profiles')&&isset($cert['student_id']);

        $id=isset($cert['id'])?'cert.`id`':'0';
        $uuid=stagiaArchiveExpr($cert,['uuid','token'],"''");
        $ref=stagiaArchiveExpr($cert,['reference','ref'],"CONCAT('CERT-',cert.`id`)");
        $type=stagiaArchiveExpr($cert,['type_document','type'],"'ATTESTATION_STAGE'");
        $status=stagiaArchiveExpr($cert,['statut','status'],"'GENERE'");
        $date=stagiaArchiveExpr($cert,['generated_at','created_at','updated_at'],"NOW()");
        $hostId=stagiaArchiveExpr($cert,['host_etablissement_id','host_id','etablissement_id'],"0");
        $univId=stagiaArchiveExpr($cert,['university_etablissement_id','owner_etablissement_id'],"0");
        $certCampaign=stagiaArchiveExpr($cert,['campaign_id'],"0");
        $certAssignment=stagiaArchiveExpr($cert,['assignment_id'],"0");

        $joins=[];
        if($hasStudent)$joins[]='LEFT JOIN student_profiles sp ON sp.id=cert.student_id';
        else $joins[]='LEFT JOIN (SELECT NULL id) sp ON 1=0';

        if($hasComp)$joins[]='LEFT JOIN stage_completions comp ON comp.id=cert.completion_id';
        else $joins[]='LEFT JOIN (SELECT NULL id,NULL campaign_id,NULL assignment_id) comp ON 1=0';

        if($hasAssign)$joins[]='LEFT JOIN stage_assignments a ON a.id=COALESCE(comp.assignment_id,'.$certAssignment.')';
        else $joins[]='LEFT JOIN (SELECT NULL id,NULL host_unit_id,NULL date_debut,NULL date_fin) a ON 1=0';

        if($hasCampaign)$joins[]='LEFT JOIN stage_campaigns c ON c.id=COALESCE(comp.campaign_id,'.$certCampaign.')';
        else $joins[]='LEFT JOIN (SELECT NULL id,NULL code,NULL titre,NULL owner_etablissement_id) c ON 1=0';

        $joins[]='LEFT JOIN host_units hu ON hu.id=a.host_unit_id';

        if($hasEst){
            $joins[]='LEFT JOIN etablissements h ON h.id=COALESCE('.$hostId.',a.host_etablissement_id)';
            $joins[]='LEFT JOIN etablissements u ON u.id=COALESCE('.$univId.',c.owner_etablissement_id)';
        }else{
            $joins[]='LEFT JOIN (SELECT NULL id,NULL nom) h ON 1=0';
            $joins[]='LEFT JOIN (SELECT NULL id,NULL nom) u ON 1=0';
        }

        $where=[];$params=[];
        if($isStudent&&$studentId&&isset($cert['student_id'])){$where[]='cert.student_id=?';$params[]=$studentId;}
        elseif($eid){
            $parts=[];
            if(isset($cert['host_etablissement_id']))$parts[]='cert.host_etablissement_id=?';
            if(isset($cert['university_etablissement_id']))$parts[]='cert.university_etablissement_id=?';
            if(isset($cert['owner_etablissement_id']))$parts[]='cert.owner_etablissement_id=?';
            if($hasCampaign)$parts[]='c.owner_etablissement_id=?';
            if($hasAssign&&stagiaArchiveHas($pdo,'stage_assignments','host_etablissement_id'))$parts[]='a.host_etablissement_id=?';
            if($parts){$where[]='('.implode(' OR ',$parts).')';for($i=0;$i<count($parts);$i++)$params[]=$eid;}
            /* Si aucune colonne de périmètre n'est disponible, on laisse visible au rôle autorisé. */
        }

        $studentName=stagiaArchiveNameExpr($pdo);
        $studentCode=stagiaArchiveStudentCodeExpr($pdo);
        $sql="
            SELECT $id id,$uuid uuid,$ref reference,$type type_document,$status statut,$date generated_at,
                   $studentName student_name,$studentCode student_code,
                   COALESCE(c.code,'') campaign_code,COALESCE(c.titre,'') campaign_title,
                   COALESCE(h.nom,'') host_name,COALESCE(u.nom,'') university_name,
                   COALESCE(hu.nom,'') unit_name
            FROM stage_certificates cert
            ".implode("\n",$joins)."
            ".($where?'WHERE '.implode(' AND ',$where):'')."
            ORDER BY generated_at DESC,id DESC
            LIMIT 500
        ";
        $s=$pdo->prepare($sql);$s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}

if(!function_exists('stagiaDocArchiveList')){
    /** Agrège les sources documentaires accessibles et applique les actions autorisées au rôle courant. */
    function stagiaDocArchiveList(PDO $pdo,int $eid,int $userId,string $roleUpper):array{
        $items=[];
        $base=defined('BASE_URL')?BASE_URL:'';
        $isStudent=$roleUpper==='STAGIAIRE';
        $studentId=$isStudent?stagiaArchiveStudentId($pdo,$userId):0;

        /* Attestations / certificats existants */
        try{
            foreach(stagiaArchiveCertificateRows($pdo,$eid,$studentId,$isStudent) as $r){
                $type=$r['type_document']?:'ATTESTATION_STAGE';
                $isCert=$type==='CERTIFICAT_STAGE';
                $url=$r['uuid']?$base.'/actions/stages/certificate-pdf.php?token='.rawurlencode((string)$r['uuid']):'';
                stagiaArchivePush($items,[
                    'key'=>'CERT-'.($r['id']??uniqid()),
                    'type'=>$type,
                    'type_label'=>$isCert?'Certificat de stage':'Attestation de stage',
                    'reference'=>$r['reference']??'',
                    'title'=>trim(($r['campaign_code']??'').' — '.($r['campaign_title']??''),' —') ?: ($isCert?'Certificat de stage':'Attestation de stage'),
                    'student_name'=>$r['student_name']??'',
                    'student_code'=>$r['student_code']??'',
                    'campaign'=>trim(($r['campaign_code']??'').' '.($r['campaign_title']??'')),
                    'establishment'=>($r['host_name']??'') ?: ($r['university_name']??''),
                    'date'=>$r['generated_at']??'',
                    'status'=>$r['statut']??'',
                    'url'=>$url,
                    'download_url'=>$url?($url.'&download=1'):''
                ]);
            }
        }catch(Throwable $e){error_log('[DOC ARCHIVE CERT V2] '.$e->getMessage());}

        /* Conventions */
        if(stagiaArchiveTableExists($pdo,'stage_conventions')){
            try{
                $cols=stagiaArchiveColumns($pdo,'stage_conventions');
                $id=isset($cols['id'])?'`id`':'0';
                $uuid=stagiaArchiveExpr($cols,['uuid','token'],"''");
                $reference=stagiaArchiveExpr($cols,['reference','ref'],"CONCAT('CONV-',`id`)");
                $title=stagiaArchiveExpr($cols,['titre','title','libelle'],"'Convention de stage'");
                $status=stagiaArchiveExpr($cols,['statut','status'],"''");
                $date=stagiaArchiveExpr($cols,['generated_at','date_emission','updated_at','created_at'],"NOW()");
                $where=[];$params=[];
                if($eid){
                    $parts=[];
                    foreach(['university_etablissement_id','owner_etablissement_id','etablissement_id','host_etablissement_id'] as $c)if(isset($cols[$c]))$parts[]='`'.$c.'`=?';
                    if($parts){$where[]='('.implode(' OR ',$parts).')';for($i=0;$i<count($parts);$i++)$params[]=$eid;}
                }
                $sql="SELECT $id id,$uuid uuid,$reference reference,$title title,$status statut,$date generated_at FROM stage_conventions ".($where?'WHERE '.implode(' AND ',$where):'')." ORDER BY generated_at DESC,id DESC LIMIT 500";
                $s=$pdo->prepare($sql);$s->execute($params);
                foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
                    $url=$r['uuid']?$base.'/actions/stages/convention-file.php?token='.rawurlencode((string)$r['uuid']):'';
                    stagiaArchivePush($items,[
                        'key'=>'CONV-'.$r['id'],
                        'type'=>'CONVENTION_STAGE',
                        'type_label'=>'Convention de stage',
                        'reference'=>$r['reference']??'',
                        'title'=>$r['title']?:'Convention de stage',
                        'date'=>$r['generated_at']??'',
                        'status'=>$r['statut']??'',
                        'url'=>$url,
                        'download_url'=>$url?($url.'&download=1'):''
                    ]);
                }
            }catch(Throwable $e){error_log('[DOC ARCHIVE CONV V2] '.$e->getMessage());}
        }

        /* Modèles imprimés */
        if(stagiaArchiveTableExists($pdo,'stage_document_archives')){
            try{
                $a=stagiaArchiveColumns($pdo,'stage_document_archives');
                $where=[];$params=[];
                if($isStudent&&$studentId&&isset($a['student_id'])){$where[]='da.student_id=?';$params[]=$studentId;}
                elseif($eid){
                    $parts=[];
                    foreach(['host_etablissement_id','university_etablissement_id','etablissement_id'] as $c)if(isset($a[$c]))$parts[]='da.`'.$c.'`=?';
                    if($parts){$where[]='('.implode(' OR ',$parts).')';for($i=0;$i<count($parts);$i++)$params[]=$eid;}
                }
                $s=$pdo->prepare("SELECT da.* FROM stage_document_archives da ".($where?'WHERE '.implode(' AND ',$where):'')." ORDER BY COALESCE(da.generated_at,da.created_at) DESC,da.id DESC LIMIT 500");
                $s->execute($params);
                foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
                    $docType=strtoupper((string)($r['document_type']??'MODELE'));
                    stagiaArchivePush($items,[
                        'key'=>'ARCH-'.($r['id']??uniqid()),
                        'type'=>$r['document_type']??'MODELE',
                        'type_label'=>$docType==='LETTRE_STAGE'?'Lettre de stage':($r['title']??'Modèle imprimé'),
                        'reference'=>$r['reference']??'',
                        'title'=>$r['title']??'Document imprimé',
                        'student_name'=>$r['student_name']??'',
                        'student_code'=>$r['student_code']??'',
                        'campaign'=>$r['campaign_label']??'',
                        'establishment'=>$r['establishment_name']??'',
                        'date'=>$r['generated_at']??($r['created_at']??''),
                        'status'=>$r['status']??'GENERE',
                        'url'=>$r['source_url']??'',
                        'download_url'=>$r['source_url']??''
                    ]);
                }
            }catch(Throwable $e){error_log('[DOC ARCHIVE MODEL V2] '.$e->getMessage());}
        }

        usort($items,function($a,$b){return strcmp((string)$b['date'],(string)$a['date']);});

        $canManage=in_array($roleUpper,['SUPER_ADMIN','ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL'],true);
        foreach($items as &$it){
            $status=(string)($it['status']??'');
            $key=(string)($it['key']??'');
            $manageable=$canManage && preg_match('/^(CERT|ARCH|CONV)-\d+$/',$key);
            $it['can_archive']=$manageable && $status==='VALIDE';
            $it['can_cancel']=$manageable && $status!=='ANNULÉ';
            $it['can_reactivate']=$manageable && in_array($status,['ANNULÉ','ARCHIVÉ'],true);
        }
        unset($it);
        return $items;
    }
}

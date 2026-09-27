<?php
/* =========================================================
   STAGIA-RDC — Données documents de stage
   ---------------------------------------------------------
   Helper robuste : modèles imprimables, fiche de présence,
   fiche d'appréciation, lettre de recommandation,
   rapport / certificat final synthèse.
========================================================= */

if(!function_exists('stagiaDocTableExists')){
    function stagiaDocTableExists(PDO $pdo,string $table):bool{
        try{
            $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
            $s->execute([$table]);
            return (int)$s->fetchColumn()>0;
        }catch(Throwable $e){return false;}
    }
}

if(!function_exists('stagiaDocColumnExists')){
    function stagiaDocColumnExists(PDO $pdo,string $table,string $column):bool{
        try{
            $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
            $s->execute([$table,$column]);
            return (int)$s->fetchColumn()>0;
        }catch(Throwable $e){return false;}
    }
}

if(!function_exists('stagiaDocPick')){
    function stagiaDocPick(array $row,array $keys,string $default=''):string{
        foreach($keys as $k){
            if(isset($row[$k]) && trim((string)$row[$k])!=='')return trim((string)$row[$k]);
        }
        return $default;
    }
}

if(!function_exists('stagiaDocDateFr')){
    function stagiaDocDateFr($v):string{
        if(!$v)return '—';
        $s=substr((string)$v,0,10);
        $p=explode('-',$s);
        return count($p)===3?$p[2].'/'.$p[1].'/'.$p[0]:$s;
    }
}

if(!function_exists('stagiaDocDateLongFr')){
    function stagiaDocDateLongFr($v=null):string{
        $ts=$v?strtotime((string)$v):time();
        if(!$ts)$ts=time();
        $m=[1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
        return date('d',$ts).' '.$m[(int)date('n',$ts)].' '.date('Y',$ts);
    }
}

if(!function_exists('stagiaDocNum')){
    function stagiaDocNum($v,int $d=2):string{
        if($v===null||$v==='')return '—';
        return number_format((float)$v,$d,',',' ');
    }
}

if(!function_exists('stagiaDocStudentMatricule')){
    function stagiaDocStudentMatricule(PDO $pdo,int $studentId,int $enrollmentId=0,int $academicEnrollmentId=0):string{
        $candidates=[];
        foreach(['matricule_academique','matricule','stagia_code','code_stagia','reference'] as $col){
            if(stagiaDocColumnExists($pdo,'student_profiles',$col))$candidates[]="`$col`";
        }
        if($studentId&&$candidates){
            try{
                $s=$pdo->prepare('SELECT '.implode(',',$candidates).' FROM student_profiles WHERE id=? LIMIT 1');
                $s->execute([$studentId]);
                $r=$s->fetch(PDO::FETCH_ASSOC)?:[];
                $v=stagiaDocPick($r,array_map(fn($x)=>trim($x,'`'),$candidates));
                if($v!=='')return $v;
            }catch(Throwable $e){}
        }

        $candidates=[];
        foreach(['matricule_academique','matricule','stagia_code','code_stagia','reference'] as $col){
            if(stagiaDocColumnExists($pdo,'student_enrollments',$col))$candidates[]="`$col`";
        }
        if($enrollmentId&&$candidates){
            try{
                $s=$pdo->prepare('SELECT '.implode(',',$candidates).' FROM student_enrollments WHERE id=? LIMIT 1');
                $s->execute([$enrollmentId]);
                $r=$s->fetch(PDO::FETCH_ASSOC)?:[];
                $v=stagiaDocPick($r,array_map(fn($x)=>trim($x,'`'),$candidates));
                if($v!=='')return $v;
            }catch(Throwable $e){}
        }

        return $studentId?'STG-'.$studentId:'—';
    }
}

if(!function_exists('stagiaDocResolvePromotion')){
    function stagiaDocResolvePromotion(PDO $pdo,int $academicEnrollmentId=0,int $campaignId=0,string $campaignTitle=''):string{
        $promotionId=0;$levelId=0;

        if($academicEnrollmentId && stagiaDocTableExists($pdo,'student_academic_enrollments')){
            try{
                $cols=['id'];
                foreach(['promotion_id','niveau_id','academic_level_id','level_id'] as $c)
                    if(stagiaDocColumnExists($pdo,'student_academic_enrollments',$c))$cols[]=$c;
                $s=$pdo->prepare('SELECT '.implode(',',$cols).' FROM student_academic_enrollments WHERE id=? LIMIT 1');
                $s->execute([$academicEnrollmentId]);
                $r=$s->fetch(PDO::FETCH_ASSOC)?:[];
                $promotionId=(int)($r['promotion_id']??0);
                $levelId=(int)($r['niveau_id']??($r['academic_level_id']??($r['level_id']??0)));
            }catch(Throwable $e){}
        }

        if(!$promotionId && $campaignId && stagiaDocTableExists($pdo,'stage_campaign_promotions')){
            try{
                $col=stagiaDocColumnExists($pdo,'stage_campaign_promotions','promotion_id')?'promotion_id':null;
                if($col){
                    $s=$pdo->prepare("SELECT $col FROM stage_campaign_promotions WHERE campaign_id=? ORDER BY id LIMIT 1");
                    $s->execute([$campaignId]);
                    $promotionId=(int)$s->fetchColumn();
                }
            }catch(Throwable $e){}
        }

        if($promotionId && stagiaDocTableExists($pdo,'promotions')){
            try{
                $cols=['id'];
                foreach(['niveau','code','nom','libelle','intitule','academic_level_id','niveau_id','level_id'] as $c)
                    if(stagiaDocColumnExists($pdo,'promotions',$c))$cols[]=$c;
                $s=$pdo->prepare('SELECT '.implode(',',$cols).' FROM promotions WHERE id=? LIMIT 1');
                $s->execute([$promotionId]);
                $r=$s->fetch(PDO::FETCH_ASSOC)?:[];
                $label=stagiaDocPick($r,['niveau','code','nom','libelle','intitule']);
                if($label!=='')return $label;
                $levelId=(int)($r['academic_level_id']??($r['niveau_id']??($r['level_id']??$levelId)));
            }catch(Throwable $e){}
        }

        if($levelId && stagiaDocTableExists($pdo,'academic_levels')){
            try{
                $cols=['id'];
                foreach(['code','libelle','nom','niveau'] as $c)
                    if(stagiaDocColumnExists($pdo,'academic_levels',$c))$cols[]=$c;
                $s=$pdo->prepare('SELECT '.implode(',',$cols).' FROM academic_levels WHERE id=? LIMIT 1');
                $s->execute([$levelId]);
                $r=$s->fetch(PDO::FETCH_ASSOC)?:[];
                $label=stagiaDocPick($r,['code','libelle','nom','niveau']);
                if($label!=='')return $label;
            }catch(Throwable $e){}
        }

        if(preg_match('/\b(D[1-9]|L[1-9]|M[1-9]|G[1-9]|BAC\s*[1-9])\b/i',$campaignTitle,$m))
            return strtoupper(str_replace(' ','',$m[1]));

        return '—';
    }
}

if(!function_exists('stagiaDocBaseContextSql')){
    function stagiaDocBaseContextSql():string{
        return "
            SELECT
                a.id assignment_id,a.host_etablissement_id,a.host_unit_id,a.date_debut,a.date_fin,a.statut assignment_status,
                ad.id admission_id,sr.id reservation_id,
                app.id application_id,app.academic_enrollment_id,app.campaign_id,
                c.code campaign_code,c.titre campaign_title,c.date_debut campaign_start,c.date_fin campaign_end,
                st.libelle stage_type_label,
                ae.id academic_enrollment_id,se.id enrollment_id,
                sp.id student_id,sp.user_id student_user_id,sp.nom,sp.postnom,sp.prenom,
                hu.nom unit_name,
                h.id host_id,h.nom host_name,h.ville host_city,h.province host_province,
                u.id university_id,u.nom university_name,u.ville university_city,u.province university_province
            FROM stage_assignments a
            LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
            LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
            LEFT JOIN stage_applications app ON app.id=sr.application_id
            LEFT JOIN stage_campaigns c ON c.id=app.campaign_id
            LEFT JOIN stage_types st ON st.id=c.stage_type_id
            LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
            LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
            LEFT JOIN student_profiles sp ON sp.id=se.student_id
            LEFT JOIN host_units hu ON hu.id=a.host_unit_id
            LEFT JOIN etablissements h ON h.id=a.host_etablissement_id
            LEFT JOIN etablissements u ON u.id=c.owner_etablissement_id
        ";
    }
}

if(!function_exists('stagiaDocHydrateContext')){
    function stagiaDocHydrateContext(PDO $pdo,array $r):array{
        $r['assignment_id']=(int)($r['assignment_id']??0);
        $r['student_id']=(int)($r['student_id']??0);
        $r['academic_enrollment_id']=(int)($r['academic_enrollment_id']??0);
        $r['campaign_id']=(int)($r['campaign_id']??0);
        $r['host_id']=(int)($r['host_id']??0);
        $r['university_id']=(int)($r['university_id']??0);
        $r['student_name']=trim(implode(' ',array_filter([$r['nom']??'',$r['postnom']??'',$r['prenom']??''])));
        if($r['student_name']==='')$r['student_name']='Stagiaire #'.$r['student_id'];
        $r['matricule']=stagiaDocStudentMatricule($pdo,$r['student_id'],(int)($r['enrollment_id']??0),$r['academic_enrollment_id']);
        $r['promotion']=stagiaDocResolvePromotion($pdo,$r['academic_enrollment_id'],$r['campaign_id'],(string)($r['campaign_title']??''));
        $r['period_start']=$r['date_debut']?:($r['campaign_start']??null);
        $r['period_end']=$r['date_fin']?:($r['campaign_end']??null);
        return $r;
    }
}

if(!function_exists('stagiaDocStageContexts')){
    function stagiaDocStageContexts(PDO $pdo,int $eid,string $role,int $uid=0):array{
        $role=strtoupper($role);
        $sql=stagiaDocBaseContextSql()." WHERE a.statut<>'ANNULEE' ";
        $p=[];

        if($role==='STAGIAIRE'){
            $sql.=" AND sp.user_id=? ";$p[]=$uid;
        }elseif(in_array($role,['ADMIN_ACCUEIL','COORDINATEUR_STAGES','CHEF_SERVICE','ENCADREUR','EVALUATEUR_CLINIQUE'],true)){
            $sql.=" AND a.host_etablissement_id=? ";$p[]=$eid;
            if(in_array($role,['ENCADREUR','EVALUATEUR_CLINIQUE'],true)){
                $sql.=" AND EXISTS(SELECT 1 FROM stage_rotation_supervisors rs JOIN stage_rotations rr ON rr.id=rs.rotation_id WHERE rr.assignment_id=a.id AND rs.user_id=? AND rs.actif=1) ";
                $p[]=$uid;
            }
        }else{
            $sql.=" AND c.owner_etablissement_id=? ";$p[]=$eid;
        }

        $sql.=" ORDER BY a.created_at DESC,a.id DESC LIMIT 200";
        try{
            $s=$pdo->prepare($sql);$s->execute($p);
            $rows=$s->fetchAll(PDO::FETCH_ASSOC);
            return array_map(fn($r)=>stagiaDocHydrateContext($pdo,$r),$rows);
        }catch(Throwable $e){
            error_log('[DOC STAGE CONTEXTS] '.$e->getMessage());
            return [];
        }
    }
}

if(!function_exists('stagiaDocStageContext')){
    function stagiaDocStageContext(PDO $pdo,int $assignmentId):?array{
        if(!$assignmentId)return null;
        try{
            $s=$pdo->prepare(stagiaDocBaseContextSql()." WHERE a.id=? LIMIT 1");
            $s->execute([$assignmentId]);
            $r=$s->fetch(PDO::FETCH_ASSOC);
            return $r?stagiaDocHydrateContext($pdo,$r):null;
        }catch(Throwable $e){
            error_log('[DOC STAGE CONTEXT] '.$e->getMessage());
            return null;
        }
    }
}

if(!function_exists('stagiaDocAttendance')){
    function stagiaDocAttendance(PDO $pdo,int $assignmentId):array{
        if(!$assignmentId||!stagiaDocTableExists($pdo,'stage_attendances'))return [];
        try{
            $sql="
                SELECT att.*
                FROM stage_attendances att
                JOIN stage_rotations r ON r.id=att.rotation_id
                WHERE r.assignment_id=?
                ORDER BY att.date_presence ASC,att.id ASC
            ";
            $s=$pdo->prepare($sql);$s->execute([$assignmentId]);
            $rows=$s->fetchAll(PDO::FETCH_ASSOC);
            if($rows)return $rows;
        }catch(Throwable $e){}

        if(stagiaDocColumnExists($pdo,'stage_attendances','assignment_id')){
            try{
                $s=$pdo->prepare("SELECT * FROM stage_attendances WHERE assignment_id=? ORDER BY date_presence ASC,id ASC");
                $s->execute([$assignmentId]);
                return $s->fetchAll(PDO::FETCH_ASSOC);
            }catch(Throwable $e){}
        }
        return [];
    }
}

if(!function_exists('stagiaDocAttendanceSummary')){
    function stagiaDocAttendanceSummary(array $rows):array{
        $x=['total'=>count($rows),'present'=>0,'retard'=>0,'absent'=>0,'justifie'=>0,'garde'=>0,'validated'=>0];
        foreach($rows as $r){
            $st=strtoupper((string)($r['statut']??''));
            if($st==='PRESENT')$x['present']++;
            elseif($st==='RETARD')$x['retard']++;
            elseif($st==='ABSENT')$x['absent']++;
            elseif($st==='JUSTIFIE'||$st==='JUSTIFIÉ')$x['justifie']++;
            elseif($st==='GARDE')$x['garde']++;
            if(!empty($r['validated_at'])||!empty($r['validated_by'])||!empty($r['valide_at']))$x['validated']++;
        }
        $x['presence_rate']=$x['total']?round((($x['present']+$x['retard']+$x['garde'])/$x['total'])*100,2):null;
        return $x;
    }
}

if(!function_exists('stagiaDocLogbookSummary')){
    function stagiaDocLogbookSummary(PDO $pdo,int $assignmentId):array{
        $out=['total'=>0,'valides'=>0,'soumis'=>0,'rejetes'=>0];
        if(!$assignmentId||!stagiaDocTableExists($pdo,'stage_logbook_entries'))return $out;
        try{
            $s=$pdo->prepare("SELECT le.statut,COUNT(*) n FROM stage_logbook_entries le JOIN stage_rotations r ON r.id=le.rotation_id WHERE r.assignment_id=? GROUP BY le.statut");
            $s->execute([$assignmentId]);
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
                $n=(int)$r['n'];$st=strtoupper((string)$r['statut']);$out['total']+=$n;
                if($st==='VALIDE'||$st==='VALIDÉ')$out['valides']+=$n;
                elseif($st==='SOUMIS')$out['soumis']+=$n;
                elseif($st==='REJETE'||$st==='REJETÉ')$out['rejetes']+=$n;
            }
        }catch(Throwable $e){
            if(stagiaDocColumnExists($pdo,'stage_logbook_entries','assignment_id')){
                try{
                    $s=$pdo->prepare("SELECT statut,COUNT(*) n FROM stage_logbook_entries WHERE assignment_id=? GROUP BY statut");
                    $s->execute([$assignmentId]);
                    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
                        $n=(int)$r['n'];$st=strtoupper((string)$r['statut']);$out['total']+=$n;
                        if($st==='VALIDE'||$st==='VALIDÉ')$out['valides']+=$n;
                        elseif($st==='SOUMIS')$out['soumis']+=$n;
                        elseif($st==='REJETE'||$st==='REJETÉ')$out['rejetes']+=$n;
                    }
                }catch(Throwable $e2){}
            }
        }
        return $out;
    }
}

if(!function_exists('stagiaDocEvaluation')){
    function stagiaDocEvaluation(PDO $pdo,int $assignmentId):array{
        $out=['evaluation'=>null,'scores'=>[]];
        if(!$assignmentId||!stagiaDocTableExists($pdo,'stage_evaluations'))return $out;
        try{
            $s=$pdo->prepare("
                SELECT *
                FROM stage_evaluations
                WHERE assignment_id=?
                  AND statut IN('FINALISEE','VALIDEE','SOUMISE','BROUILLON')
                ORDER BY FIELD(statut,'FINALISEE','VALIDEE','SOUMISE','BROUILLON'),COALESCE(finalized_at,validated_at,updated_at,created_at) DESC,id DESC
                LIMIT 1
            ");
            $s->execute([$assignmentId]);
            $ev=$s->fetch(PDO::FETCH_ASSOC);
        }catch(Throwable $e){$ev=null;}

        if(!$ev)return $out;
        $out['evaluation']=$ev;

        if(stagiaDocTableExists($pdo,'stage_evaluation_scores')){
            try{
                $s=$pdo->prepare("SELECT * FROM stage_evaluation_scores WHERE evaluation_id=? ORDER BY id ASC");
                $s->execute([(int)$ev['id']]);
                $out['scores']=$s->fetchAll(PDO::FETCH_ASSOC);
            }catch(Throwable $e){}
        }
        return $out;
    }
}

if(!function_exists('stagiaDocResultItem')){
    function stagiaDocResultItem(PDO $pdo,int $assignmentId):?array{
        if(!$assignmentId||!stagiaDocTableExists($pdo,'stage_result_items'))return null;
        try{
            $sql="SELECT ri.*,t.statut transmission_statut,t.transmitted_at,t.validated_at FROM stage_result_items ri LEFT JOIN stage_result_transmissions t ON t.id=ri.transmission_id WHERE ri.assignment_id=? ORDER BY ri.id DESC LIMIT 1";
            $s=$pdo->prepare($sql);$s->execute([$assignmentId]);
            $r=$s->fetch(PDO::FETCH_ASSOC);
            return $r?:null;
        }catch(Throwable $e){return null;}
    }
}

if(!function_exists('stagiaDocFullData')){
    function stagiaDocFullData(PDO $pdo,int $assignmentId):?array{
        $ctx=stagiaDocStageContext($pdo,$assignmentId);
        if(!$ctx)return null;
        $att=stagiaDocAttendance($pdo,$assignmentId);
        $eval=stagiaDocEvaluation($pdo,$assignmentId);
        return [
            'context'=>$ctx,
            'attendance'=>$att,
            'attendance_summary'=>stagiaDocAttendanceSummary($att),
            'logbook_summary'=>stagiaDocLogbookSummary($pdo,$assignmentId),
            'evaluation'=>$eval['evaluation'],
            'scores'=>$eval['scores'],
            'result'=>stagiaDocResultItem($pdo,$assignmentId)
        ];
    }
}

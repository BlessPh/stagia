<?php

function stageResultUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

function stageResultName(array $x):string{
    $n=trim(implode(' ',array_filter([$x['nom']??'',$x['postnom']??'',$x['prenom']??''])));
    return $n!==''?$n:'Étudiant #'.($x['student_id']??'-');
}

function stageResultDecision(float $score,int $evals):string{
    if($evals<=0)return 'A_REPRENDRE';
    return $score>=50?'VALIDE':'A_REPRENDRE';
}

/** Normalise une ancienne note /20 en pourcentage pour les indicateurs cliniques. */
function stageResultScorePercent(float $score):float{
    $percent=$score<=20?$score*5:$score;
    return round(max(0,min(100,$percent)),2);
}

function stageResultHostId(PDO $pdo):int{
    return function_exists('currentEtablissementId')?(int)currentEtablissementId($pdo):(int)($_SESSION['etablissement_id']??0);
}

function stageResultAssignments(PDO $pdo,int $hostId,int $campaignId):array{
    $s=$pdo->prepare("
        SELECT DISTINCT
            a.id assignment_id,a.statut assignment_status,
            sp.id student_id,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
            c.id campaign_id,c.code campaign_code,c.titre campaign_title,
            c.owner_etablissement_id university_id,
            uni.nom university_name
        FROM stage_assignments a
        JOIN stage_admissions ad ON ad.id=a.admission_id
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications sa ON sa.id=sr.application_id
        JOIN stage_campaigns c ON c.id=sa.campaign_id
        JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id
        JOIN student_enrollments se ON se.id=sae.enrollment_id
        JOIN student_profiles sp ON sp.id=se.student_id
        WHERE a.host_etablissement_id=?
          AND c.id=?
          AND a.statut<>'ANNULEE'
        ORDER BY sp.nom,sp.postnom,sp.prenom,a.id
    ");
    $s->execute([$hostId,$campaignId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/* Délibération finale officielle du stagiaire. */
function stageResultFinalDeliberation(PDO $pdo,int $assignmentId):?array{
    $s=$pdo->prepare("
        SELECT id,statut,mini_juries_total,mini_juries_valides,
               note_finale,decision,appreciation,date_deliberation,validated_at
        FROM stage_final_deliberations
        WHERE assignment_id=?
          AND statut<>'ANNULEE'
        ORDER BY id DESC
        LIMIT 1
    ");
    $s->execute([$assignmentId]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}

function stageResultMetrics(PDO $pdo,int $assignmentId):array{
    $r=$pdo->prepare("SELECT id,statut FROM stage_rotations WHERE assignment_id=? AND statut<>'ANNULEE'");
    $r->execute([$assignmentId]);
    $rotations=$r->fetchAll(PDO::FETCH_ASSOC);

    $rotIds=array_map(fn($x)=>(int)$x['id'],$rotations);
    $rotationCount=count($rotIds);
    $completedRotationCount=count(array_filter($rotations,fn($x)=>$x['statut']==='TERMINEE'));

    $rotSql=$rotIds?(' OR rotation_id IN('.implode(',',array_fill(0,count($rotIds),'?')).')'):'';
    $rotParams=$rotIds;

    $a=$pdo->prepare("SELECT
            COUNT(*) total,
            SUM(statut='PRESENT') presents,
            SUM(statut='RETARD') retards,
            SUM(statut='ABSENT') absents,
            SUM(statut='JUSTIFIE') justifies,
            SUM(statut='GARDE') gardes
        FROM stage_attendances
        WHERE assignment_id=? $rotSql");
    $a->execute(array_merge([$assignmentId],$rotParams));
    $att=$a->fetch(PDO::FETCH_ASSOC)?:[];

    $j=$pdo->prepare("SELECT COUNT(*) total,SUM(statut='VALIDE') valides
        FROM stage_logbook_entries
        WHERE assignment_id=? $rotSql");
    $j->execute(array_merge([$assignmentId],$rotParams));
    $log=$j->fetch(PDO::FETCH_ASSOC)?:[];

    $jvr=0;
    if($rotIds){
        $q=$pdo->prepare("
            SELECT COUNT(DISTINCT rotation_id)
            FROM stage_logbook_entries
            WHERE statut='VALIDE'
              AND rotation_id IN(".implode(',',array_fill(0,count($rotIds),'?')).")
        ");
        $q->execute($rotIds);
        $jvr=(int)$q->fetchColumn();
    }

    $e=$pdo->prepare("
        SELECT
            e.note_finale,
            SUM(CASE WHEN es.note_max>0 THEN (es.note/es.note_max)*es.poids ELSE 0 END)
                / NULLIF(SUM(CASE WHEN es.note_max>0 THEN es.poids ELSE 0 END),0)*100 AS score_percent
        FROM stage_evaluations e
        JOIN stage_rotations r ON r.id=e.rotation_id
        LEFT JOIN stage_evaluation_scores es ON es.evaluation_id=e.id
        WHERE r.assignment_id=?
          AND e.type_evaluation='FIN_ROTATION'
          AND e.statut='FINALISEE'
          AND e.note_finale IS NOT NULL
        GROUP BY e.id,e.note_finale
    ");
    $e->execute([$assignmentId]);
    $evaluationScores=[];
    foreach($e->fetchAll(PDO::FETCH_ASSOC) as $evaluation){
        $evaluationScores[]=round(
            $evaluation['score_percent']!==null
                ?max(0,min(100,(float)$evaluation['score_percent']))
                :stageResultScorePercent((float)$evaluation['note_finale']),
            2
        );
    }

    $finalEvalCount=0;
    if($rotIds){
        $q=$pdo->prepare("
            SELECT COUNT(DISTINCT e.rotation_id)
            FROM stage_evaluations e
            WHERE e.type_evaluation='FIN_ROTATION'
              AND e.statut='FINALISEE'
              AND e.rotation_id IN(".implode(',',array_fill(0,count($rotIds),'?')).")
        ");
        $q->execute($rotIds);
        $finalEvalCount=(int)$q->fetchColumn();
    }

    $presents=(int)($att['presents']??0);
    $retards=(int)($att['retards']??0);
    $justifies=(int)($att['justifies']??0);
    $gardes=(int)($att['gardes']??0);
    $total=(int)($att['total']??0);
    $presenceRate=$total>0?round((($presents+$retards+$justifies+$gardes)*100)/$total,2):0.00;

    $evals=count($evaluationScores);
    $evaluationScore=$evals>0?round(array_sum($evaluationScores)/$evals,2):0.0;

    $deliberation=stageResultFinalDeliberation($pdo,$assignmentId);
    $deliberationValidated=$deliberation&&($deliberation['statut']??'')==='VALIDEE';

    $finalScore=$deliberationValidated&&$deliberation['note_finale']!==null
        ?(float)$deliberation['note_finale']
        :null;

    $finalDecision=$deliberationValidated
        ?($deliberation['decision']??null)
        :null;

    /* Pré-requis historiques conservés. */
    $clinicalReady=
        $rotationCount>0 &&
        $completedRotationCount===$rotationCount &&
        $total>0 &&
        $jvr===$rotationCount &&
        $finalEvalCount===$rotationCount;

    /* Nouvelle règle : transmission impossible sans délibération finale VALIDÉE. */
    $ready=$clinicalReady&&$deliberationValidated;

    return [
        'rotation_count'=>$rotationCount,
        'completed_rotation_count'=>$completedRotationCount,
        'presence_count'=>$total,
        'present_count'=>$presents,
        'late_count'=>$retards,
        'absent_count'=>(int)($att['absents']??0),
        'justified_count'=>$justifies,
        'guard_count'=>$gardes,
        'presence_rate'=>$presenceRate,
        'journal_count'=>(int)($log['total']??0),
        'validated_journal_count'=>(int)($log['valides']??0),
        'validated_journal_rotation_count'=>$jvr,
        'evaluation_count'=>$evals,
        'final_evaluation_count'=>$finalEvalCount,
        'evaluation_score'=>$evaluationScore,
        'deliberation_id'=>$deliberation?(int)$deliberation['id']:null,
        'deliberation_status'=>$deliberation['statut']??null,
        'mini_juries_total'=>$deliberation?(int)($deliberation['mini_juries_total']??0):0,
        'mini_juries_valides'=>$deliberation?(int)($deliberation['mini_juries_valides']??0):0,
        'deliberation_date'=>$deliberation['date_deliberation']??null,
        'final_score'=>$finalScore,
        'decision'=>$finalDecision,
        'clinical_ready'=>$clinicalReady,
        'ready'=>$ready
    ];
}

function stageResultCampaignStats(PDO $pdo,int $hostId,int $campaignId):array{
    $items=stageResultAssignments($pdo,$hostId,$campaignId);

    $s=[
        'students'=>count($items),'rotations'=>0,'completed_rotations'=>0,
        'presence_count'=>0,'present_like'=>0,
        'journal_count'=>0,'validated_journal_count'=>0,'validated_journal_rotations'=>0,
        'evaluation_count'=>0,'final_evaluation_count'=>0,
        'deliberation_count'=>0,'validated_deliberation_count'=>0,
        'score_sum'=>0,'score_n'=>0,
        'clinical_ready_students'=>0,'ready_students'=>0
    ];

    foreach($items as $x){
        $m=stageResultMetrics($pdo,(int)$x['assignment_id']);

        $s['rotations']+=$m['rotation_count'];
        $s['completed_rotations']+=$m['completed_rotation_count'];
        $s['presence_count']+=$m['presence_count'];
        $s['present_like']+=$m['present_count']+$m['late_count']+$m['justified_count']+$m['guard_count'];
        $s['journal_count']+=$m['journal_count'];
        $s['validated_journal_count']+=$m['validated_journal_count'];
        $s['validated_journal_rotations']+=$m['validated_journal_rotation_count'];
        $s['evaluation_count']+=$m['evaluation_count'];
        $s['final_evaluation_count']+=$m['final_evaluation_count'];

        if(!empty($m['deliberation_id']))$s['deliberation_count']++;
        if(($m['deliberation_status']??'')==='VALIDEE')$s['validated_deliberation_count']++;

        if($m['final_score']!==null){
            $s['score_sum']+=(float)$m['final_score'];
            $s['score_n']++;
        }

        if(!empty($m['clinical_ready']))$s['clinical_ready_students']++;
        if(!empty($m['ready']))$s['ready_students']++;
    }

    $s['presence_rate']=$s['presence_count']>0
        ?round(($s['present_like']*100)/$s['presence_count'],2)
        :0;

    $s['average_score']=$s['score_n']>0
        ?round($s['score_sum']/$s['score_n'],2)
        :0;

    $s['clinical_ready']=$s['students']>0&&$s['clinical_ready_students']===$s['students'];

    /* Campagne transmissible seulement si tous les stagiaires sont prêts. */
    $s['ready']=$s['students']>0&&$s['ready_students']===$s['students'];

    return $s;
}

function stageResultLatestTransmission(PDO $pdo,int $hostId,int $campaignId):?array{
    $s=$pdo->prepare("SELECT * FROM stage_result_transmissions WHERE host_etablissement_id=? AND campaign_id=? ORDER BY id DESC LIMIT 1");
    $s->execute([$hostId,$campaignId]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}

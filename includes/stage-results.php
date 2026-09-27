<?php

/** Génère l'UUID d'une transmission de résultat. */
function stageResultUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&15)|64);$d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}
/** Construit le nom affichable de l'étudiant avec une valeur de repli. */
function stageResultName(array $x):string{
    $n=trim(implode(' ',array_filter([$x['nom']??'',$x['postnom']??'',$x['prenom']??''])));
    return $n!==''?$n:'Étudiant #'.($x['student_id']??'-');
}
/** Déduit la décision métier à partir de la note et de l'existence d'évaluations. */
function stageResultDecision(float $score,int $evals):string{
    if($evals<=0)return 'A_REPRENDRE';
    return $score>=50?'VALIDE':'A_REPRENDRE';
}
/** Résout l'établissement d'accueil courant pour les résultats. */
function stageResultHostId(PDO $pdo):int{
    return function_exists('currentEtablissementId')?(int)currentEtablissementId($pdo):(int)($_SESSION['etablissement_id']??0);
}
/** Liste les affectations d'une campagne avec l'identité minimale des stagiaires. */
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
/** Agrège présences, journaux et évaluations d'une affectation en indicateurs de résultat. */
function stageResultMetrics(PDO $pdo,int $assignmentId):array{
    $r=$pdo->prepare("SELECT id FROM stage_rotations WHERE assignment_id=? AND statut<>'ANNULEE'");
    $r->execute([$assignmentId]);
    $rotIds=array_map('intval',$r->fetchAll(PDO::FETCH_COLUMN));
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

    $e=$pdo->prepare("
        SELECT COUNT(*) total,ROUND(AVG(e.note_finale),2) score
        FROM stage_evaluations e
        JOIN stage_rotations r ON r.id=e.rotation_id
        WHERE r.assignment_id=?
          AND e.statut IN('VALIDEE','FINALISEE')
    ");
    $e->execute([$assignmentId]);
    $ev=$e->fetch(PDO::FETCH_ASSOC)?:[];

    $presents=(int)($att['presents']??0);
    $retards=(int)($att['retards']??0);
    $justifies=(int)($att['justifies']??0);
    $gardes=(int)($att['gardes']??0);
    $total=(int)($att['total']??0);
    $presenceRate=$total>0?round((($presents+$retards+$justifies+$gardes)*100)/$total,2):0.00;
    $score=(float)($ev['score']??0);
    $evals=(int)($ev['total']??0);

    return [
        'rotation_count'=>count($rotIds),
        'presence_count'=>$total,
        'present_count'=>$presents,
        'late_count'=>$retards,
        'absent_count'=>(int)($att['absents']??0),
        'justified_count'=>$justifies,
        'guard_count'=>$gardes,
        'presence_rate'=>$presenceRate,
        'journal_count'=>(int)($log['total']??0),
        'validated_journal_count'=>(int)($log['valides']??0),
        'evaluation_count'=>$evals,
        'final_score'=>$score,
        'decision'=>stageResultDecision($score,$evals)
    ];
}
/** Cumule les indicateurs des stagiaires afin d'alimenter la synthèse de campagne. */
function stageResultCampaignStats(PDO $pdo,int $hostId,int $campaignId):array{
    $items=stageResultAssignments($pdo,$hostId,$campaignId);
    $s=[
        'students'=>count($items),'rotations'=>0,'presence_count'=>0,'present_like'=>0,
        'journal_count'=>0,'validated_journal_count'=>0,'evaluation_count'=>0,'score_sum'=>0,'score_n'=>0
    ];
    foreach($items as $x){
        $m=stageResultMetrics($pdo,(int)$x['assignment_id']);
        $s['rotations']+=$m['rotation_count'];
        $s['presence_count']+=$m['presence_count'];
        $s['present_like']+=$m['present_count']+$m['late_count']+$m['justified_count']+$m['guard_count'];
        $s['journal_count']+=$m['journal_count'];
        $s['validated_journal_count']+=$m['validated_journal_count'];
        $s['evaluation_count']+=$m['evaluation_count'];
        if($m['evaluation_count']>0){$s['score_sum']+=$m['final_score'];$s['score_n']++;}
    }
    $s['presence_rate']=$s['presence_count']>0?round(($s['present_like']*100)/$s['presence_count'],2):0;
    $s['average_score']=$s['score_n']>0?round($s['score_sum']/$s['score_n'],2):0;
    $s['ready']=$s['students']>0&&$s['evaluation_count']>0;
    return $s;
}
/** Retourne la plus récente transmission de résultats de la campagne. */
function stageResultLatestTransmission(PDO $pdo,int $hostId,int $campaignId):?array{
    $s=$pdo->prepare("SELECT * FROM stage_result_transmissions WHERE host_etablissement_id=? AND campaign_id=? ORDER BY id DESC LIMIT 1");
    $s->execute([$hostId,$campaignId]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
}

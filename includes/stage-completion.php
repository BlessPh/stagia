<?php

/* =========================================================
   STAGIA-RDC - MOTEUR DE CLÔTURE DE STAGE
========================================================= */

/** Génère l'identifiant UUID de synthèse de clôture. */
function completionUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&15)|64);
    $d[8]=chr((ord($d[8])&63)|128);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($d),4)
    );
}

/* =========================================================
   SYNCHRONISER LA CLÔTURE D'UNE AFFECTATION
========================================================= */
/**
 * Recalcule la clôture à partir des rotations, présences, journaux,
 * évaluations, délibération finale et validation universitaire.
 *
 * Une décision de clôture déjà définitive n'est jamais réouverte.
 */
function syncStageCompletion(PDO $pdo,int $assignmentId):array{

    /* =====================================================
       1. AFFECTATION + ÉTUDIANT
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            a.id AS assignment_id,
            a.admission_id,
            a.host_etablissement_id,
            a.date_debut AS assignment_start,
            a.date_fin AS assignment_end,
            sa.campaign_id,
            se.student_id

        FROM stage_assignments a

        INNER JOIN stage_admissions ad
            ON ad.id=a.admission_id

        INNER JOIN stage_reservations sr
            ON sr.id=ad.reservation_id

        INNER JOIN stage_applications sa
            ON sa.id=sr.application_id

        INNER JOIN student_academic_enrollments sae
            ON sae.id=sa.academic_enrollment_id

        INNER JOIN student_enrollments se
            ON se.id=sae.enrollment_id

        WHERE a.id=?
        LIMIT 1
    ");

    $stmt->execute([$assignmentId]);
    $base=$stmt->fetch(PDO::FETCH_ASSOC);

    if(!$base)
        throw new RuntimeException('Affectation introuvable.');

    $hostId=(int)$base['host_etablissement_id'];
    $campaignId=(int)$base['campaign_id'];

    /* =====================================================
       2. ROTATIONS
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(statut='TERMINEE') AS terminees
        FROM stage_rotations
        WHERE assignment_id=?
          AND statut<>'ANNULEE'
    ");

    $stmt->execute([$assignmentId]);
    $rot=$stmt->fetch(PDO::FETCH_ASSOC);

    $totalRotations=(int)($rot['total']??0);
    $rotationsTerminees=(int)($rot['terminees']??0);

    /* =====================================================
       3. PRÉSENCES
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(statut IN('PRESENT','GARDE')) AS presences,
            SUM(statut='RETARD') AS retards,
            SUM(statut='ABSENT') AS absences,
            SUM(statut='JUSTIFIE') AS justifiees,
            COUNT(DISTINCT rotation_id) AS rotations_couvertes

        FROM stage_attendances

        WHERE assignment_id=?
    ");

    $stmt->execute([$assignmentId]);
    $att=$stmt->fetch(PDO::FETCH_ASSOC);

    $attendanceTotal=(int)($att['total']??0);
    $presences=(int)($att['presences']??0);
    $retards=(int)($att['retards']??0);
    $absences=(int)($att['absences']??0);
    $justifiees=(int)($att['justifiees']??0);
    $attendanceRotations=(int)($att['rotations_couvertes']??0);

    /*
     * PRESENT + GARDE sont déjà comptés dans $presences.
     * RETARD est également considéré comme présence effective.
     */
    $effective=$presences+$retards;

    $tauxPresence=$attendanceTotal>0
        ?round(($effective/$attendanceTotal)*100,2)
        :null;

    /* =====================================================
       4. JOURNAUX DE STAGE
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(statut='VALIDE') AS valides,
            COUNT(
                DISTINCT CASE
                    WHEN statut='VALIDE' THEN rotation_id
                END
            ) AS rotations_validees

        FROM stage_logbook_entries

        WHERE assignment_id=?
    ");

    $stmt->execute([$assignmentId]);
    $log=$stmt->fetch(PDO::FETCH_ASSOC);

    $totalJournaux=(int)($log['total']??0);
    $journauxValides=(int)($log['valides']??0);
    $logbookRotations=(int)($log['rotations_validees']??0);

    /* =====================================================
       5. ÉVALUATIONS DE FIN DE ROTATION FINALISÉES
       -----------------------------------------------------
       Conservées comme prérequis clinique historique.
       Elles ne définissent plus la note finale officielle.
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            COUNT(DISTINCT rotation_id) AS rotations_evaluees,
            AVG(
                COALESCE(
                    score_percent,
                    LEAST(100,GREATEST(0,CASE WHEN note_finale<=20 THEN note_finale*5 ELSE note_finale END))
                )
            ) AS moyenne
        FROM (
            SELECT
                e.rotation_id,
                e.note_finale,
                SUM(CASE WHEN es.note_max>0 THEN (es.note/es.note_max)*es.poids ELSE 0 END)
                    / NULLIF(SUM(CASE WHEN es.note_max>0 THEN es.poids ELSE 0 END),0)*100 AS score_percent
            FROM stage_evaluations e
            LEFT JOIN stage_evaluation_scores es ON es.evaluation_id=e.id
            WHERE e.assignment_id=?
              AND e.type_evaluation='FIN_ROTATION'
              AND e.statut='FINALISEE'
              AND e.note_finale IS NOT NULL
            GROUP BY e.id,e.rotation_id,e.note_finale
        ) final_evaluations
    ");

    $stmt->execute([$assignmentId]);
    $eval=$stmt->fetch(PDO::FETCH_ASSOC);

    $evaluatedRotations=(int)($eval['rotations_evaluees']??0);

    $evaluationAverage=$eval['moyenne']!==null
        ?round((float)$eval['moyenne'],2)
        :null;

    /* =====================================================
       6. DERNIÈRE ÉVALUATION DE FIN FINALISÉE
       -----------------------------------------------------
       Conservée pour compatibilité avec stage_completions.
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT id

        FROM stage_evaluations

        WHERE assignment_id=?
          AND type_evaluation='FIN_ROTATION'
          AND statut='FINALISEE'

        ORDER BY finalized_at DESC,id DESC
        LIMIT 1
    ");

    $stmt->execute([$assignmentId]);

    $finalEvaluationId=$stmt->fetchColumn();
    $finalEvaluationId=$finalEvaluationId
        ?(int)$finalEvaluationId
        :null;

    /* =====================================================
       7. DÉLIBÉRATION FINALE HOSPITALIÈRE
       -----------------------------------------------------
       La note finale officielle vient désormais de cette
       délibération et non de la moyenne des évaluations.
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            id,
            statut,
            mini_juries_total,
            mini_juries_valides,
            note_finale,
            decision,
            appreciation,
            date_deliberation,
            validated_at

        FROM stage_final_deliberations

        WHERE assignment_id=?
          AND host_etablissement_id=?
          AND statut<>'ANNULEE'

        ORDER BY id DESC
        LIMIT 1
    ");

    $stmt->execute([$assignmentId,$hostId]);
    $deliberation=$stmt->fetch(PDO::FETCH_ASSOC)?:null;

    $deliberationValidated=
        $deliberation
        &&($deliberation['statut']??'')==='VALIDEE';

    $deliberationId=$deliberation
        ?(int)$deliberation['id']
        :null;

    $noteFinale=
        $deliberationValidated
        &&$deliberation['note_finale']!==null
            ?round((float)$deliberation['note_finale'],2)
            :null;

    $decisionFinale=
        $deliberationValidated
            ?($deliberation['decision']??null)
            :null;

    $deliberationAppreciation=
        $deliberationValidated
            ?($deliberation['appreciation']??null)
            :null;

    /* =====================================================
       8. VALIDATION ACADÉMIQUE DE LA TRANSMISSION
       -----------------------------------------------------
       Une transmission ARCHIVÉE ne compte comme validée
       que si validated_at est réellement renseigné.
       Cela évite qu'un simple RECU -> ARCHIVE contourne
       la validation académique.
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            t.id AS transmission_id,
            t.statut AS transmission_status,
            t.transmitted_at,
            t.validated_at AS university_validated_at,

            ri.final_score AS transmitted_score,
            ri.decision AS transmitted_decision,
            ri.appreciation AS transmitted_appreciation

        FROM stage_result_items ri

        INNER JOIN stage_result_transmissions t
            ON t.id=ri.transmission_id

        WHERE ri.assignment_id=?
          AND t.host_etablissement_id=?
          AND t.campaign_id=?
          AND t.statut IN('VALIDE','ARCHIVE')
          AND t.validated_at IS NOT NULL

        ORDER BY t.validated_at DESC,t.id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $assignmentId,
        $hostId,
        $campaignId
    ]);

    $academicResult=$stmt->fetch(PDO::FETCH_ASSOC)?:null;

    $academicValidated=(bool)$academicResult;
    $transmissionId=$academicResult
        ?(int)$academicResult['transmission_id']
        :null;

    /* =====================================================
       9. CONDITIONS DE CLÔTURE
    ====================================================== */
    $blockers=[];
    $today=date('Y-m-d');

    /* Période générale du stage */
    if(
        !empty($base['assignment_end']) &&
        $today<$base['assignment_end']
    ){
        $blockers[]=
            'La période générale du stage se termine le '.
            date('d/m/Y',strtotime($base['assignment_end'])).'.';
    }

    /* Rotations */
    if($totalRotations===0){

        $blockers[]='Aucune rotation enregistrée.';

    }elseif($rotationsTerminees<$totalRotations){

        $blockers[]=
            ($totalRotations-$rotationsTerminees).
            ' rotation(s) non terminée(s).';
    }

    /* Présences */
    if(
        $totalRotations>0 &&
        $attendanceRotations<$totalRotations
    ){
        $blockers[]=
            'Présences manquantes sur certaines rotations.';
    }

    /* Journaux */
    if(
        $totalRotations>0 &&
        $logbookRotations<$totalRotations
    ){
        $blockers[]=
            'Journal validé manquant sur certaines rotations.';
    }

    if($totalJournaux>$journauxValides){
        $blockers[]=
            'Certains journaux ne sont pas encore validés.';
    }

    /* Évaluations finales */
    if(
        $totalRotations>0 &&
        $evaluatedRotations<$totalRotations
    ){
        $blockers[]=
            'Évaluation de fin finalisée manquante sur certaines rotations.';
    }

    /* Délibération finale */
    if(!$deliberationValidated){
        $blockers[]=
            'La délibération finale hospitalière doit être validée.';
    }

    /*
     * Validation universitaire :
     * le processus fonctionnel n'est terminé qu'après
     * validation académique du résultat transmis.
     */
    if(!$academicValidated){
        $blockers[]=
            'Le résultat transmis doit être validé par l’université avant la clôture définitive.';
    }

    /*
     * Une validation universitaire antérieure à la délibération
     * finale ne peut pas valider le nouveau résultat officiel.
     * Les anciennes transmissions restent historiques mais ne
     * satisfont pas le nouveau workflow.
     */
    if(
        $academicValidated &&
        $deliberationValidated &&
        !empty($academicResult['university_validated_at']) &&
        !empty($deliberation['validated_at']) &&
        strtotime($academicResult['university_validated_at'])<
        strtotime($deliberation['validated_at'])
    ){
        $blockers[]=
            'La validation universitaire est antérieure à la délibération finale. Une transmission conforme au nouveau résultat est requise.';
    }

    /*
     * Contrôle de cohérence entre le résultat délibéré et
     * l'instantané réellement validé par l'université.
     */
    if(
        $academicValidated &&
        $deliberationValidated
    ){
        $transmittedScore=
            $academicResult['transmitted_score']!==null
                ?round((float)$academicResult['transmitted_score'],2)
                :null;

        $transmittedDecision=
            strtoupper(trim((string)($academicResult['transmitted_decision']??'')));

        $officialDecision=
            strtoupper(trim((string)($decisionFinale??'')));

        if(
            $noteFinale===null ||
            $transmittedScore===null ||
            abs($transmittedScore-$noteFinale)>0.01
        ){
            $blockers[]=
                'La note validée par l’université ne correspond pas à la délibération finale.';
        }

        if(
            $officialDecision==='' ||
            $transmittedDecision!==$officialDecision
        ){
            $blockers[]=
                'La décision validée par l’université ne correspond pas à la délibération finale.';
        }
    }

    $ready=empty($blockers);

    $newStatus=$ready
        ?'PRET'
        :'EN_PREPARATION';

    /* =====================================================
       10. RECHERCHER UNE CLÔTURE EXISTANTE
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT id,statut,note_finale,appreciation_finale
        FROM stage_completions
        WHERE assignment_id=?
        LIMIT 1
    ");

    $stmt->execute([$assignmentId]);
    $existing=$stmt->fetch(PDO::FETCH_ASSOC);

    /*
     * Une clôture définitive ne doit plus être recalculée.
     * Cette règle préserve les clôtures historiques existantes.
     */
    $locked=$existing && in_array(
        $existing['statut'],
        ['VALIDE','REFUSE','ANNULE'],
        true
    );

    /* =====================================================
       11. SYNCHRONISER stage_completions
       -----------------------------------------------------
       Aucun changement de schéma SQL n'est nécessaire.
       note_finale reçoit maintenant la note de délibération.
    ====================================================== */
    if($locked){

        $completionId=(int)$existing['id'];

    }elseif($existing){

        $completionId=(int)$existing['id'];

        $stmt=$pdo->prepare("
            UPDATE stage_completions
            SET
                statut=?,
                total_rotations=?,
                rotations_terminees=?,

                total_presences=?,
                total_retards=?,
                total_absences=?,
                total_justifiees=?,
                taux_presence=?,

                total_journaux=?,
                journaux_valides=?,

                final_evaluation_id=?,
                note_finale=?,

                prepared_at=CASE
                    WHEN ?='PRET'
                    THEN COALESCE(prepared_at,NOW())
                    ELSE NULL
                END

            WHERE id=?
        ");

        $stmt->execute([
            $newStatus,

            $totalRotations,
            $rotationsTerminees,

            $presences,
            $retards,
            $absences,
            $justifiees,
            $tauxPresence,

            $totalJournaux,
            $journauxValides,

            $finalEvaluationId,
            $noteFinale,

            $newStatus,
            $completionId
        ]);

    }else{

        $stmt=$pdo->prepare("
            INSERT INTO stage_completions(
                uuid,
                assignment_id,
                admission_id,
                student_id,
                host_etablissement_id,
                campaign_id,
                statut,

                total_rotations,
                rotations_terminees,

                total_presences,
                total_retards,
                total_absences,
                total_justifiees,
                taux_presence,

                total_journaux,
                journaux_valides,

                final_evaluation_id,
                note_finale,

                prepared_at
            )
            VALUES(
                ?,?,?,?,?,?,?,
                ?,?,
                ?,?,?,?,?,
                ?,?,
                ?,?,
                CASE
                    WHEN ?='PRET'
                    THEN NOW()
                    ELSE NULL
                END
            )
        ");

        $stmt->execute([
            completionUuid(),

            $assignmentId,
            $base['admission_id'],
            $base['student_id'],
            $hostId,
            $campaignId,

            $newStatus,

            $totalRotations,
            $rotationsTerminees,

            $presences,
            $retards,
            $absences,
            $justifiees,
            $tauxPresence,

            $totalJournaux,
            $journauxValides,

            $finalEvaluationId,
            $noteFinale,

            $newStatus
        ]);

        $completionId=(int)$pdo->lastInsertId();
    }

    $resultNoteFinale=$noteFinale;
    $resultDecisionFinale=$decisionFinale;
    $resultAppreciation=$deliberationAppreciation;
    if($locked&&!$deliberationValidated){
        $resultNoteFinale=$existing['note_finale']!==null?(float)$existing['note_finale']:null;
        $resultDecisionFinale=$academicResult['transmitted_decision']??null;
        $resultAppreciation=$existing['appreciation_finale']??($academicResult['transmitted_appreciation']??null);
    }

    /* =====================================================
       12. RÉSULTAT
    ====================================================== */
    return [
        'id'=>$completionId,

        'statut'=>$locked
            ?$existing['statut']
            :$newStatus,

        'ready'=>$locked
            ?$existing['statut']==='VALIDE'
            :$ready,

        'assignment_start'=>$base['assignment_start'],
        'assignment_end'=>$base['assignment_end'],

        'total_rotations'=>$totalRotations,
        'rotations_terminees'=>$rotationsTerminees,

        'total_presences'=>$presences,
        'total_retards'=>$retards,
        'total_absences'=>$absences,
        'total_justifiees'=>$justifiees,
        'taux_presence'=>$tauxPresence,

        'total_journaux'=>$totalJournaux,
        'journaux_valides'=>$journauxValides,

        'evaluations_finales'=>$evaluatedRotations,
        'evaluation_average'=>$evaluationAverage,

        /* Compatibilité historique. */
        'final_evaluation_id'=>$finalEvaluationId,

        /* Résultat officiel actuel. */
        'note_finale'=>$resultNoteFinale,
        'decision_finale'=>$resultDecisionFinale,

        'deliberation_id'=>$deliberationId,
        'deliberation_status'=>$deliberation['statut']??null,
        'deliberation_date'=>$deliberation['date_deliberation']??null,
        'deliberation_validated_at'=>$deliberation['validated_at']??null,
        'deliberation_appreciation'=>$resultAppreciation,

        'transmission_id'=>$transmissionId,
        'university_validation_status'=>$academicResult['transmission_status']??null,
        'university_validated_at'=>$academicResult['university_validated_at']??null,

        'blockers'=>$locked
            ?[]
            :$blockers
    ];
}

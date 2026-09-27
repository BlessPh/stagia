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
 * Recalcule la clôture à partir des rotations, présences, journaux et évaluations,
 * puis crée ou met à jour la synthèse sans réouvrir une décision définitive.
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
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT
            COUNT(DISTINCT rotation_id) AS rotations_evaluees,
            AVG(note_finale) AS moyenne

        FROM stage_evaluations

        WHERE assignment_id=?
          AND type_evaluation='FIN_ROTATION'
          AND statut='FINALISEE'
    ");

    $stmt->execute([$assignmentId]);
    $eval=$stmt->fetch(PDO::FETCH_ASSOC);

    $evaluatedRotations=(int)($eval['rotations_evaluees']??0);

    $noteFinale=$eval['moyenne']!==null
        ?round((float)$eval['moyenne'],2)
        :null;

    /* =====================================================
       6. DERNIÈRE ÉVALUATION DE FIN FINALISÉE
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
       7. CONDITIONS DE CLÔTURE
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

    $ready=empty($blockers);
    $newStatus=$ready
        ?'PRET'
        :'EN_PREPARATION';

    /* =====================================================
       8. RECHERCHER UNE CLÔTURE EXISTANTE
    ====================================================== */
    $stmt=$pdo->prepare("
        SELECT id,statut
        FROM stage_completions
        WHERE assignment_id=?
        LIMIT 1
    ");

    $stmt->execute([$assignmentId]);
    $existing=$stmt->fetch(PDO::FETCH_ASSOC);

    /*
     * Une clôture définitive ne doit plus être recalculée.
     */
    $locked=$existing && in_array(
        $existing['statut'],
        ['VALIDE','REFUSE','ANNULE'],
        true
    );

    /* =====================================================
       9. SYNCHRONISER stage_completions
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
            $base['host_etablissement_id'],
            $base['campaign_id'],

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

    /* =====================================================
       10. RÉSULTAT
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

        'final_evaluation_id'=>$finalEvaluationId,
        'note_finale'=>$noteFinale,

        'blockers'=>$locked
            ?[]
            :$blockers
    ];
}

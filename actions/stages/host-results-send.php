<?php
/**
 * Endpoint AJAX d'envoi d'un relevé de résultats hospitaliers vers l'université propriétaire de la campagne.
 * Un instantané détaillé des indicateurs de chaque stagiaire est créé à l'envoi.
 */
require_once __DIR__.'/../../includes/session-security.php';

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/stage-results.php';

requireAjaxRole(['ADMIN_ACCUEIL','COORDINATEUR_STAGES','AUTORITE_HOSPITALIERE']);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /*
     * L'envoi exige désormais le parcours complet :
     * rotations / présences / journaux / évaluations FIN_ROTATION,
     * mini-jurys validés puis délibération finale hospitalière VALIDÉE.
     */
    $hostId=stageResultHostId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $campaignId=(int)($_POST['campaign_id']??0);
    $observation=trim((string)($_POST['observation']??''));

    if(!$hostId||!$uid||!$campaignId)
        jsonResponse(false,'Informations incomplètes.',[],422);

    $assignments=stageResultAssignments($pdo,$hostId,$campaignId);

    if(!$assignments)
        jsonResponse(false,'Aucun stagiaire à transmettre pour cette session.',[],422);

    /*
     * On conserve le contrôle historique des évaluations,
     * mais il ne suffit plus à autoriser la transmission.
     */
    $metrics=stageResultCampaignStats($pdo,$hostId,$campaignId);

    if((int)($metrics['evaluation_count']??0)<=0)
        jsonResponse(false,'Aucune évaluation validée ou finalisée à transmettre.',[],422);

    /*
     * Nouvelle règle métier :
     * la campagne n'est transmissible que si tous ses stagiaires
     * ont satisfait les prérequis et possèdent une délibération finale VALIDÉE.
     */
    if(empty($metrics['ready'])){
        $readyStudents=(int)($metrics['ready_students']??0);
        $students=(int)($metrics['students']??count($assignments));
        $validatedDeliberations=(int)($metrics['validated_deliberation_count']??0);

        jsonResponse(
            false,
            "Transmission impossible : tous les stagiaires doivent terminer leur parcours et avoir une délibération finale validée ({$validatedDeliberations}/{$students} délibération(s) validée(s)).",
            [
                'students'=>$students,
                'ready_students'=>$readyStudents,
                'validated_deliberations'=>$validatedDeliberations
            ],
            422
        );
    }

    /*
     * Une transmission déjà envoyée, reçue, validée ou archivée
     * reste historique et ne doit jamais être écrasée.
     */
    $latest=stageResultLatestTransmission($pdo,$hostId,$campaignId);

    if($latest&&in_array($latest['statut'],['ENVOYE','RECU','VALIDE','ARCHIVE'],true))
        jsonResponse(false,'Les résultats de cette session ont déjà été transmis.',[],422);

    $universityId=(int)$assignments[0]['university_id'];

    /* La transmission et toutes ses lignes de résultat sont créées de manière atomique. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        INSERT INTO stage_result_transmissions(
            uuid,campaign_id,host_etablissement_id,university_etablissement_id,
            statut,transmitted_by,transmitted_at,observation
        ) VALUES(?,?,?,?, 'ENVOYE',?,NOW(),?)
    ");

    $s->execute([
        stageResultUuid(),
        $campaignId,
        $hostId,
        $universityId,
        $uid,
        $observation?:null
    ]);

    $transmissionId=(int)$pdo->lastInsertId();

    $ins=$pdo->prepare("
        INSERT INTO stage_result_items(
            transmission_id,assignment_id,student_id,rotation_count,presence_count,
            present_count,late_count,absent_count,justified_count,guard_count,presence_rate,
            journal_count,validated_journal_count,evaluation_count,final_score,decision,appreciation
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");

    /*
     * Les métriques sont figées par stagiaire afin que le relevé transmis reste traçable.
     *
     * IMPORTANT :
     * - final_score vient désormais de stage_final_deliberations.note_finale ;
     * - decision vient désormais de stage_final_deliberations.decision ;
     * - la moyenne des évaluations reste un indicateur de suivi, pas le résultat officiel.
     */
    foreach($assignments as $a){

        $m=stageResultMetrics($pdo,(int)$a['assignment_id']);

        /*
         * Sécurité supplémentaire côté serveur :
         * même si l'interface a autorisé l'envoi, aucun stagiaire incomplet
         * ne peut être inséré dans une nouvelle transmission.
         */
        if(empty($m['ready'])){
            throw new RuntimeException(
                'Transmission interrompue : le dossier de '.stageResultName($a).' n’est pas encore prêt.'
            );
        }

        if(($m['deliberation_status']??'')!=='VALIDEE'){
            throw new RuntimeException(
                'Transmission interrompue : la délibération finale de '.stageResultName($a).' n’est pas validée.'
            );
        }

        if($m['final_score']===null){
            throw new RuntimeException(
                'Transmission interrompue : aucune note finale de délibération pour '.stageResultName($a).'.'
            );
        }

        if(empty($m['decision'])){
            throw new RuntimeException(
                'Transmission interrompue : aucune décision finale de délibération pour '.stageResultName($a).'.'
            );
        }

        /*
         * L'appréciation conserve les indicateurs historiques
         * et ajoute la traçabilité de la délibération finale.
         */
        $app=
            'Présence '.$m['presence_rate'].'%'.
            ' · Journaux validés '.$m['validated_journal_count'].'/'.$m['journal_count'].
            ' · Évaluations '.$m['final_evaluation_count'].'/'.$m['rotation_count'].
            ' · Mini-jurys '.$m['mini_juries_valides'].'/'.$m['mini_juries_total'].
            ' · Délibération '.($m['deliberation_date']?:'-').
            ' · Note finale '.$m['final_score'].'%'.
            ' · Décision '.$m['decision'];

        $ins->execute([
            $transmissionId,
            (int)$a['assignment_id'],
            (int)$a['student_id'],

            $m['rotation_count'],
            $m['presence_count'],
            $m['present_count'],
            $m['late_count'],
            $m['absent_count'],
            $m['justified_count'],
            $m['guard_count'],
            $m['presence_rate'],

            $m['journal_count'],
            $m['validated_journal_count'],
            $m['evaluation_count'],

            /* Résultat officiel issu de la délibération finale. */
            $m['final_score'],
            $m['decision'],
            $app
        ]);
    }

    $pdo->commit();

    jsonResponse(
        true,
        'Résultats issus des délibérations finales transmis à l’université.',
        ['transmission_id'=>$transmissionId]
    );

}catch(Throwable $e){
    /* Un échec annule l'entête et toutes les lignes de transmission. */
    if(isset($pdo)&&$pdo->inTransaction())
        $pdo->rollBack();

    error_log('[HOST RESULTS SEND] '.$e->getMessage());

    jsonResponse(
        false,
        'Erreur transmission : '.$e->getMessage(),
        [],
        422
    );
}

<?php
declare(strict_types=1);

require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/stage-results.php';

$checks=0;
$check=static function(bool $condition,string $message)use(&$checks):void{
    if(!$condition)throw new RuntimeException($message);
    $checks++;
};

foreach(['stage_mini_juries','stage_mini_jury_rotations','stage_final_deliberations'] as $table){
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    $check((int)$stmt->fetchColumn()===1,'Table manquante : '.$table);
}

$stmt=$pdo->query("SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stage_result_items' AND column_name='decision'");
$check(str_contains((string)$stmt->fetchColumn(),"'AJOURNE'"),'La décision AJOURNE n’est pas transmissible.');

$check(stageResultScorePercent(16.0)===80.0,'Conversion historique /20 invalide.');
$check(stageResultScorePercent(84.5)===84.5,'Pourcentage moderne altéré.');

$assignmentId=(int)$pdo->query('SELECT id FROM stage_assignments ORDER BY id LIMIT 1')->fetchColumn();
if($assignmentId>0){
    $metrics=stageResultMetrics($pdo,$assignmentId);
    $deliberation=stageResultFinalDeliberation($pdo,$assignmentId);
    $validated=$deliberation&&($deliberation['statut']??'')==='VALIDEE';
    $check(array_key_exists('clinical_ready',$metrics),'Indicateur clinique absent.');
    $check(array_key_exists('deliberation_status',$metrics),'Statut de délibération absent.');
    $check($validated?($metrics['final_score']!==null):($metrics['final_score']===null),'La note officielle ne respecte pas la validation de la délibération.');
}

echo $checks." contrôles du workflow de délibération réussis.\n";

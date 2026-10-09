<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-task.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);

$filterValues=static function(array $names,array $allowed):array{
    $values=[];
    foreach($names as $name){
        if(!array_key_exists($name,$_GET))continue;
        $raw=is_array($_GET[$name])?$_GET[$name]:[$_GET[$name]];
        foreach($raw as $value)foreach(explode(',',(string)$value) as $part){
            $part=strtoupper(trim($part));if($part!=='')$values[]=$part;
        }
    }
    $values=array_values(array_unique($values));$invalid=array_values(array_diff($values,$allowed));
    if($invalid)apiResponse(false,'Filtre invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowed],422);
    return $values;
};
$dateFilter=static function(string $name):string{
    $value=trim((string)($_GET[$name]??''));if($value==='')return '';
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if(!$parsed||$parsed->format('Y-m-d')!==$value)apiResponse(false,'Filtre '.$name.' invalide.',[],422);
    return $value;
};
$statuses=$filterValues(['status','statuses'],['A_FAIRE','EN_COURS','TERMINEE','A_REVOIR','VALIDEE','ANNULEE']);
$priorities=$filterValues(['priority','priorities'],['BASSE','NORMALE','HAUTE','URGENTE']);
$dueFrom=$dateFilter('due_from');$dueTo=$dateFilter('due_to');
if($dueFrom!==''&&$dueTo!==''&&$dueFrom>$dueTo)apiResponse(false,'due_from doit précéder due_to.',[],422);

try{
    $data=stageTaskStudentApiList($pdo,(int)$student['student_id'],[
        'statuses'=>$statuses,'priorities'=>$priorities,
        'assignment_uuid'=>trim((string)($_GET['assignment_uuid']??'')),
        'rotation_uuid'=>trim((string)($_GET['rotation_uuid']??'')),
        'due_from'=>$dueFrom,'due_to'=>$dueTo,
        'page'=>max(1,(int)($_GET['page']??1)),'per_page'=>max(1,min(100,(int)($_GET['per_page']??20)))
    ]);
    apiResponse(true,'',$data);
}catch(Throwable $e){
    apiResponse(false,'Erreur taches : '.$e->getMessage(),[],500);
}

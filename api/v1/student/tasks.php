<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/stage-task.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);

try{
    $data=stageTaskStudentList($pdo,(int)$student['student_id'],[
        'status'=>$_GET['status']??'',
        'assignment_uuid'=>$_GET['assignment_uuid']??''
    ]);
    apiResponse(true,'',$data);
}catch(Throwable $e){
    apiResponse(false,'Erreur taches : '.$e->getMessage(),[],500);
}

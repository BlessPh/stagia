<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
require_once __DIR__.'/../../../includes/stage-feedback.php';
try{
    apiResponse(true,'',feedbackStudentVisibleList($pdo,(int)$student['student_id'],(string)($_GET['type']??'')));
}catch(Throwable $e){
    apiResponse(false,'Erreur feedbacks : '.$e->getMessage(),[],500);
}

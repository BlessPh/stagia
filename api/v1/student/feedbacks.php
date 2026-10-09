<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
require_once __DIR__.'/../../../includes/stage-feedback.php';
try{
    apiResponse(true,'',feedbackStudentVisibleList($pdo,(int)$student['student_id'],$_GET));
}catch(InvalidArgumentException $e){
    apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    error_log('[STUDENT FEEDBACKS] '.$e->getMessage());
    apiResponse(false,'Une erreur interne empêche le chargement des feedbacks.',[],500);
}

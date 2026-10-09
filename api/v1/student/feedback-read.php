<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('POST');
$student=requireApiStudent($pdo);
$uuid=trim((string)($_GET['uuid']??''));
if($uuid==='')apiResponse(false,'Identifiant du feedback requis.',[],422);

require_once __DIR__.'/../../../includes/stage-feedback.php';
try{
    apiResponse(true,'Feedback marque comme lu.',[
        'feedback'=>feedbackStudentMarkRead($pdo,(int)$student['student_id'],$uuid)
    ]);
}catch(OutOfBoundsException $e){
    apiResponse(false,$e->getMessage(),[],404);
}catch(Throwable $e){
    apiResponse(false,'Erreur lors du marquage du feedback.',[],500);
}

<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../includes/student-communication-api.php';
requireApiMethod('GET');$student=requireApiStudent($pdo);
try{$items=studentCommunicationContacts($pdo,(int)$student['user_id'],(int)$student['student_id']);apiResponse(true,'',['items'=>$items,'total'=>count($items)]);}
catch(Throwable $e){error_log('[API COMM CONTACTS] '.$e->getMessage());apiResponse(false,'Impossible de charger les contacts autorises.',[],500);}

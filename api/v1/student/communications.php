<?php
require_once __DIR__.'/../api-auth.php';require_once __DIR__.'/../../../includes/student-communication-api.php';requireApiMethod('GET');$student=requireApiStudent($pdo);
try{$limit=studentCommunicationLimit($_GET['limit']??30);$offset=max(0,(int)($_GET['offset']??0));$items=studentCommunicationList($pdo,(int)$student['user_id'],$limit,$offset);apiResponse(true,'',['items'=>$items,'limit'=>$limit,'offset'=>$offset]);}catch(Throwable $e){error_log('[API COMMUNICATIONS] '.$e->getMessage());apiResponse(false,'Impossible de charger les communications.',[],500);}

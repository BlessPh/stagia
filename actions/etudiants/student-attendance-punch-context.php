<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/student-attendance-punch.php';

requireRole(['STAGIAIRE']);

$userId=(int)($_SESSION['user_id']??0);

try{
    if(!$userId){
        jsonResponse(false,'Session invalide.',[],401);
    }

    $context=studentAttendancePunchContext(
        $pdo,
        $userId
    );

    jsonResponse(true,'',['punch'=>$context]);

}catch(Throwable $e){
    jsonResponse(
        false,
        'Erreur pointeuse : '.$e->getMessage(),
        [],
        500
    );
}

<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
try{
    $s=$pdo->prepare("SELECT se.id enrollment_id,se.matricule,se.email_institutionnel,se.date_inscription,se.statut,e.id university_id,e.code university_code,e.nom university_name,e.ville university_city,e.province university_province,
        (SELECT COUNT(*) FROM student_academic_enrollments ae WHERE ae.enrollment_id=se.id) academic_path_count
        FROM student_enrollments se JOIN etablissements e ON e.id=se.etablissement_id WHERE se.student_id=? ORDER BY (se.statut='ACTIF') DESC,se.id DESC");
    $s->execute([(int)$student['student_id']]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){$item['enrollment_id']=(int)$item['enrollment_id'];$item['university_id']=(int)$item['university_id'];$item['academic_path_count']=(int)$item['academic_path_count'];}unset($item);
    apiResponse(true,'',['items'=>$items,'total'=>count($items)]);
}catch(Throwable $e){apiResponse(false,'Erreur rattachements : '.$e->getMessage(),[],500);}

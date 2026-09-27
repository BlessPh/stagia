<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
try{
    $s=$pdo->prepare("SELECT sd.id,sd.enrollment_id,sd.academic_enrollment_id,sd.type_code,sd.titre,sd.nom_fichier,sd.mime_type,sd.taille,sd.origine,sd.visibilite,sd.created_at,sd.updated_at,e.code university_code,e.nom university_name,aa.libelle academic_year
        FROM student_documents sd LEFT JOIN student_enrollments se ON se.id=sd.enrollment_id LEFT JOIN etablissements e ON e.id=se.etablissement_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=sd.academic_enrollment_id LEFT JOIN annees_academiques aa ON aa.id=ae.annee_academique_id
        WHERE sd.student_id=? AND sd.statut='ACTIF' AND sd.type_code<>'CONVENTION_STAGE' ORDER BY sd.created_at DESC,sd.id DESC");
    $s->execute([(int)$student['student_id']]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){
        $item['id']=(int)$item['id'];$item['enrollment_id']=$item['enrollment_id']!==null?(int)$item['enrollment_id']:null;$item['academic_enrollment_id']=$item['academic_enrollment_id']!==null?(int)$item['academic_enrollment_id']:null;$item['taille']=$item['taille']!==null?(int)$item['taille']:null;
        $endpoint=rtrim(BASE_URL,'/').'/api/v1/student/academic-documents/'.$item['id'].'/file';$item['view_endpoint']=$endpoint;$item['download_endpoint']=$endpoint.'?download=1';$item['requires_bearer']=true;
    }unset($item);
    apiResponse(true,'',['items'=>$items,'total'=>count($items)]);
}catch(Throwable $e){apiResponse(false,'Erreur documents academiques : '.$e->getMessage(),[],500);}

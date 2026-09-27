<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);$enrollmentId=(int)($_GET['enrollment_id']??0);
if(!$enrollmentId)apiResponse(false,'Rattachement universitaire requis.',[],422);
try{
    $s=$pdo->prepare("SELECT se.id enrollment_id,se.matricule,se.email_institutionnel,se.date_inscription,se.statut,e.id university_id,e.code university_code,e.nom university_name FROM student_enrollments se JOIN etablissements e ON e.id=se.etablissement_id WHERE se.id=? AND se.student_id=? LIMIT 1");
    $s->execute([$enrollmentId,(int)$student['student_id']]);$enrollment=$s->fetch(PDO::FETCH_ASSOC);
    if(!$enrollment)apiResponse(false,'Rattachement introuvable.',[],404);
    $enrollment['enrollment_id']=(int)$enrollment['enrollment_id'];$enrollment['university_id']=(int)$enrollment['university_id'];
    $s=$pdo->prepare("SELECT ae.id academic_enrollment_id,ae.statut,ae.date_debut,ae.date_fin,aa.id academic_year_id,aa.libelle academic_year,aa.date_debut academic_year_start,aa.date_fin academic_year_end,
        p.id promotion_id,p.code promotion_code,p.nom promotion,p.niveau,f.id program_id,f.code program_code,f.nom program,d.id department_id,d.nom department,fa.id faculty_id,fa.nom faculty
        FROM student_academic_enrollments ae JOIN annees_academiques aa ON aa.id=ae.annee_academique_id JOIN promotions p ON p.id=ae.promotion_id LEFT JOIN filieres f ON f.id=p.filiere_id
        LEFT JOIN departements d ON d.id=f.departement_id LEFT JOIN facultes fa ON fa.id=d.faculte_id WHERE ae.enrollment_id=? ORDER BY (ae.statut='EN_COURS') DESC,aa.date_debut DESC,ae.id DESC");
    $s->execute([$enrollmentId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){foreach(['academic_enrollment_id','academic_year_id','promotion_id','program_id','department_id','faculty_id'] as $key)$item[$key]=$item[$key]!==null?(int)$item[$key]:null;}unset($item);
    apiResponse(true,'',['enrollment'=>$enrollment,'current'=>$items[0]??null,'items'=>$items,'total'=>count($items)]);
}catch(Throwable $e){apiResponse(false,'Erreur parcours academique : '.$e->getMessage(),[],500);}

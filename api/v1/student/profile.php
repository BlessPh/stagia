<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
try{
    $s=$pdo->prepare("SELECT sp.uuid,sp.stagia_code,sp.nom,sp.postnom,sp.prenom,sp.sexe,sp.date_naissance,sp.email,sp.telephone,sp.statut,sp.created_at,sp.updated_at,sp.photo,u.identifiant,u.email account_email,u.statut_compte
        FROM student_profiles sp JOIN users u ON u.id=sp.user_id WHERE sp.id=? LIMIT 1");
    $s->execute([(int)$student['student_id']]);$profile=$s->fetch(PDO::FETCH_ASSOC);
    if(!$profile)apiResponse(false,'Profil etudiant introuvable.',[],404);
    $profile['photo_available']=!empty($profile['photo']);unset($profile['photo']);

    $s=$pdo->prepare("SELECT ae.id academic_enrollment_id,ae.statut academic_status,aa.id academic_year_id,aa.libelle academic_year,aa.date_debut academic_year_start,aa.date_fin academic_year_end,
        se.id enrollment_id,se.matricule,se.email_institutionnel,se.statut enrollment_status,e.id university_id,e.code university_code,e.nom university_name,
        p.id promotion_id,p.code promotion_code,p.nom promotion,p.niveau,f.id program_id,f.code program_code,f.nom program,d.id department_id,d.nom department,fa.id faculty_id,fa.nom faculty
        FROM student_enrollments se JOIN etablissements e ON e.id=se.etablissement_id LEFT JOIN student_academic_enrollments ae ON ae.enrollment_id=se.id
        LEFT JOIN annees_academiques aa ON aa.id=ae.annee_academique_id LEFT JOIN promotions p ON p.id=ae.promotion_id LEFT JOIN filieres f ON f.id=p.filiere_id
        LEFT JOIN departements d ON d.id=f.departement_id LEFT JOIN facultes fa ON fa.id=d.faculte_id
        WHERE se.student_id=? ORDER BY (se.statut='ACTIF') DESC,(ae.statut='EN_COURS') DESC,ae.id DESC LIMIT 1");
    $s->execute([(int)$student['student_id']]);$current=$s->fetch(PDO::FETCH_ASSOC)?:null;
    if($current){foreach(['academic_enrollment_id','academic_year_id','enrollment_id','university_id','promotion_id','program_id','department_id','faculty_id'] as $key)$current[$key]=$current[$key]!==null?(int)$current[$key]:null;}
    apiResponse(true,'',['student'=>$profile,'current_academic'=>$current]);
}catch(Throwable $e){apiResponse(false,'Erreur profil : '.$e->getMessage(),[],500);}

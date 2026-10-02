<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);
$enrollmentId=(int)($_GET['enrollment_id']??0);

if(!$enrollmentId){
    apiResponse(false,'Rattachement universitaire requis.',[],422);
}

try{
    $statement=$pdo->prepare("
        SELECT se.id,se.matricule,se.email_institutionnel,se.date_inscription,se.statut,
               e.id AS university_id,e.code AS university_code,e.nom AS university_name
        FROM student_enrollments se
        INNER JOIN etablissements e ON e.id=se.etablissement_id
        WHERE se.id=? AND se.student_id=?
        LIMIT 1
    ");
    $statement->execute([$enrollmentId,(int)$student['student_id']]);
    $enrollment=$statement->fetch(PDO::FETCH_ASSOC);

    if(!$enrollment){
        apiResponse(false,'Rattachement introuvable.',[],404);
    }

    $statement=$pdo->prepare("
        SELECT ae.id AS academic_enrollment_id,ae.statut,
               aa.id AS academic_year_id,aa.libelle AS academic_year,
               aa.date_debut AS academic_year_start,aa.date_fin AS academic_year_end,
               p.id AS promotion_id,p.code AS promotion_code,p.nom AS promotion_name,p.niveau,
               f.id AS program_id,f.code AS program_code,f.nom AS program_name,
               d.id AS department_id,d.nom AS department_name,
               fa.id AS faculty_id,fa.nom AS faculty_name
        FROM student_academic_enrollments ae
        INNER JOIN annees_academiques aa ON aa.id=ae.annee_academique_id
        INNER JOIN promotions p ON p.id=ae.promotion_id
        LEFT JOIN filieres f ON f.id=p.filiere_id
        LEFT JOIN departements d ON d.id=f.departement_id
        LEFT JOIN facultes fa ON fa.id=d.faculte_id
        WHERE ae.enrollment_id=?
        ORDER BY aa.date_debut DESC,ae.id DESC
    ");
    $statement->execute([$enrollmentId]);
    $rows=$statement->fetchAll(PDO::FETCH_ASSOC);

    $context=$rows[0]??null;
    $academicYears=[];

    foreach($rows as $row){
        $isCurrent=$row['statut']==='EN_COURS';

        $academicYears[]=[
            'academic_enrollment_id'=>(int)$row['academic_enrollment_id'],
            'academic_year'=>[
                'id'=>(int)$row['academic_year_id'],
                'label'=>$row['academic_year'],
                'start_date'=>$row['academic_year_start'],
                'end_date'=>$row['academic_year_end']
            ],
            'level'=>$row['niveau'],
            'promotion'=>[
                'id'=>(int)$row['promotion_id'],
                'code'=>$row['promotion_code'],
                'name'=>$row['promotion_name']
            ],
            'status'=>$row['statut'],
            'is_current'=>$isCurrent
        ];
    }

    apiResponse(true,'',[
        'enrollment'=>[
            'id'=>(int)$enrollment['id'],
            'matricule'=>$enrollment['matricule'],
            'institutional_email'=>$enrollment['email_institutionnel'],
            'enrollment_date'=>$enrollment['date_inscription'],
            'status'=>$enrollment['statut']
        ],
        'university'=>[
            'id'=>(int)$enrollment['university_id'],
            'code'=>$enrollment['university_code'],
            'name'=>$enrollment['university_name']
        ],
        'curriculum'=>$context?[
            'faculty'=>$context['faculty_id']!==null?[
                'id'=>(int)$context['faculty_id'],
                'name'=>$context['faculty_name']
            ]:null,
            'department'=>$context['department_id']!==null?[
                'id'=>(int)$context['department_id'],
                'name'=>$context['department_name']
            ]:null,
            'program'=>$context['program_id']!==null?[
                'id'=>(int)$context['program_id'],
                'code'=>$context['program_code'],
                'name'=>$context['program_name']
            ]:null
        ]:null,
        'academic_years'=>$academicYears
    ]);
}catch(Throwable $e){
    apiResponse(false,'Erreur parcours academique : '.$e->getMessage(),[],500);
}

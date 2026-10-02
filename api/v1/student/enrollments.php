<?php
require_once __DIR__.'/../api-auth.php';

requireApiMethod('GET');
$student=requireApiStudent($pdo);

try{
    $statement=$pdo->prepare("
        SELECT se.id AS enrollment_id,se.matricule,se.email_institutionnel,
               se.date_inscription,se.statut AS enrollment_status,
               e.id AS university_id,e.code AS university_code,e.nom AS university_name,
               e.type_etablissement AS university_type,e.email AS university_email,
               e.telephone AS university_phone,e.adresse AS university_address,
               e.ville AS university_city,e.province AS university_province,e.logo AS university_logo,
               ae.id AS academic_enrollment_id,ae.statut AS academic_status,
               aa.id AS academic_year_id,aa.libelle AS academic_year,
               aa.date_debut AS academic_year_start,aa.date_fin AS academic_year_end,
               p.id AS promotion_id,p.code AS promotion_code,p.nom AS promotion_name,p.niveau,
               f.id AS program_id,f.code AS program_code,f.nom AS program_name,
               f.duree_annees AS program_duration_years,
               d.id AS department_id,d.code AS department_code,d.nom AS department_name,
               fa.id AS academic_unit_id,fa.code AS academic_unit_code,
               fa.nom AS academic_unit_name,fa.type_unite AS academic_unit_type,
               parent_fa.id AS parent_academic_unit_id,
               parent_fa.code AS parent_academic_unit_code,
               parent_fa.nom AS parent_academic_unit_name,
               parent_fa.type_unite AS parent_academic_unit_type,
               os.id AS specialty_id,os.code AS specialty_code,os.nom AS specialty_name,
               (SELECT COUNT(*)
                FROM student_academic_enrollments history
                WHERE history.enrollment_id=se.id) AS academic_history_count
        FROM student_enrollments se
        INNER JOIN etablissements e ON e.id=se.etablissement_id
        LEFT JOIN student_academic_enrollments ae
               ON ae.id=(
                   SELECT current_academic.id
                   FROM student_academic_enrollments current_academic
                   WHERE current_academic.enrollment_id=se.id
                   ORDER BY (current_academic.statut='EN_COURS') DESC,current_academic.id DESC
                   LIMIT 1
               )
        LEFT JOIN annees_academiques aa ON aa.id=ae.annee_academique_id
        LEFT JOIN promotions p ON p.id=ae.promotion_id
        LEFT JOIN filieres f ON f.id=p.filiere_id
        LEFT JOIN departements d ON d.id=f.departement_id
        LEFT JOIN facultes fa ON fa.id=COALESCE(d.faculte_id,f.faculte_id)
        LEFT JOIN facultes parent_fa ON parent_fa.id=fa.parent_id
        LEFT JOIN options_specialites os ON os.id=p.option_specialite_id
        WHERE se.student_id=?
        ORDER BY (se.statut='ACTIF') DESC,se.id DESC
    ");
    $statement->execute([(int)$student['student_id']]);
    $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
    $items=[];

    foreach($rows as $row){
        $currentAcademic=$row['academic_enrollment_id']!==null?[
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
            'status'=>$row['academic_status']
        ]:null;

        $items[]=[
            'enrollment_id'=>(int)$row['enrollment_id'],
            'matricule'=>$row['matricule'],
            'institutional_email'=>$row['email_institutionnel'],
            'enrollment_date'=>$row['date_inscription'],
            'status'=>$row['enrollment_status'],
            'is_active'=>$row['enrollment_status']==='ACTIF',
            'university'=>[
                'id'=>(int)$row['university_id'],
                'code'=>$row['university_code'],
                'name'=>$row['university_name'],
                'type'=>$row['university_type'],
                'logo_url'=>apiPublicFileUrl($row['university_logo']),
                'contact'=>[
                    'email'=>$row['university_email'],
                    'phone'=>$row['university_phone']
                ],
                'location'=>[
                    'address'=>$row['university_address'],
                    'city'=>$row['university_city'],
                    'province'=>$row['university_province']
                ]
            ],
            'academic_structure'=>[
                'academic_unit'=>$row['academic_unit_id']!==null?[
                    'id'=>(int)$row['academic_unit_id'],
                    'type'=>$row['academic_unit_type'],
                    'code'=>$row['academic_unit_code'],
                    'name'=>$row['academic_unit_name'],
                    'parent'=>$row['parent_academic_unit_id']!==null?[
                        'id'=>(int)$row['parent_academic_unit_id'],
                        'type'=>$row['parent_academic_unit_type'],
                        'code'=>$row['parent_academic_unit_code'],
                        'name'=>$row['parent_academic_unit_name']
                    ]:null
                ]:null,
                'department'=>$row['department_id']!==null?[
                    'id'=>(int)$row['department_id'],
                    'code'=>$row['department_code'],
                    'name'=>$row['department_name']
                ]:null,
                'program'=>$row['program_id']!==null?[
                    'id'=>(int)$row['program_id'],
                    'code'=>$row['program_code'],
                    'name'=>$row['program_name'],
                    'duration_years'=>$row['program_duration_years']!==null
                        ?(int)$row['program_duration_years']
                        :null
                ]:null,
                'specialty'=>$row['specialty_id']!==null?[
                    'id'=>(int)$row['specialty_id'],
                    'code'=>$row['specialty_code'],
                    'name'=>$row['specialty_name']
                ]:null
            ],
            'current_academic'=>$currentAcademic,
            'academic_history_count'=>(int)$row['academic_history_count']
        ];
    }

    apiResponse(true,'',['items'=>$items,'total'=>count($items)]);
}catch(Throwable $e){
    apiResponse(false,'Erreur rattachements : '.$e->getMessage(),[],500);
}

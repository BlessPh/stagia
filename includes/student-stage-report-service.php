<?php

function studentStageReportUuid():string{
    $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
}

function studentStageReportTableExists(PDO $pdo):bool{
    $s=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');$s->execute(['stage_reports']);return (bool)$s->fetchColumn();
}

function studentStageReportAssignment(PDO $pdo,int $studentId,string $assignmentUuid):?array{
    $s=$pdo->prepare("SELECT a.id assignment_id,a.uuid assignment_uuid,a.statut assignment_status,a.date_debut,a.date_fin,
        c.id campaign_id,c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,c.owner_etablissement_id university_id,
        uni.code university_code,uni.nom university_name,h.id hospital_id,h.code hospital_code,h.nom hospital_name,
        hu.id department_id,hu.code department_code,hu.nom department_name,hu.type department_type
        FROM stage_assignments a
        JOIN stage_admissions ad ON ad.id=a.admission_id
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        JOIN student_enrollments se ON se.id=ae.enrollment_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id
        WHERE a.uuid=? AND se.student_id=? AND a.statut<>'ANNULEE' LIMIT 1");
    $s->execute([$assignmentUuid,$studentId]);$row=$s->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function studentStageReportFileInfo(array $file):array{
    if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Le fichier du rapport est absent ou incomplet.');
    if(!is_uploaded_file((string)$file['tmp_name']))throw new InvalidArgumentException('Le fichier recu est invalide.');
    $size=(int)($file['size']??0);
    if($size<=0||$size>15*1024*1024)throw new InvalidArgumentException('Le rapport doit avoir une taille comprise entre 1 octet et 15 Mo.');
    $original=preg_replace('/[\x00-\x1F\x7F]+/u','',basename((string)($file['name']??'')))?:'rapport';
    $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    $signature=(string)file_get_contents((string)$file['tmp_name'],false,null,0,16);$mime=null;
    if($extension==='pdf'&&str_starts_with($signature,'%PDF-'))$mime='application/pdf';
    elseif($extension==='doc'&&substr($signature,0,8)==="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")$mime='application/msword';
    elseif($extension==='docx'&&class_exists('ZipArchive')){
        $zip=new ZipArchive();$opened=$zip->open((string)$file['tmp_name']);
        if($opened===true&&$zip->locateName('[Content_Types].xml')!==false&&$zip->locateName('word/document.xml')!==false)$mime='application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        if($opened===true)$zip->close();
    }
    if(!$mime)throw new InvalidArgumentException('Format non autorise. Utilisez PDF, DOC ou DOCX.');
    return ['original'=>$original,'extension'=>$extension,'mime'=>$mime,'size'=>$size,'tmp'=>(string)$file['tmp_name']];
}

function studentStageReportSubmit(PDO $pdo,int $studentId,array $input,array $file):array{
    if(!studentStageReportTableExists($pdo))throw new RuntimeException('Migration des rapports de stage requise.');
    $assignmentUuid=trim((string)($input['assignment_uuid']??''));
    if($assignmentUuid==='')throw new InvalidArgumentException('assignment_uuid est obligatoire.');
    $assignment=studentStageReportAssignment($pdo,$studentId,$assignmentUuid);
    if(!$assignment)throw new InvalidArgumentException('Affectation introuvable ou non autorisee.');
    $title=trim((string)($input['title']??$input['titre']??''));
    if($title==='')$title='Rapport de stage - '.$assignment['campaign_title'];
    if(mb_strlen($title)>180)throw new InvalidArgumentException('Le titre du rapport est trop long.');
    $description=trim((string)($input['description']??''));
    if(mb_strlen($description)>5000)throw new InvalidArgumentException('La description est trop longue.');
    $upload=studentStageReportFileInfo($file);$uuid=studentStageReportUuid();
    $root=dirname(__DIR__).'/storage/stage-reports';
    $directory=$root.'/'.$studentId.'/'.$assignmentUuid;
    if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('Impossible de creer le dossier des rapports.');
    $protection=$root.'/.htaccess';if(!is_file($protection))@file_put_contents($protection,"Require all denied\n");
    $stored=$uuid.'.'.$upload['extension'];$absolute=$directory.'/'.$stored;
    $relative='storage/stage-reports/'.$studentId.'/'.$assignmentUuid.'/'.$stored;
    if(!move_uploaded_file($upload['tmp'],$absolute))throw new RuntimeException("Impossible d'enregistrer le rapport.");
    try{
        $pdo->beginTransaction();
        $versionStmt=$pdo->prepare('SELECT COALESCE(MAX(version_no),0)+1 FROM stage_reports WHERE assignment_id=? FOR UPDATE');
        $versionStmt->execute([(int)$assignment['assignment_id']]);$version=(int)$versionStmt->fetchColumn();
        $s=$pdo->prepare("INSERT INTO stage_reports(uuid,assignment_id,student_id,university_etablissement_id,version_no,titre,description,nom_original,nom_stockage,chemin,mime_type,extension,taille,statut,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'SOUMIS',NOW())");
        $s->execute([$uuid,(int)$assignment['assignment_id'],$studentId,(int)$assignment['university_id'],$version,$title,$description?:null,$upload['original'],$stored,$relative,$upload['mime'],$upload['extension'],$upload['size']]);
        $id=(int)$pdo->lastInsertId();$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(is_file($absolute))@unlink($absolute);throw $e;}
    return studentStageReportById($pdo,$studentId,$id)??[];
}

function studentStageReportBaseSql():string{
    return "SELECT r.*,a.uuid assignment_uuid,a.statut assignment_status,a.date_debut,a.date_fin,
        c.uuid campaign_uuid,c.code campaign_code,c.titre campaign_title,
        h.id hospital_id,h.code hospital_code,h.nom hospital_name,
        uni.id university_id,uni.code university_code,uni.nom university_name,
        hu.id department_id,hu.code department_code,hu.nom department_name,hu.type department_type,
        reviewer.id reviewer_id,TRIM(CONCAT_WS(' ',reviewer.prenom,reviewer.nom,reviewer.postnom)) reviewer_name
        FROM stage_reports r
        JOIN stage_assignments a ON a.id=r.assignment_id
        JOIN stage_admissions ad ON ad.id=a.admission_id
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        JOIN etablissements h ON h.id=a.host_etablissement_id
        JOIN etablissements uni ON uni.id=r.university_etablissement_id
        LEFT JOIN host_units hu ON hu.id=a.host_unit_id
        LEFT JOIN users reviewer ON reviewer.id=r.reviewed_by_user_id";
}

function studentStageReportNormalize(array $row):array{
    return [
        'uuid'=>$row['uuid'],'version'=>(int)$row['version_no'],'title'=>$row['titre'],'description'=>$row['description'],
        'status'=>$row['statut'],'university_comment'=>$row['commentaire_universite'],
        'submitted_at'=>$row['submitted_at'],'received_at'=>$row['received_at'],'reviewed_at'=>$row['reviewed_at'],
        'file'=>['original_name'=>$row['nom_original'],'mime_type'=>$row['mime_type'],'extension'=>$row['extension'],'size'=>(int)$row['taille']],
        'reviewer'=>$row['reviewer_id']!==null?['user_id'=>(int)$row['reviewer_id'],'name'=>$row['reviewer_name']]:null,
        'assignment'=>['uuid'=>$row['assignment_uuid'],'status'=>$row['assignment_status'],'start_date'=>$row['date_debut'],'end_date'=>$row['date_fin']],
        'campaign'=>['uuid'=>$row['campaign_uuid'],'code'=>$row['campaign_code'],'title'=>$row['campaign_title']],
        'hospital'=>['id'=>(int)$row['hospital_id'],'code'=>$row['hospital_code'],'name'=>$row['hospital_name']],
        'university'=>['id'=>(int)$row['university_id'],'code'=>$row['university_code'],'name'=>$row['university_name']],
        'department'=>$row['department_id']!==null?['id'=>(int)$row['department_id'],'code'=>$row['department_code'],'name'=>$row['department_name'],'type'=>$row['department_type']]:null
    ];
}

function studentStageReportById(PDO $pdo,int $studentId,int $id):?array{
    $s=$pdo->prepare(studentStageReportBaseSql().' WHERE r.id=? AND r.student_id=? LIMIT 1');$s->execute([$id,$studentId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);return $row?studentStageReportNormalize($row):null;
}

function studentStageReport(PDO $pdo,int $studentId,string $uuid):?array{
    if(!studentStageReportTableExists($pdo))throw new RuntimeException('Migration des rapports de stage requise.');
    $s=$pdo->prepare(studentStageReportBaseSql().' WHERE r.uuid=? AND r.student_id=? LIMIT 1');$s->execute([$uuid,$studentId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);return $row?studentStageReportNormalize($row):null;
}

function studentStageReportRecord(PDO $pdo,int $studentId,string $uuid):?array{
    $s=$pdo->prepare('SELECT * FROM stage_reports WHERE uuid=? AND student_id=? LIMIT 1');$s->execute([$uuid,$studentId]);$row=$s->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function studentStageReportPath(array $report):?string{
    $root=realpath(dirname(__DIR__));$storage=realpath(dirname(__DIR__).'/storage/stage-reports');
    $path=$root?realpath($root.'/'.ltrim((string)($report['chemin']??''),'/\\')):false;
    return $root&&$storage&&$path&&str_starts_with($path,$storage.DIRECTORY_SEPARATOR)&&is_file($path)?$path:null;
}

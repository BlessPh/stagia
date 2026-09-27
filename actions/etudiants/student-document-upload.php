<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);
verifyAjaxCsrf();

try{
    $etablissementId=currentEtablissementId($pdo);
    $enrollmentId=(int)($_POST['enrollment_id']??0);
    $academicId=(int)($_POST['academic_enrollment_id']??0);
    $type=trim($_POST['type_code']??'');
    $titre=trim($_POST['titre']??'');

    if(!$etablissementId || !$enrollmentId || !$type || !$titre)
        jsonResponse(false,'Informations obligatoires manquantes.',[],422);

    /* Vérifier le rattachement */
    $stmt=$pdo->prepare("
        SELECT id,student_id
        FROM student_enrollments
        WHERE id=? AND etablissement_id=?
    ");
    $stmt->execute([$enrollmentId,$etablissementId]);
    $enrollment=$stmt->fetch();

    if(!$enrollment)
        jsonResponse(false,'Étudiant introuvable dans votre établissement.',[],404);

    /* Vérifier l'année académique éventuelle */
    if($academicId){
        $stmt=$pdo->prepare("
            SELECT id FROM student_academic_enrollments
            WHERE id=? AND enrollment_id=?
        ");
        $stmt->execute([$academicId,$enrollmentId]);

        if(!$stmt->fetch())
            jsonResponse(false,'Parcours académique invalide.',[],422);
    }

    /* Type documentaire */
    $types=[
        'ATTESTATION_INSCRIPTION',
        'RELEVE_NOTES',
        'ATTESTATION_STAGE',
        'LETTRE_STAGE',
        'DIPLOME',
        'AUTRE'
    ];

    if(!in_array($type,$types,true))
        jsonResponse(false,'Type de document invalide.',[],422);

    /* Fichier */
    if(empty($_FILES['document']) || $_FILES['document']['error']!==UPLOAD_ERR_OK)
        jsonResponse(false,'Veuillez sélectionner un fichier.',[],422);

    $file=$_FILES['document'];

    /* Maximum 5 Mo */
    if($file['size']>5*1024*1024)
        jsonResponse(false,'Le fichier ne doit pas dépasser 5 Mo.',[],422);

    /* Vérification réelle du MIME */
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);

    $allowed=[
        'application/pdf'=>'pdf',
        'image/jpeg'=>'jpg',
        'image/png'=>'png'
    ];

    if(!isset($allowed[$mime]))
        jsonResponse(false,'Formats autorisés : PDF, JPG et PNG.',[],422);

    $extension=$allowed[$mime];

    /* Dossier privé de l'étudiant */
    $studentId=(int)$enrollment['student_id'];
    $directory=__DIR__.'/../../storage/student_documents/'.$studentId;

    if(!is_dir($directory) && !mkdir($directory,0775,true))
        throw new Exception('Impossible de créer le dossier de stockage.');

    /* Nom impossible à deviner */
    $storedName=bin2hex(random_bytes(20)).'.'.$extension;
    $destination=$directory.'/'.$storedName;

    if(!move_uploaded_file($file['tmp_name'],$destination))
        throw new Exception("Impossible d'enregistrer le fichier.");

    $relativePath='student_documents/'.$studentId.'/'.$storedName;

    /* Enregistrer le document */
    $stmt=$pdo->prepare("
        INSERT INTO student_documents(
            student_id,enrollment_id,academic_enrollment_id,
            uploaded_by_user_id,type_code,titre,
            nom_fichier,chemin,mime_type,taille,
            origine,visibilite,statut
        )
        VALUES(?,?,?,?,?,?,?,?,?,?,
               'ETABLISSEMENT','ETABLISSEMENT','ACTIF')
    ");

    $stmt->execute([
        $studentId,
        $enrollmentId,
        $academicId?:null,
        $_SESSION['user_id'],
        $type,
        $titre,
        basename($file['name']),
        $relativePath,
        $mime,
        $file['size']
    ]);

    jsonResponse(true,'Document ajouté avec succès.',[
        'id'=>(int)$pdo->lastInsertId()
    ]);

}catch(Throwable $e){
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}
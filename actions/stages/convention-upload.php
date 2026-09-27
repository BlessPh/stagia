<?php
/**
 * Endpoint AJAX de dépôt du PDF final signé d'une convention.
 * Il lie le fichier au dossier étudiant puis fait passer la convention à l'état SIGNEE.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/stage-convention.php';

verifyAjaxCsrf();
$absolutePath=null;

try{
    /* La convention doit être en attente de signature, appartenir au déposant et avoir ses trois signatures. */
    requireConventionAjax();
    $id=(int)($_POST['id']??0);
    if(!$id)throw new RuntimeException('Convention invalide.');

    $x=conventionLoad($pdo,$id,false);
    if($x['statut']!=='A_SIGNER')
        throw new RuntimeException('Seule une convention à signer peut recevoir le PDF final.');
    if(!conventionIsUniversityOwner($x)&&!conventionIsHostOwner($x))
        throw new RuntimeException('Dépôt non autorisé.');
    if($x['document_id'])
        throw new RuntimeException('Un document signé est déjà rattaché.');
    if(!$x['date_signature_etudiant']||!$x['date_signature_universite']||!$x['date_signature_accueil'])
        throw new RuntimeException('Les trois signatures doivent être enregistrées avant le dépôt du PDF.');

    if(!isset($_FILES['document']))throw new RuntimeException('Sélectionnez le PDF signé.');
    $f=$_FILES['document'];
    if($f['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Erreur lors du transfert du fichier.');
    if(!is_uploaded_file($f['tmp_name']))throw new RuntimeException('Fichier reçu invalide.');

    /* Taille, extension et signature binaire empêchent l'enregistrement d'un faux PDF. */
    $size=(int)$f['size'];
    if($size<=0||$size>8*1024*1024)throw new RuntimeException('Le PDF doit faire au maximum 8 Mo.');

    $name=basename((string)$f['name']);
    $signature='';
    if($h=fopen($f['tmp_name'],'rb')){
        $signature=fread($h,8);fclose($h);
    }
    if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf'||substr($signature,0,5)!=='%PDF-')
        throw new RuntimeException('Seul un véritable fichier PDF est accepté.');

    /* Le fichier est isolé dans l'espace documentaire propre au stagiaire. */
    $studentId=(int)$x['student_id'];
    $storageRoot=__DIR__.'/../../storage/student-documents';
    $studentDir=$storageRoot.'/'.$studentId;
    if(!is_dir($studentDir)&&!mkdir($studentDir,0775,true)&&!is_dir($studentDir))
        throw new RuntimeException('Impossible de créer le dossier de stockage.');
    if(!file_exists($storageRoot.'/.htaccess'))
        @file_put_contents($storageRoot.'/.htaccess',"Require all denied\n");

    $stored='convention_'.preg_replace('/[^a-zA-Z0-9-]/','',$x['uuid']).'.pdf';
    $absolutePath=$studentDir.'/'.$stored;
    if(!move_uploaded_file($f['tmp_name'],$absolutePath))
        throw new RuntimeException("Impossible d'enregistrer le PDF.");

    $relative='storage/student-documents/'.$studentId.'/'.$stored;
    $cleanName=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)?:'convention-signee.pdf';

    /* Document étudiant et statut de convention sont enregistrés dans une même transaction. */
    $pdo->beginTransaction();
    $pdo->prepare("
        INSERT INTO student_documents(
            student_id,enrollment_id,academic_enrollment_id,uploaded_by_user_id,
            type_code,titre,nom_fichier,chemin,mime_type,taille,
            origine,visibilite,statut
        ) VALUES(?,?,?,?,?,?,?,?,?,?,'ETABLISSEMENT','PARTAGEE','ACTIF')
    ")->execute([
        $studentId,
        null,
        (int)$x['academic_enrollment_id']?:null,
        conventionUserId()?:null,
        'CONVENTION_STAGE',
        $x['titre'],
        $cleanName,
        $relative,
        'application/pdf',
        $size
    ]);
    $documentId=(int)$pdo->lastInsertId();

    $pdo->prepare("
        UPDATE stage_conventions
        SET document_id=?,statut='SIGNEE',signed_at=NOW()
        WHERE id=? AND statut='A_SIGNER'
    ")->execute([$documentId,$id]);

    $pdo->commit();
    $absolutePath=null;
    jsonResponse(true,'PDF signé enregistré. La convention est maintenant signée.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    if($absolutePath&&is_file($absolutePath))@unlink($absolutePath);
    jsonResponse(false,$e->getMessage(),[],422);
}

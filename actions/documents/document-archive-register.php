<?php
ini_set('display_errors','0');
if(!headers_sent())header('Content-Type: application/json; charset=utf-8');
ob_start();
try{
    require_once __DIR__.'/../../config/config.php';
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/ajax.php';
    require_once __DIR__.'/../../includes/permissions.php';
    require_once __DIR__.'/../../includes/document-archive-service.php';
    requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);

    if(empty($_POST['csrf'])||empty($_SESSION['csrf'])||!hash_equals($_SESSION['csrf'],(string)$_POST['csrf']))
        throw new RuntimeException('Session expirée.');

    if(!stagiaArchiveTableExists($pdo,'stage_document_archives'))
        throw new RuntimeException('Table stage_document_archives introuvable. Exécutez apply-document-archives-schema.php.');

    $eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));
    $uid=(int)($_SESSION['user_id']??0);
    $role=strtoupper(trim((string)($_SESSION['role_code']??'')));
    $studentId=(int)($_POST['student_id']??0);

    $type=substr(trim((string)($_POST['document_type']??'MODELE')),0,60);
    $title=substr(trim((string)($_POST['title']??'Document imprimé')),0,180);
    $ref='DOC-'.date('Ymd-His').'-'.random_int(100,999);
    $url=substr(trim((string)($_POST['source_url']??'')),0,500);

    $s=$pdo->prepare("INSERT INTO stage_document_archives(uuid,document_type,reference,title,student_id,assignment_id,campaign_id,host_etablissement_id,university_etablissement_id,generated_by,generated_at,source_url,status) VALUES(UUID(),?,?,?,?,?,?,?,?,?,NOW(),?,'GENERE')");
    $s->execute([$type,$ref,$title,$studentId?:null,(int)($_POST['assignment_id']??0)?:null,(int)($_POST['campaign_id']??0)?:null,$eid?:null,$eid?:null,$uid,$url]);

    ob_clean();
    echo json_encode(['success'=>true,'message'=>'Document archivé.','data'=>['reference'=>$ref]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    ob_clean();http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage(),'data'=>[]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

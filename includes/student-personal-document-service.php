<?php

function studentPersonalDocumentList(PDO $pdo,int $studentId):array{
    $s=$pdo->prepare("SELECT id,uuid,titre,categorie,nom_original,mime_type,extension,taille,created_at,updated_at FROM student_personal_documents WHERE student_id=? ORDER BY created_at DESC,id DESC");
    $s->execute([$studentId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach($items as &$item){$item['id']=(int)$item['id'];$item['taille']=(int)$item['taille'];}unset($item);
    return $items;
}

function studentPersonalDocument(PDO $pdo,int $studentId,string $uuid):?array{
    $s=$pdo->prepare('SELECT * FROM student_personal_documents WHERE uuid=? AND student_id=? LIMIT 1');
    $s->execute([$uuid,$studentId]);$row=$s->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function studentPersonalDocumentPath(array $document):?string{
    $root=realpath(dirname(__DIR__));
    $storage=realpath(dirname(__DIR__).'/storage/student-documents');
    $path=$root?realpath($root.'/'.ltrim((string)($document['chemin']??''),'/\\')):false;
    if(!$root||!$storage||!$path||!str_starts_with($path,$storage.DIRECTORY_SEPARATOR)||!is_file($path))return null;
    return $path;
}

function studentPersonalDocumentUpload(PDO $pdo,int $studentId,array $input,array $file):array{
    $title=trim((string)($input['title']??$input['titre']??''));
    if($title==='')throw new InvalidArgumentException('Le titre du document est obligatoire.');
    if(mb_strlen($title)>180)throw new InvalidArgumentException('Le titre du document est trop long.');
    $category=strtoupper(trim((string)($input['category']??$input['categorie']??'AUTRE')));
    if(!in_array($category,['IDENTITE','ACADEMIQUE','STAGE','ADMINISTRATIF','AUTRE'],true))$category='AUTRE';
    if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('Le fichier est absent ou incomplet.');
    if(!is_uploaded_file((string)$file['tmp_name']))throw new InvalidArgumentException('Le fichier recu est invalide.');
    $size=(int)($file['size']??0);if($size<=0||$size>8*1024*1024)throw new InvalidArgumentException('Le fichier doit avoir une taille comprise entre 1 octet et 8 Mo.');
    $original=preg_replace('/[\x00-\x1F\x7F]+/u','',basename((string)($file['name']??'')))?:'document';
    $clientExt=strtolower(pathinfo($original,PATHINFO_EXTENSION));
    $signature=(string)file_get_contents((string)$file['tmp_name'],false,null,0,16);
    $mime=null;$extension=null;
    if($clientExt==='pdf'&&str_starts_with($signature,'%PDF-')){$mime='application/pdf';$extension='pdf';}
    elseif(in_array($clientExt,['jpg','jpeg'],true)&&substr($signature,0,3)==="\xFF\xD8\xFF"){$mime='image/jpeg';$extension='jpg';}
    elseif($clientExt==='png'&&substr($signature,0,8)==="\x89PNG\x0D\x0A\x1A\x0A"){$mime='image/png';$extension='png';}
    elseif($clientExt==='doc'&&substr($signature,0,8)==="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"){$mime='application/msword';$extension='doc';}
    elseif($clientExt==='docx'&&class_exists('ZipArchive')){
        $zip=new ZipArchive();$opened=$zip->open((string)$file['tmp_name']);
        if($opened===true&&$zip->locateName('[Content_Types].xml')!==false&&$zip->locateName('word/document.xml')!==false){$mime='application/vnd.openxmlformats-officedocument.wordprocessingml.document';$extension='docx';}
        if($opened===true)$zip->close();
    }
    if(!$mime||!$extension)throw new InvalidArgumentException('Format non autorise. Utilisez PDF, JPG, PNG, DOC ou DOCX.');

    $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
    $uuid=vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
    $root=dirname(__DIR__).'/storage/student-documents';$directory=$root.'/'.$studentId;
    if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('Impossible de creer le dossier de stockage.');
    $protection=$root.'/.htaccess';if(!is_file($protection))@file_put_contents($protection,"Require all denied\n");
    $stored=$uuid.'.'.$extension;$absolute=$directory.'/'.$stored;$relative='storage/student-documents/'.$studentId.'/'.$stored;
    if(!move_uploaded_file((string)$file['tmp_name'],$absolute))throw new RuntimeException("Impossible d'enregistrer le fichier.");
    try{
        $s=$pdo->prepare('INSERT INTO student_personal_documents(uuid,student_id,titre,categorie,nom_original,nom_stockage,mime_type,extension,taille,chemin) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$uuid,$studentId,$title,$category,$original,$stored,$mime,$extension,$size,$relative]);
    }catch(Throwable $e){if(is_file($absolute))@unlink($absolute);throw $e;}
    return ['id'=>(int)$pdo->lastInsertId(),'uuid'=>$uuid,'titre'=>$title,'categorie'=>$category,'nom_original'=>$original,'mime_type'=>$mime,'extension'=>$extension,'taille'=>$size];
}

function studentPersonalDocumentDelete(PDO $pdo,int $studentId,string $uuid):bool{
    $document=studentPersonalDocument($pdo,$studentId,$uuid);if(!$document)return false;
    $path=studentPersonalDocumentPath($document);
    $s=$pdo->prepare('DELETE FROM student_personal_documents WHERE id=? AND student_id=?');$s->execute([(int)$document['id'],$studentId]);
    if($path&&is_file($path))@unlink($path);
    return true;
}

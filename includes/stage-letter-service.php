<?php
/* =========================================================
   STAGIA-RDC — Lettre de stage
   ---------------------------------------------------------
   Génère le lien de lettre après acceptation d'une candidature
   et l'archive pour l'université + l'hôpital.
========================================================= */

if(!function_exists('stageLetterTableExists')){
function stageLetterTableExists(PDO $pdo,string $table):bool{
    try{$s=$pdo->prepare('SHOW TABLES LIKE ?');$s->execute([$table]);return (bool)$s->fetchColumn();}
    catch(Throwable $e){return false;}
}}

if(!function_exists('stageLetterColumns')){
function stageLetterColumns(PDO $pdo,string $table):array{
    static $cache=[];
    if(isset($cache[$table]))return $cache[$table];
    try{
        $rows=$pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $cols=[];foreach($rows as $r)$cols[(string)$r['Field']]=true;
        return $cache[$table]=$cols;
    }catch(Throwable $e){return $cache[$table]=[];}
}}

if(!function_exists('stageLetterHas')){
function stageLetterHas(PDO $pdo,string $table,string $column):bool{
    $cols=stageLetterColumns($pdo,$table);
    return isset($cols[$column]);
}}

if(!function_exists('stageLetterExpr')){
function stageLetterExpr(array $cols,string $alias,array $names,string $default="''"):string{
    foreach($names as $n)if(isset($cols[$n]))return $alias.'.`'.$n.'`';
    return $default;
}}

if(!function_exists('stageLetterConcatName')){
function stageLetterConcatName(array $cols,string $alias='sp'):string{
    $parts=[];
    foreach(['nom','postnom','prenom'] as $c)if(isset($cols[$c]))$parts[]=$alias.'.`'.$c.'`';
    return $parts?"COALESCE(NULLIF(TRIM(CONCAT_WS(' ',".implode(',',$parts).")),''),'Étudiant')":"'Étudiant'";
}}

if(!function_exists('stageLetterStudentCodeExpr')){
function stageLetterStudentCodeExpr(array $cols,string $alias='sp'):string{
    $parts=[];
    foreach(['stagia_code','matricule','matricule_academique','code_etudiant','numero_matricule'] as $c)
        if(isset($cols[$c]))$parts[]="NULLIF($alias.`$c`,'')";
    $parts[]="CONCAT('STG-ETU-',LPAD(COALESCE($alias.`id`,0),8,'0'))";
    return 'COALESCE('.implode(',',$parts).')';
}}

if(!function_exists('stageLetterUuid')){
function stageLetterUuid():string{
    $d=random_bytes(16);$d[6]=chr((ord($d[6])&0x0f)|0x40);$d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}}

if(!function_exists('stageLetterBaseUrl')){
function stageLetterBaseUrl():string{return defined('BASE_URL')?rtrim(BASE_URL,'/'):'';}
}

if(!function_exists('stageLetterUrl')){
function stageLetterUrl(int $applicationId):string{
    return stageLetterBaseUrl().'/views/documents/print-stage-document.php?type=lettre_stage&application_id='.$applicationId;
}}

if(!function_exists('stageLetterReference')){
function stageLetterReference(int $applicationId):string{
    return 'STG/LET/'.date('Y').'/'.str_pad((string)$applicationId,5,'0',STR_PAD_LEFT);
}}

if(!function_exists('stageLetterDateFr')){
function stageLetterDateFr($date):string{
    $v=trim((string)$date);if($v==='')return '—';
    try{$d=new DateTimeImmutable(substr($v,0,10));}catch(Throwable $e){return $v;}
    $m=[1=>'janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    return $d->format('d').' '.$m[(int)$d->format('n')].' '.$d->format('Y');
}}

if(!function_exists('stageLetterEsc')){
function stageLetterEsc($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
}

if(!function_exists('stageLetterDataByApplication')){
function stageLetterDataByApplication(PDO $pdo,int $applicationId):?array{
    if($applicationId<=0)return null;

    $sp=stageLetterColumns($pdo,'student_profiles');
    $et=stageLetterColumns($pdo,'etablissements');
    $sc=stageLetterColumns($pdo,'stage_campaigns');
    $pr=stageLetterColumns($pdo,'promotions');

    $studentName=stageLetterConcatName($sp,'sp');
    $studentCode=stageLetterStudentCodeExpr($sp,'sp');
    $campaignCode=stageLetterExpr($sc,'c',['code','campaign_code'],"CONCAT('CAM-',LPAD(c.id,6,'0'))");
    $campaignTitle=stageLetterExpr($sc,'c',['titre','title','libelle','nom'],"CONCAT('Session #',c.id)");
    $dateDebut=stageLetterExpr($sc,'c',['date_debut','start_date','starts_at'],"NULL");
    $dateFin=stageLetterExpr($sc,'c',['date_fin','end_date','ends_at'],"NULL");
    $estName=stageLetterExpr($et,'h',['nom','name','raison_sociale'],"'Structure d’accueil'");
    $uniName=stageLetterExpr($et,'u',['nom','name','raison_sociale'],"'Établissement de formation'");
    $hostCity=stageLetterExpr($et,'h',['ville','city','commune'],"''");
    $hostProvince=stageLetterExpr($et,'h',['province','state','region'],"''");
    $hostAddress=stageLetterExpr($et,'h',['adresse','address'],"''");
    $uniAddress=stageLetterExpr($et,'u',['adresse','address'],"''");
    $promotion=stageLetterExpr($pr,'p',['nom','libelle','code','niveau'],"''");

    $sql="
        SELECT
            a.id application_id,a.statut application_status,a.campaign_id,a.academic_enrollment_id,
            a.host_etablissement_id host_id,a.participation_id,
            r.id reservation_id,r.statut reservation_status,
            c.owner_etablissement_id university_id,
            $campaignCode campaign_code,$campaignTitle campaign_title,$dateDebut period_start,$dateFin period_end,
            u.id university_etablissement_id,$uniName university_name,$uniAddress university_address,
            h.id host_etablissement_id,$estName host_name,$hostCity host_city,$hostProvince host_province,$hostAddress host_address,
            se.student_id,$studentName student_name,$studentCode student_code,
            p.id promotion_id,$promotion promotion_name
        FROM stage_applications a
        LEFT JOIN stage_reservations r ON r.application_id=a.id
        LEFT JOIN stage_campaigns c ON c.id=a.campaign_id
        LEFT JOIN etablissements u ON u.id=c.owner_etablissement_id
        LEFT JOIN etablissements h ON h.id=a.host_etablissement_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=a.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN promotions p ON p.id=ae.promotion_id
        WHERE a.id=?
        LIMIT 1
    ";
    $s=$pdo->prepare($sql);$s->execute([$applicationId]);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)return null;
    $row['reference']=stageLetterReference($applicationId);
    $row['letter_url']=stageLetterUrl($applicationId);
    return $row;
}}

if(!function_exists('stageLetterActorCanView')){
function stageLetterActorCanView(array $d,int $eid,string $roleUpper):bool{
    if(in_array($roleUpper,['SUPER_ADMIN','ADMIN_NATIONAL','MINISTERE'],true))return true;
    if(!$eid)return false;
    if(in_array($roleUpper,['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],true))
        return (int)($d['university_id']??0)===$eid || (int)($d['university_etablissement_id']??0)===$eid;
    if(in_array($roleUpper,['ADMIN_ACCUEIL','COORDINATEUR_STAGES'],true))
        return (int)($d['host_id']??0)===$eid || (int)($d['host_etablissement_id']??0)===$eid;
    return false;
}}

if(!function_exists('stageLetterFindArchiveId')){
function stageLetterFindArchiveId(PDO $pdo,array $cols,array $d):int{
    if(!stageLetterTableExists($pdo,'stage_document_archives'))return 0;
    $where=[];$params=[];
    if(isset($cols['application_id'])){$where[]='application_id=?';$params[]=(int)$d['application_id'];}
    if(isset($cols['source_url'])){$where[]='source_url=?';$params[]=(string)$d['letter_url'];}
    if(isset($cols['reference'])){$where[]='reference=?';$params[]=(string)$d['reference'];}
    if(!$where)return 0;
    $sql='SELECT id FROM stage_document_archives WHERE document_type IN(\'LETTRE_STAGE\',\'LETTRE_ENVOI_STAGE\') AND ('.implode(' OR ',$where).') LIMIT 1';
    try{$s=$pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();}
    catch(Throwable $e){return 0;}
}}

if(!function_exists('stageLetterArchiveInsert')){
function stageLetterArchiveInsert(PDO $pdo,array $d,int $actorId=0):void{
    if(!stageLetterTableExists($pdo,'stage_document_archives'))return;
    $cols=stageLetterColumns($pdo,'stage_document_archives');
    $now=date('Y-m-d H:i:s');
    $title='Lettre de stage - '.trim((string)($d['student_name']??'Étudiant'));
    $url=(string)($d['letter_url']??stageLetterUrl((int)$d['application_id']));

    $values=[
        'uuid'=>stageLetterUuid(),
        'document_type'=>'LETTRE_STAGE',
        'reference'=>$d['reference']??stageLetterReference((int)$d['application_id']),
        'title'=>$title,
        'student_id'=>(int)($d['student_id']??0)?:null,
        'assignment_id'=>null,
        'campaign_id'=>(int)($d['campaign_id']??0)?:null,
        'application_id'=>(int)($d['application_id']??0)?:null,
        'reservation_id'=>(int)($d['reservation_id']??0)?:null,
        'host_etablissement_id'=>(int)($d['host_id']??($d['host_etablissement_id']??0))?:null,
        'university_etablissement_id'=>(int)($d['university_id']??($d['university_etablissement_id']??0))?:null,
        'etablissement_id'=>(int)($d['university_id']??($d['university_etablissement_id']??0))?:null,
        'generated_by'=>$actorId?:null,
        'generated_at'=>$now,
        'created_at'=>$now,
        'updated_at'=>$now,
        'source_url'=>$url,
        'status'=>'ENVOYEE',
        'student_name'=>$d['student_name']??'',
        'student_code'=>$d['student_code']??'',
        'campaign_label'=>trim(($d['campaign_code']??'').' — '.($d['campaign_title']??''),' —'),
        'establishment_name'=>$d['host_name']??''
    ];

    $existing=stageLetterFindArchiveId($pdo,$cols,$d);
    if($existing>0){
        $sets=[];$params=[];
        foreach(['title','student_id','campaign_id','host_etablissement_id','university_etablissement_id','source_url','status','student_name','student_code','campaign_label','establishment_name','generated_by','generated_at','updated_at'] as $c){
            if(isset($cols[$c])&&array_key_exists($c,$values)){$sets[]='`'.$c.'`=?';$params[]=$values[$c];}
        }
        if($sets){$params[]=$existing;$pdo->prepare('UPDATE stage_document_archives SET '.implode(',',$sets).' WHERE id=?')->execute($params);}
        return;
    }

    $insert=[];$marks=[];$params=[];
    foreach($values as $c=>$v){
        if(!isset($cols[$c]))continue;
        $insert[]='`'.$c.'`';$marks[]='?';$params[]=$v;
    }
    if(!$insert)return;
    $pdo->prepare('INSERT INTO stage_document_archives('.implode(',',$insert).') VALUES('.implode(',',$marks).')')->execute($params);
}}

if(!function_exists('stageLetterCreateForAcceptedApplication')){
function stageLetterCreateForAcceptedApplication(PDO $pdo,int $applicationId,int $actorId=0):?array{
    $d=stageLetterDataByApplication($pdo,$applicationId);
    if(!$d)return null;
    stageLetterArchiveInsert($pdo,$d,$actorId);
    return $d;
}}

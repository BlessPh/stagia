<?php
require_once __DIR__.'/../api-auth.php';
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../includes/student-stage-report-service.php';

$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');apiResponse(false,'Methode HTTP non autorisee.',[],405);}
$student=requireApiStudent($pdo);$studentId=(int)$student['student_id'];

$decorate=static function(array $item):array{
    $endpoint=rtrim(BASE_URL,'/').'/api/v1/student/stage-reports/'.rawurlencode((string)$item['uuid']);
    $item['detail_endpoint']=$endpoint;$item['file_endpoint']=$endpoint.'/file';$item['download_endpoint']=$endpoint.'/file?download=1';
    return $item;
};

try{
    if($method==='POST'){
        $report=studentStageReportSubmit($pdo,$studentId,$_POST,$_FILES['report']??$_FILES['file']??[]);
        apiResponse(true,'Rapport de stage soumis a votre universite.',['report'=>$decorate($report)],201);
    }
    if(!studentStageReportTableExists($pdo))throw new RuntimeException('Migration des rapports de stage requise.');
    $allowedStatuses=['SOUMIS','RECU','ACCEPTE','A_CORRIGER','REJETE','ARCHIVE'];
    $raw=$_GET['status']??$_GET['statuses']??[];$values=is_array($raw)?$raw:explode(',',(string)$raw);
    $statuses=array_values(array_unique(array_filter(array_map(static fn($v):string=>strtoupper(trim((string)$v)),$values))));
    if($invalid=array_values(array_diff($statuses,$allowedStatuses)))apiResponse(false,'Filtre status invalide : '.implode(', ',$invalid).'.',['allowed_values'=>$allowedStatuses],422);
    $assignmentUuid=trim((string)($_GET['assignment_uuid']??''));$campaignUuid=trim((string)($_GET['campaign_uuid']??''));
    $dateFrom=trim((string)($_GET['date_from']??''));$dateTo=trim((string)($_GET['date_to']??''));
    $validDate=static function(string $v):bool{$d=$v===''?null:DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $v===''||($d!==false&&$d->format('Y-m-d')===$v);};
    if(!$validDate($dateFrom)||!$validDate($dateTo)||($dateFrom!==''&&$dateTo!==''&&$dateFrom>$dateTo))apiResponse(false,'Periode invalide. Format attendu : YYYY-MM-DD.',[],422);
    $page=max(1,(int)($_GET['page']??1));$perPage=max(1,min(100,(int)($_GET['per_page']??20)));
    $where=['r.student_id=?'];$params=[$studentId];
    if($statuses){$where[]='r.statut IN('.implode(',',array_fill(0,count($statuses),'?')).')';array_push($params,...$statuses);}
    if($assignmentUuid!==''){$where[]='a.uuid=?';$params[]=$assignmentUuid;}
    if($campaignUuid!==''){$where[]='c.uuid=?';$params[]=$campaignUuid;}
    if($dateFrom!==''){$where[]='DATE(r.submitted_at)>=?';$params[]=$dateFrom;}
    if($dateTo!==''){$where[]='DATE(r.submitted_at)<=?';$params[]=$dateTo;}
    $s=$pdo->prepare(studentStageReportBaseSql().' WHERE '.implode(' AND ',$where).' ORDER BY r.submitted_at DESC,r.id DESC');$s->execute($params);
    $items=[];$stats=['total'=>0,'by_status'=>array_fill_keys($allowedStatuses,0),'assignments'=>0];$assignmentSet=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$item=$decorate(studentStageReportNormalize($row));$items[]=$item;$stats['total']++;$stats['by_status'][$item['status']]++;$assignmentSet[$item['assignment']['uuid']]=true;}
    $stats['assignments']=count($assignmentSet);$total=count($items);$pages=max(1,(int)ceil($total/$perPage));if($page>$pages)$page=$pages;$offset=($page-1)*$perPage;$pageItems=array_slice($items,$offset,$perPage);
    apiResponse(true,'',['items'=>$pageItems,'stats'=>$stats,
        'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages,'from'=>$total?$offset+1:0,'to'=>$total?$offset+count($pageItems):0],
        'filters'=>['statuses'=>$statuses,'assignment_uuid'=>$assignmentUuid?:null,'campaign_uuid'=>$campaignUuid?:null,'date_from'=>$dateFrom?:null,'date_to'=>$dateTo?:null],
        'available_filters'=>['statuses'=>$allowedStatuses,'per_page_max'=>100]]);
}catch(InvalidArgumentException $e){apiResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[STUDENT STAGE REPORTS] '.$e->getMessage());apiResponse(false,'Une erreur interne empeche le traitement des rapports.',[],500);}

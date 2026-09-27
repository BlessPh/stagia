<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/document-stage-data.php';

requireAjaxRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','ADMIN_ACCUEIL','COORDINATEUR_STAGES']);

try{
    $eid=(int)(function_exists('currentEtablissementId')?currentEtablissementId($pdo):($_SESSION['etablissement_id']??0));
    $uid=(int)($_SESSION['user_id']??0);
    $role=(string)($_SESSION['role_code']??'');
    $rows=stagiaDocStageContexts($pdo,$eid,$role,$uid);

    $items=array_map(function($x){
        return [
            'id'=>(int)$x['assignment_id'],
            'assignment_id'=>(int)$x['assignment_id'],
            'student_name'=>$x['student_name'],
            'matricule'=>$x['matricule'],
            'promotion'=>$x['promotion'],
            'campaign_code'=>$x['campaign_code']??'',
            'campaign_title'=>$x['campaign_title']??'',
            'host_name'=>$x['host_name']??'',
            'university_name'=>$x['university_name']??'',
            'unit_name'=>$x['unit_name']??'',
            'stage_type_label'=>$x['stage_type_label']??'',
            'date_debut'=>$x['period_start']??'',
            'date_fin'=>$x['period_end']??'',
            'label'=>trim(($x['student_name']??'').' — '.($x['promotion']??'').' — '.($x['campaign_title']??''))
        ];
    },$rows);

    jsonResponse(true,'',['items'=>$items]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur chargement stages : '.$e->getMessage(),[],500);
}

<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/certificate-service.php';
requireAjaxRole(['STAGIAIRE']);
try{
    $userId=(int)($_SESSION['user_id']??0);if(!$userId)jsonResponse(false,'Utilisateur non identifié.',[],401);
    $s=$pdo->prepare('SELECT id FROM student_profiles WHERE user_id=? LIMIT 1');$s->execute([$userId]);$sid=(int)$s->fetchColumn();
    if(!$sid)jsonResponse(true,'',['items'=>[]]);

    $where='student_id=?';
    if(function_exists('cert_col')&&cert_col($pdo,'stage_certificates','statut'))
        $where.=" AND UPPER(COALESCE(statut,''))<>'ANNULEE'";

    $s=$pdo->prepare('SELECT uuid FROM stage_certificates WHERE '.$where.' ORDER BY generated_at DESC,id DESC');$s->execute([$sid]);
    $items=[];
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $uuid){
        $d=certificateData($pdo,$uuid);if(!$d)continue;
        $items[]=[
            'id'=>(int)$d['certificate_id'],'uuid'=>$d['uuid'],'reference'=>$d['reference'],'type_document'=>$d['type_document']?:'ATTESTATION_STAGE','statut'=>$d['statut']?:'GENERE','generated_at'=>$d['generated_at'],
            'note_finale'=>$d['note_finale'],'taux_presence'=>$d['taux_presence'],'date_debut'=>$d['date_debut'],'date_fin'=>$d['date_fin'],'campaign_code'=>$d['campaign_code'],'campaign_title'=>$d['campaign_title'],'host_name'=>$d['host_name'],'unit_name'=>$d['unit_name']
        ];
    }
    jsonResponse(true,'',['items'=>$items]);
}catch(Throwable $e){jsonResponse(false,'Erreur documents : '.$e->getMessage(),[],500);} 

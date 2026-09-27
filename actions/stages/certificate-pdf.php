<?php
/**
 * Endpoint de consultation d'une attestation de stage à partir de son jeton public.
 * Le document reste accessible seulement au stagiaire, à son hôpital ou à son établissement de formation autorisé.
 */
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/certificate-service.php';

/* Un rôle authentifié est obligatoire avant toute résolution du jeton d'attestation. */
requireRole(['SUPER_ADMIN','ADMIN_ACCUEIL','ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE','STAGIAIRE']);
$token=trim($_GET['token']??'');
if(!$token){http_response_code(400);exit('Attestation invalide.');}

try{
    /* Les autorisations complémentaires sont évaluées selon le propriétaire fonctionnel du document. */
    $data=certificateData($pdo,$token);
    if(!$data){http_response_code(404);exit('Attestation introuvable.');}

    $role=$_SESSION['role_code']??'';
    if($role==='ADMIN_ACCUEIL'){
        $hostId=(int)currentEtablissementId($pdo);
        if(!$hostId||(int)$data['host_id']!==$hostId){http_response_code(403);exit('Accès interdit.');}
    }
    if(in_array($role,['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE'],true)){
        /* Accès établissement de formation : autorisé si le document appartient à une de ses campagnes. */
        $eid=(int)currentEtablissementId($pdo);
        $s=$pdo->prepare('SELECT owner_etablissement_id FROM stage_campaigns WHERE id=? LIMIT 1');
        $s->execute([(int)($data['campaign_id']??0)]);
        if(!$eid||(int)$s->fetchColumn()!==$eid){http_response_code(403);exit('Accès interdit.');}
    }
    if($role==='STAGIAIRE'){
        $s=$pdo->prepare('SELECT id FROM student_profiles WHERE user_id=? LIMIT 1');
        $s->execute([(int)$_SESSION['user_id']]);$studentId=(int)$s->fetchColumn();
        if(!$studentId||$studentId!==(int)$data['student_id']){http_response_code(403);exit('Accès interdit.');}
    }

    /* Le service génère le rendu officiel à partir des données contrôlées de l'attestation. */
    $html=certificateRenderHtml($pdo,$data);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;exit;
}catch(Throwable $e){http_response_code(500);exit('Erreur attestation : '.htmlspecialchars($e->getMessage()));}

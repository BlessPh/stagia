<?php
require_once __DIR__.'/../../config/config.php';require_once __DIR__.'/../../config/database.php';require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../includes/ajax.php';
try{
    requireRole(['SUPER_ADMIN']);verifyAjaxCsrf();
    $host=(int)($_POST['host_etablissement_id']??0);$level=(int)($_POST['academic_level_id']??0);$amount=(float)($_POST['amount']??0);$cur=strtoupper(trim($_POST['currency']??'USD'));
    if(!$host||!$level)jsonResponse(false,'Sélectionnez l’hôpital et le niveau.',[],422);
    if($amount<0)jsonResponse(false,'Montant invalide.',[],422);
    if(!preg_match('/^[A-Z]{3,10}$/',$cur))jsonResponse(false,'Devise invalide.',[],422);
    $s=$pdo->prepare("SELECT COUNT(*) FROM etablissements WHERE id=? AND type_etablissement='HOPITAL'");$s->execute([$host]);if(!$s->fetchColumn())jsonResponse(false,'Hôpital invalide.',[],422);
    $s=$pdo->prepare("SELECT COUNT(*) FROM academic_levels WHERE id=? AND actif=1");$s->execute([$level]);if(!$s->fetchColumn())jsonResponse(false,'Niveau invalide.',[],422);
    $s=$pdo->prepare("INSERT INTO stagia_hospital_level_fees(host_etablissement_id,academic_level_id,amount,currency,actif) VALUES(?,?,?,?,1) ON DUPLICATE KEY UPDATE amount=VALUES(amount),currency=VALUES(currency),actif=1,updated_at=NOW()");
    $s->execute([$host,$level,$amount,$cur]);jsonResponse(true,'Frais STAGIA enregistré.');
}catch(Throwable $e){jsonResponse(false,$e->getMessage(),[],500);}
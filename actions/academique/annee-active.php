<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/permissions.php';

requireRole(['ADMIN_ETABLISSEMENT','RESPONSABLE_PEDAGOGIQUE']);

if($_SERVER['REQUEST_METHOD']!=='POST') exit('Requête invalide.');
if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')) exit('Requête invalide.');

$etablissementId=currentEtablissementId($pdo);
$id=(int)($_POST['id']??0);

$stmt=$pdo->prepare("SELECT id FROM annees_academiques WHERE id=? AND etablissement_id=?");
$stmt->execute([$id,$etablissementId]);
if(!$stmt->fetch()) exit('Année académique introuvable.');

try{
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE annees_academiques SET actif=0 WHERE etablissement_id=?")
        ->execute([$etablissementId]);

    $pdo->prepare("UPDATE annees_academiques SET actif=1 WHERE id=? AND etablissement_id=?")
        ->execute([$id,$etablissementId]);

    $pdo->commit();

    header('Location: '.BASE_URL.'/views/academique/annees.php?active=1');
    exit;

}catch(Throwable $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    exit('Erreur lors de la modification.');
}
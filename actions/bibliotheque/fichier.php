<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/bibliotheque.php';
try{
    $c=biblioContexte($pdo);$d=biblioDocument($pdo,(int)($_GET['id']??0));
    if(!biblioLit($c,$d)||$d['statut']==='corbeille'){http_response_code(403);exit('Document inaccessible.');}
    if(!preg_match('/^[a-f0-9]{64}$/D',$d['nom_stockage']))throw new RuntimeException('Nom de stockage invalide.');
    $path=biblioDossier().'/'.$d['nom_stockage'];
    if(!is_file($path))throw new RuntimeException('Fichier absent.');
    $download=($_GET['telecharger']??'')==='1';
    if($download&&!biblioTelecharge($c,$d)){
        http_response_code(403);header('Cache-Control: no-store');
        exit('Le déposant n’a pas autorisé le téléchargement de ce document.');
    }
    $column=$download?'telechargements':'lectures';
    $pdo->prepare("INSERT INTO bibliotheque_consultations(utilisateur_id,document_id,$column)
        VALUES(?,?,1) ON DUPLICATE KEY UPDATE $column=$column+1,derniere_consultation=NOW()")->execute([$c['user'],$d['id']]);
    session_write_close();
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
    header('Content-Type: '.$d['mime']);
    header('Content-Length: '.filesize($path));
    header("Content-Disposition: ".($download?'attachment':'inline')."; filename=\"document\"; filename*=UTF-8''".rawurlencode($d['nom_original']));
    readfile($path);
}catch(Throwable $e){
    error_log('[BIBLIOTHEQUE FICHIER] '.$e->getMessage());
    http_response_code(404);exit('Document indisponible.');
}

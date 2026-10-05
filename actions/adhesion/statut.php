<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

class AdhesionStatusException extends RuntimeException{}

if($_SERVER['REQUEST_METHOD']!=='POST'||($_SESSION['role_code']??'')!=='SUPER_ADMIN'){
    http_response_code(403);
    exit('Accès refusé.');
}

$csrf=(string)($_POST['csrf']??'');
if(empty($_SESSION['csrf'])||$csrf===''||!hash_equals((string)$_SESSION['csrf'],$csrf)){
    http_response_code(403);
    exit('Requête invalide.');
}

$id=(int)($_POST['id']??0);
$status=strtoupper(trim((string)($_POST['statut']??'')));
$comment=trim((string)($_POST['commentaire']??''));
$allowed=['EN_EXAMEN','A_COMPLETER','REJETEE'];

if(!$id||!in_array($status,$allowed,true)){
    http_response_code(422);
    exit('Décision invalide.');
}

if(in_array($status,['A_COMPLETER','REJETEE'],true)&&$comment===''){
    $_SESSION['adhesion_action_error']=$status==='REJETEE'
        ?'Le motif du rejet est obligatoire.'
        :'Précisez le complément demandé.';
    header('Location: '.BASE_URL.'/views/adhesions/show.php?id='.$id);
    exit;
}

try{
    $pdo->beginTransaction();

    $statement=$pdo->prepare("SELECT statut FROM demandes_adhesion WHERE id=? LIMIT 1 FOR UPDATE");
    $statement->execute([$id]);
    $current=$statement->fetchColumn();

    if($current===false){
        throw new AdhesionStatusException('Demande introuvable.');
    }

    if(!in_array($current,['SOUMISE','EN_EXAMEN','A_COMPLETER'],true)){
        throw new AdhesionStatusException('Cette demande a déjà reçu une décision définitive.');
    }

    $statement=$pdo->prepare("
        UPDATE demandes_adhesion
        SET statut=?,commentaire_admin=?,traite_par=?,traite_le=NOW()
        WHERE id=?
    ");
    $statement->execute([
        $status,
        $comment!==''?$comment:null,
        (int)($_SESSION['user_id']??0)?:null,
        $id
    ]);

    $pdo->commit();
    header('Location: '.BASE_URL.'/views/adhesions/show.php?id='.$id.'&updated=1');
    exit;
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[ADHESION STATUS] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    $_SESSION['adhesion_action_error']=$e instanceof AdhesionStatusException
        ?$e->getMessage()
        :'Impossible de mettre à jour cette demande.';
    header('Location: '.BASE_URL.'/views/adhesions/show.php?id='.$id);
    exit;
}

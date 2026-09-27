<?php
declare(strict_types=1);

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

$utilisateur=(int)($_SESSION['user_id']??0);

try{
    $q=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE utilisateur_id=? AND lue_le IS NULL AND archivee_le IS NULL');
    $q->execute([$utilisateur]);
    $notifications=(int)$q->fetchColumn();

    $q=$pdo->prepare("SELECT COUNT(*) FROM messages m JOIN participants_conversations pc ON pc.conversation_id=m.conversation_id AND pc.utilisateur_id=? AND pc.quitte_le IS NULL JOIN conversations c ON c.id=m.conversation_id AND c.statut<>'archivee' WHERE m.auteur_utilisateur_id<>? AND m.statut IN ('envoye','modifie') AND m.id>COALESCE(pc.dernier_message_lu_id,0)");
    $q->execute([$utilisateur,$utilisateur]);
    $messages=(int)$q->fetchColumn();

    /* Chaque nouveau message crée déjà une notification : ne pas le compter deux fois. */
    echo json_encode(['success'=>true,'data'=>['notifications'=>$notifications,'messages'=>$messages,'total'=>max($notifications,$messages)]],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log((string)$e);
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Compteur indisponible.'],JSON_UNESCAPED_UNICODE);
}

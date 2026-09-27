<?php
/**
 * Endpoint AJAX de rotation de la clé API d'un appareil de pointage.
 * La nouvelle clé est fournie une seule fois dans la réponse sécurisée.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

/* Dépendances de base, réponse JSON et contrôle d'accès métier. */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* La rotation d'une clé est limitée à l'accueil disposant du droit de gestion. */
requireAjaxRole(['ADMIN_ACCUEIL']);
if(function_exists('contextPermission')&&!contextPermission('attendance.device.manage'))
    jsonResponse(false,'Permission insuffisante.',[],403);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* Identification de l'appareil et de l'auteur depuis la session et le POST. */
    $hostId=(int)currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);
    $id=(int)($_POST['id']??0);

    if(!$hostId||!$userId||!$id)
        jsonResponse(false,'Appareil invalide.',[],422);

    /* Transaction et verrou de ligne pour empêcher deux rotations simultanées. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT id,code,nom
        FROM attendance_devices
        WHERE id=? AND host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$id,$hostId]);
    $device=$s->fetch(PDO::FETCH_ASSOC);

    if(!$device)
        throw new RuntimeException('Appareil introuvable.');

    /* La base conserve l'empreinte SHA-256, jamais la clé API lisible. */
    $plainKey=bin2hex(random_bytes(24));
    $hash=hash('sha256',$plainKey);

    $s=$pdo->prepare("
        UPDATE attendance_devices
        SET api_key_hash=?,updated_at=CURRENT_TIMESTAMP
        WHERE id=? AND host_etablissement_id=?
    ");
    $s->execute([$hash,$id,$hostId]);

    $pdo->commit();

    /* Trace d'audit sans exposer la clé nouvellement produite. */
    error_log(
        '[ATTENDANCE DEVICE KEY ROTATED] device_id='.$id.
        ' host_id='.$hostId.' user_id='.$userId
    );

    jsonResponse(
        true,
        'Nouvelle clé générée. Copiez-la maintenant : l’ancienne clé est révoquée.',
        ['id'=>$id,'code'=>$device['code'],'api_key'=>$plainKey]
    );

}catch(Throwable $e){
    /* Retour arrière garanti si la rotation échoue avant la validation. */
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    error_log('[ATTENDANCE DEVICE REGENERATE KEY] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}

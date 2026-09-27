<?php
/**
 * Endpoint AJAX de création ou modification d'un appareil de pointage.
 * Une clé API n'est affichée qu'au moment précis de la création.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

/* Dépendances pour la persistance, la réponse JSON et la génération d'identifiants. */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/attendance-devices.php';

/* L'administration des appareils est réservée à l'accueil habilité. */
requireAjaxRole(['ADMIN_ACCUEIL']);
if(function_exists('contextPermission')&&!contextPermission('attendance.device.manage'))
    jsonResponse(false,'Permission insuffisante.',[],403);

$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

try{
    /* Normalisation des données soumises depuis le formulaire de configuration. */
    $hostId=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $id=(int)($_POST['id']??0);
    $code=strtoupper(trim($_POST['code']??''));
    $nom=trim($_POST['nom']??'');
    $type=strtoupper(trim($_POST['type']??''));
    $unitId=(int)($_POST['host_unit_id']??0);
    $fabricant=trim($_POST['fabricant']??'');
    $modele=trim($_POST['modele']??'');
    $mode=strtoupper(trim($_POST['integration_mode']??'PUSH'));
    $late=trim($_POST['heure_limite_arrivee']??'');

    if(!$hostId||$code===''||$nom==='')jsonResponse(false,'Code et nom obligatoires.',[],422);
    if(!preg_match('/^[A-Z0-9._-]{2,80}$/',$code))jsonResponse(false,'Code appareil invalide.',[],422);
    if(!in_array($type,['BIOMETRIE','QR','RFID_NFC','MOBILE','GENERIC_API'],true))
        jsonResponse(false,'Type appareil invalide.',[],422);
    if(!in_array($mode,['PUSH','PULL','SDK','LOCAL_AGENT'],true))
        jsonResponse(false,'Mode d’intégration invalide.',[],422);

    if($unitId){
        $s=$pdo->prepare("SELECT 1 FROM host_units WHERE id=? AND host_etablissement_id=? AND actif=1 LIMIT 1");
        $s->execute([$unitId,$hostId]);
        if(!$s->fetchColumn())jsonResponse(false,'Service / unité invalide.',[],422);
    }

    /* Une modification ne régénère jamais la clé existante de l'appareil. */
    if($id){
        $s=$pdo->prepare("UPDATE attendance_devices
            SET code=?,nom=?,type=?,host_unit_id=?,fabricant=?,modele=?,integration_mode=?,heure_limite_arrivee=?
            WHERE id=? AND host_etablissement_id=?");
        $s->execute([$code,$nom,$type,$unitId?:null,$fabricant?:null,$modele?:null,$mode,$late?:null,$id,$hostId]);
        if(!$s->rowCount()){
            $c=$pdo->prepare("SELECT 1 FROM attendance_devices WHERE id=? AND host_etablissement_id=?");
            $c->execute([$id,$hostId]);
            if(!$c->fetchColumn())jsonResponse(false,'Appareil introuvable.',[],404);
        }
        jsonResponse(true,'Appareil modifié avec succès.',['id'=>$id]);
    }

    /* Seule l'empreinte est stockée ; la clé en clair est communiquée une seule fois. */
    $plainKey=bin2hex(random_bytes(24));
    $hash=hash('sha256',$plainKey);

    $s=$pdo->prepare("INSERT INTO attendance_devices(
        uuid,host_etablissement_id,host_unit_id,code,nom,type,fabricant,modele,
        integration_mode,api_key_hash,heure_limite_arrivee,actif,created_by
    ) VALUES(?,?,?,?,?,?,?,?,?,?,?,1,?)");
    $s->execute([
        attendanceDeviceUuid(),$hostId,$unitId?:null,$code,$nom,$type,
        $fabricant?:null,$modele?:null,$mode,$hash,$late?:null,$uid
    ]);

    jsonResponse(true,'Appareil créé. Copiez la clé maintenant : elle ne sera plus affichée.',[
        'id'=>(int)$pdo->lastInsertId(),
        'api_key'=>$plainKey
    ]);
}catch(PDOException $e){
    /* Le conflit d'unicité du code reçoit un message fonctionnel compréhensible. */
    if((string)$e->getCode()==='23000')jsonResponse(false,'Ce code appareil existe déjà dans cet établissement.',[],422);
    error_log('[ATTENDANCE DEVICE STORE] '.$e->getMessage());
    jsonResponse(false,'Erreur appareil.',[],500);
}catch(Throwable $e){
    /* Les autres incidents sont journalisés sans exposer de détail sensible au client. */
    error_log('[ATTENDANCE DEVICE STORE] '.$e->getMessage());
    jsonResponse(false,$e->getMessage(),[],422);
}

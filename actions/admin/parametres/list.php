<?php
require_once __DIR__.'/../../../config/database.php';
require_once __DIR__.'/../../../includes/permissions.php';
require_once __DIR__.'/../../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

try{
    $items=$pdo->query("
        SELECT id,setting_key,setting_value,setting_type,category,label,description,systeme,updated_at
        FROM system_settings
        WHERE setting_key NOT LIKE 'maishapay.%'
        ORDER BY FIELD(category,'PLATEFORME','SECURITE','ADHESION','NOTIFICATIONS','MAINTENANCE'),category,id
    ")->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',['items'=>$items]);
}catch(Throwable $e){
    error_log('[ADMIN SETTINGS LIST] '.$e->getMessage());
    jsonResponse(false,'Erreur serveur : '.$e->getMessage(),[],500);
}

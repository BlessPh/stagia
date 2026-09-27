<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);

try{
    $hostId=currentEtablissementId($pdo);

    if(!$hostId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    $stmt=$pdo->prepare("
        SELECT
            u.id,
            u.nom,
            u.postnom,
            u.prenom,
            u.email,
            u.telephone,
            eu.fonction,
            eu.principal,
            r.code AS role_code,
            r.nom AS role_name
        FROM etablissement_users eu
        INNER JOIN users u ON u.id=eu.user_id
        LEFT JOIN roles r ON r.id=u.role_id
        WHERE eu.etablissement_id=?
          AND u.actif=1
        ORDER BY u.nom,u.postnom,u.prenom
    ");

    $stmt->execute([$hostId]);

    jsonResponse(true,'',[
        'items'=>$stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);

}catch(Throwable $e){
    jsonResponse(false,'Erreur encadreurs : '.$e->getMessage(),[],500);
}
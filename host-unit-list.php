<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);

try{

    $hopitalId=currentEtablissementId($pdo);

    if(!$hopitalId)
        jsonResponse(false,'Aucun établissement associé.',[],403);


    $stmt=$pdo->prepare("
        SELECT
            u.id,
            u.code,
            u.nom,
            u.type,
            u.description,
            u.capacite,
            u.actif,
            u.parent_id,
            p.nom AS parent_nom,
            p.code AS parent_code

        FROM host_units u

        LEFT JOIN host_units p
            ON p.id=u.parent_id
           AND p.host_etablissement_id=u.host_etablissement_id

        WHERE u.host_etablissement_id=?

        ORDER BY
            CASE WHEN u.type='SERVICE' THEN 0 ELSE 1 END,
            COALESCE(p.nom,u.nom),
            u.nom
    ");

    $stmt->execute([$hopitalId]);

    $items=$stmt->fetchAll(PDO::FETCH_ASSOC);


    $stats=[
        'total'=>count($items),
        'services'=>0,
        'unites'=>0,
        'actifs'=>0
    ];


    foreach($items as $item){

        if($item['type']==='SERVICE')
            $stats['services']++;

        if($item['type']==='UNITE')
            $stats['unites']++;

        if((int)$item['actif']===1)
            $stats['actifs']++;
    }


    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>$stats
    ]);


}catch(Throwable $e){

    jsonResponse(
        false,
        'Erreur chargement : '.$e->getMessage(),
        [],
        500
    );
}
<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

try{
    $items=$pdo->query("SELECT t.*,
        (SELECT COUNT(*) FROM etablissements e WHERE e.type_etablissement=t.code) nb_etablissements,
        (SELECT COUNT(*) FROM academic_structure_templates m WHERE m.type_etablissement=t.code) nb_modeles
        FROM establishment_types t
        ORDER BY t.ordre,t.libelle")->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>[
            'total'=>count($items),
            'actifs'=>count(array_filter($items,fn($x)=>(int)$x['actif']===1)),
            'academiques'=>count(array_filter($items,fn($x)=>(int)$x['academic_enabled']===1)),
            'accueil'=>count(array_filter($items,fn($x)=>(int)$x['host_enabled']===1))
        ]
    ]);
}catch(Throwable $e){
    error_log('[CONFIG TYPES ETABLISSEMENTS] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur Types d’établissements : '.$e->getMessage(),[],500);
}

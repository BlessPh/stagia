<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

try{
    $items=$pdo->query("SELECT t.*,
        (SELECT COUNT(*) FROM academic_structure_template_unit_types x WHERE x.type_unite=t.code AND x.actif=1) nb_modeles,
        (SELECT COUNT(*) FROM etablissement_academic_unit_types x WHERE x.type_unite=t.code AND x.actif=1) nb_etablissements,
        (SELECT COUNT(*) FROM facultes f WHERE f.type_unite=t.code) nb_unites
        FROM academic_unit_types t
        ORDER BY t.ordre,t.libelle")->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>[
            'total'=>count($items),
            'actifs'=>count(array_filter($items,fn($x)=>(int)$x['actif']===1)),
            'utilises'=>count(array_filter($items,fn($x)=>(int)$x['nb_unites']>0)),
            'personnalises'=>count(array_filter($items,fn($x)=>(int)$x['systeme']===0))
        ]
    ]);
}catch(Throwable $e){
    error_log('[CONFIG TYPES UNITES] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur Types d’unités académiques : '.$e->getMessage(),[],500);
}

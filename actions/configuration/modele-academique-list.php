<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/permissions.php';
require_once __DIR__.'/../../includes/ajax.php';

requireAjaxRole(['SUPER_ADMIN']);

try{
    $items=$pdo->query("SELECT t.*,
        (SELECT COUNT(*) FROM etablissement_academic_settings s WHERE s.source_template_id=t.id) nb_etablissements,
        (SELECT COUNT(*) FROM etablissement_academic_settings s WHERE s.source_template_id=t.id AND s.personnalise=1) nb_personnalises
        FROM academic_structure_templates t
        ORDER BY t.type_etablissement,t.is_default DESC,t.nom")->fetchAll(PDO::FETCH_ASSOC);

    $units=$pdo->query("SELECT template_id,type_unite,libelle,ordre,actif
        FROM academic_structure_template_unit_types
        ORDER BY template_id,ordre,libelle")->fetchAll(PDO::FETCH_ASSOC);

    $by=[];
    foreach($units as $u)$by[(int)$u['template_id']][]=$u;
    foreach($items as &$i)$i['unit_types']=$by[(int)$i['id']]??[];
    unset($i);

    $types=[];
    $s=$pdo->query("SELECT code,libelle
        FROM establishment_types
        WHERE academic_enabled=1 AND actif=1
        ORDER BY ordre,libelle");
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $t)$types[$t['code']]=$t['libelle'];

    $unitCatalog=$pdo->query("SELECT code,libelle,actif,ordre
        FROM academic_unit_types
        ORDER BY ordre,libelle")->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'items'=>$items,
        'types'=>$types,
        'unit_catalog'=>$unitCatalog,
        'stats'=>[
            'total'=>count($items),
            'actifs'=>count(array_filter($items,fn($i)=>(int)$i['actif']===1)),
            'etablissements'=>(int)$pdo->query("SELECT COUNT(*) FROM etablissement_academic_settings")->fetchColumn(),
            'personnalises'=>(int)$pdo->query("SELECT COUNT(*) FROM etablissement_academic_settings WHERE personnalise=1")->fetchColumn()
        ]
    ]);
}catch(Throwable $e){
    error_log('[CONFIG MODELES] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur Modèles académiques : '.$e->getMessage(),[],500);
}

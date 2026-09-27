<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/geographie.php';
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='GET')jsonResponse(false,'Méthode non autorisée.',[],405);
try{
    $parent=geoEntier($_GET['parent_id']??null,true);
    // Seulement les noms du référentiel actif : aucune donnée de demandeur ou de compte.
    $items=array_map(fn($r)=>array_intersect_key($r,array_flip(['id','nom','type'])),geoEnfants($pdo,$parent));
    jsonResponse(true,'',['items'=>$items]);
}catch(InvalidArgumentException $e){jsonResponse(false,$e->getMessage(),[],422);}
catch(Throwable $e){error_log('[GEOGRAPHIE OPTIONS] '.$e->getMessage());jsonResponse(false,'Le référentiel est temporairement indisponible. Réessayez plus tard.',[],503);}

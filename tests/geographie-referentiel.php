<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$fichier=__DIR__.'/../database/migrations/2026_09_14_000008_geographie_subdivisions.sql';
$sql=file_get_contents($fichier);
if($sql===false)throw new RuntimeException('Migration du référentiel absente.');

$provinces=[];
preg_match_all("/'(RDC-P-[A-Z-]+)'/",file_get_contents(__DIR__.'/../database/migrations/2026_09_14_000007_geographie.sql')?:'', $m);
foreach(array_unique($m[1]) as $code)$provinces[$code]='PROVINCE';
if(count($provinces)!==26)throw new RuntimeException('Les 26 provinces de base sont requises.');

$unites=$provinces;$comptes=[];$tests=0;
$motif="/SELECT '([^']+)',id,'(VILLE|TERRITOIRE|COMMUNE|SECTEUR|CHEFFERIE)','(?:''|[^'])*','(?:''|[^'])*' FROM geographie_unites WHERE code='([^']+)'/u";
preg_match_all($motif,$sql,$lignes,PREG_SET_ORDER);
foreach($lignes as $ligne){
    [, $code,$type,$parent]=$ligne;
    if(isset($unites[$code]))throw new RuntimeException("Code géographique dupliqué : $code");
    if(!isset($unites[$parent]))throw new RuntimeException("Parent absent pour $code : $parent");
    $autorises=match($unites[$parent]){
        'PROVINCE'=>str_starts_with($parent,'RDC-P-KINSHASA')?['COMMUNE']:['VILLE','TERRITOIRE'],
        'VILLE'=>['COMMUNE'],'TERRITOIRE'=>['COMMUNE','SECTEUR','CHEFFERIE'],default=>[]
    };
    if(!in_array($type,$autorises,true))throw new RuntimeException("Chemin interdit : {$unites[$parent]} > $type");
    $unites[$code]=$type;$comptes[$type]=($comptes[$type]??0)+1;$tests++;
}
$attendus=['VILLE'=>33,'TERRITOIRE'=>145,'COMMUNE'=>139,'SECTEUR'=>469,'CHEFFERIE'=>265];
foreach($attendus as $type=>$total)if(($comptes[$type]??0)!==$total)throw new RuntimeException("$type : ".($comptes[$type]??0)." au lieu de $total");
if(!str_contains($sql,'NOT EXISTS'))throw new RuntimeException('Migration non idempotente.');
echo "$tests subdivisions contrôlées : parents, types, codes et volumes valides.\n";

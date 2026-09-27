<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/geographie.php';
// Exécutable sans base : chemins simulés, mêmes fonctions que les endpoints.
final class GeoTestStatement extends PDOStatement{
    private ?array $row=null;
    public function __construct(private array $rows){}
    public function execute(?array $params=null):bool{$this->row=$this->rows[(int)($params[0]??0)]??null;return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row?:false;}
}
final class GeoTestPDO extends PDO{
    public function __construct(private array $rows){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new GeoTestStatement($this->rows);}
}
$tests=0;
function geoTest(bool $ok,string $label):void{global $tests;if(!$ok)throw new RuntimeException('ÉCHEC : '.$label);$tests++;}
function geoRefuse(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException $e){geoTest(true,$label);return;}throw new RuntimeException('ÉCHEC : '.$label);}
$rows=[
 1=>['id'=>1,'code'=>'RDC-P-TEST','nom'=>'Province test','type'=>'PROVINCE','parent_id'=>null,'actif'=>1],
 2=>['id'=>2,'code'=>'T2','nom'=>'Territoire test','type'=>'TERRITOIRE','parent_id'=>1,'actif'=>1],
 3=>['id'=>3,'code'=>'S3','nom'=>'Secteur test','type'=>'SECTEUR','parent_id'=>2,'actif'=>1],
 4=>['id'=>4,'code'=>'P4','nom'=>'Autre province','type'=>'PROVINCE','parent_id'=>null,'actif'=>1],
 5=>['id'=>5,'code'=>'RDC-P-KINSHASA','nom'=>'Kinshasa','type'=>'PROVINCE','parent_id'=>null,'actif'=>1],
 6=>['id'=>6,'code'=>'C6','nom'=>'Commune test','type'=>'COMMUNE','parent_id'=>5,'actif'=>1]
];
$pdo=new GeoTestPDO($rows);
$d=['geo_province_id'=>'1','geo_niveau2_id'=>'2','geo_niveau3_id'=>'3','province'=>'Valeur falsifiée','ville'=>'Valeur falsifiée'];
$geo=geoValiderAdhesion($pdo,$d);
geoTest($geo['province']==='Province test'&&$geo['ville']==='Territoire test'&&$geo['a_completer']===0,'Libellés canoniques et chemin rural complet');
geoRefuse(fn()=>geoValiderAdhesion($pdo,array_replace($d,['geo_province_id'=>'4'])),'Province étrangère');
geoRefuse(fn()=>geoValiderAdhesion($pdo,array_replace($d,['geo_niveau2_id'=>''])),'Saut de niveau');
geoRefuse(fn()=>geoValiderAdhesion($pdo,array_replace($d,['geo_niveau3_id'=>'999'])),'Localité absente');
geoRefuse(fn()=>geoValiderAdhesion($pdo,['geo_province_id'=>'1']),'Saisie libre obligatoire si niveau manquant');
geoTest(geoValiderAdhesion($pdo,['geo_province_id'=>'1','geo_ville_libre'=>'Lieu à vérifier'])['a_completer']===1,'Référentiel partiel signalé');
geoRefuse(fn()=>geoValiderAdhesion($pdo,['geo_province_id'=>'1','geo_niveau2_id'=>'2']),'Subdivision manquante à préciser');
geoTest(geoValiderAdhesion($pdo,['geo_province_id'=>'5','geo_niveau2_id'=>'6'])['a_completer']===0,'Ville-province de Kinshasa');
geoTest(geoValiderAdhesion($pdo,['geo_province_id'=>'5','geo_niveau2_id'=>'6'])['ville']==='Kinshasa','La commune ne remplace pas le nom de la ville de Kinshasa');
$inactive=$rows;$inactive[2]['actif']=0;
geoRefuse(fn()=>geoValiderAdhesion(new GeoTestPDO($inactive),$d),'Parent désactivé');
$cycle=$rows;$cycle[2]['parent_id']=3;
geoRefuse(fn()=>geoChemin(new GeoTestPDO($cycle),3),'Cycle');
$invalid=$rows;$invalid[3]['parent_id']=1;
geoRefuse(fn()=>geoChemin(new GeoTestPDO($invalid),3),'Secteur directement sous province');
foreach(['0','-1','1x','1 OR 1=1','2147483648',[],null] as $v)geoRefuse(fn()=>geoEntier($v),'Identifiant invalide');
geoTest(geoEntier('',true)===null,'Identifiant optionnel');
geoTest(geoTypesEnfants($rows[6])===[],'Commune terminale');
echo "$tests contrôles géographiques réussis.\n";

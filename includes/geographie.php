<?php
declare(strict_types=1);

/** Référentiel partagé. Aucune authentification implicite : l'API de gestion la contrôle. */
function geoTypesEnfants(?array $parent):array{
    if($parent===null)return ['PROVINCE'];
    return match($parent['type']){
        'PROVINCE'=>($parent['code']==='RDC-P-KINSHASA'?['COMMUNE']:['VILLE','TERRITOIRE']),
        'VILLE'=>['COMMUNE'],
        'TERRITOIRE'=>['COMMUNE','SECTEUR','CHEFFERIE'],
        default=>[]
    };
}

function geoEntier(mixed $value,bool $facultatif=false):?int{
    if($facultatif&&($value===null||$value===''))return null;
    if(!is_scalar($value)||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value))
        throw new InvalidArgumentException('Identifiant géographique invalide.');
    $id=(int)$value;
    if($id>2147483647)throw new InvalidArgumentException('Identifiant géographique trop grand.');
    return $id;
}

function geoUnite(PDO $pdo,int $id,bool $verrou=false):array{
    $s=$pdo->prepare('SELECT * FROM geographie_unites WHERE id=?'.($verrou?' FOR UPDATE':''));
    $s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new InvalidArgumentException('Localité introuvable. Rechargez la liste.');
    return $row;
}

/** Borné à trois niveaux ; un parent invalide ou un cycle est toujours refusé. */
function geoChemin(PDO $pdo,int $id,bool $actif=true,bool $verrou=false):array{
    $rows=[];$vus=[];
    while($id){
        if(isset($vus[$id])||count($rows)>=3)throw new InvalidArgumentException('Hiérarchie géographique incohérente.');
        $vus[$id]=true;$r=geoUnite($pdo,$id,$verrou);
        if($actif&&!(int)$r['actif'])throw new InvalidArgumentException('Cette localité ou son parent est désactivé.');
        array_unshift($rows,$r);$id=(int)($r['parent_id']??0);
    }
    $parent=null;
    foreach($rows as $r){
        if(!in_array($r['type'],geoTypesEnfants($parent),true))throw new InvalidArgumentException('Hiérarchie géographique incohérente.');
        $parent=$r;
    }
    return $rows;
}

function geoEnfants(PDO $pdo,?int $parentId,bool $actifs=true):array{
    if($parentId!==null)geoChemin($pdo,$parentId,$actifs);
    $s=$pdo->prepare('SELECT id,code,nom,type,parent_id,actif,source,version FROM geographie_unites WHERE '.
        ($parentId===null?'parent_id IS NULL':'parent_id=?').($actifs?' AND actif=1':'').' ORDER BY nom,id');
    $s->execute($parentId===null?[]:[$parentId]);return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Canonicalise les colonnes historiques sans faire confiance aux libellés POST. */
function geoValiderAdhesion(PDO $pdo,array $data,bool $verrou=false):array{
    $ids=[geoEntier($data['geo_province_id']??null),geoEntier($data['geo_niveau2_id']??null,true),geoEntier($data['geo_niveau3_id']??null,true)];
    if($ids[2]!==null&&$ids[1]===null)throw new InvalidArgumentException('Choisissez d’abord la ville ou le territoire.');
    $ids=array_values(array_filter($ids,fn($id)=>$id!==null));
    $rows=geoChemin($pdo,$ids[count($ids)-1],true,$verrou);
    if(array_map(fn($r)=>(int)$r['id'],$rows)!==$ids)throw new InvalidArgumentException('Les localités sélectionnées ne sont pas rattachées entre elles.');
    $precision=trim((string)($data['geo_precision']??''));
    if(mb_strlen($precision)>255)throw new InvalidArgumentException('Limitez la précision géographique à 255 caractères.');
    $villeLibre=trim((string)($data['geo_ville_libre']??''));
    if(mb_strlen($villeLibre)>100)throw new InvalidArgumentException('Limitez la ville ou le territoire à 100 caractères.');
    // La saisie libre complète un référentiel encore partiel ; elle ne crée jamais une unité officielle.
    if(count($ids)===1&&$villeLibre==='')throw new InvalidArgumentException('Sélectionnez votre ville/territoire ou renseignez la localité manquante.');
    $dernier=$rows[count($rows)-1];
    if(geoTypesEnfants($dernier)!==[]&&count($ids)>1&&$precision==='')
        throw new InvalidArgumentException('Choisissez la subdivision ou précisez la localité manquante.');
    return ['unite_id'=>(int)$dernier['id'],'province'=>$rows[0]['nom'],
        'ville'=>$rows[0]['code']==='RDC-P-KINSHASA'?$rows[0]['nom']:(count($rows)>1?$rows[1]['nom']:$villeLibre),
        'precision'=>$precision,'ville_libre'=>count($rows)===1?$villeLibre:null,
        'a_completer'=>geoTypesEnfants($dernier)!==[]?1:0,
        'chemin'=>array_map(fn($r)=>['id'=>(int)$r['id'],'nom'=>$r['nom'],'type'=>$r['type']],$rows)];
}

function geoEnregistrerAdhesion(PDO $pdo,int $demandeId,array $geo):void{
    $s=$pdo->prepare('INSERT INTO geographie_localisations (demande_adhesion_id,unite_id,precision_localite,ville_libre,a_completer,chemin_snapshot) VALUES(?,?,?,?,?,?)');
    $s->execute([$demandeId,$geo['unite_id'],$geo['precision']?:null,$geo['ville_libre'],$geo['a_completer'],json_encode($geo['chemin'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}

function geoRattacherEtablissement(PDO $pdo,int $demandeId,int $etablissementId):void{
    // Les anciennes demandes sans localisation structurée restent validables.
    $s=$pdo->prepare('UPDATE geographie_localisations SET etablissement_id=? WHERE demande_adhesion_id=? AND etablissement_id IS NULL');
    $s->execute([$etablissementId,$demandeId]);
}

function geoVerifierGestion():void{
    if(!estSuperAdminPrincipal()){
        http_response_code(403);exit('Accès réservé au super administrateur principal de STAGIA.');
    }
}

/** Parent/type stables : une correction de nom ne déplace pas les dossiers existants. */
function geoSauverUnite(PDO $pdo,array $data,int $acteur):int{
    $id=geoEntier($data['id']??null,true);$parentId=geoEntier($data['parent_id']??null,true);
    $nom=trim((string)($data['nom']??''));$source=trim((string)($data['source']??''));
    $type=(string)($data['type']??'');$actif=(string)($data['actif']??'');
    if($nom===''||mb_strlen($nom)>100||$source===''||mb_strlen($source)>500||!in_array($actif,['0','1'],true))
        throw new InvalidArgumentException('Nom (100 caractères), source (500 caractères) et état requis.');
    $pdo->beginTransaction();
    try{
        // Sérialise la maintenance et la désactivation des branches (également utilisée à la soumission).
        $pdo->query('SELECT id FROM geographie_version WHERE id=1 FOR UPDATE')->fetchColumn();
        $avant=$id===null?null:geoUnite($pdo,$id,true);
        if($avant!==null){
            if((string)$avant['version']!==(string)($data['version']??''))throw new InvalidArgumentException('Cette fiche a changé. Rechargez-la avant de modifier.');
            if(($avant['parent_id']===null?null:(int)$avant['parent_id'])!==$parentId||$avant['type']!==$type)
                throw new InvalidArgumentException('Le parent et le type ne peuvent pas être modifiés. Désactivez cette fiche et créez la bonne localité.');
        }
        $parent=$parentId===null?null:geoUnite($pdo,$parentId,true);
        if(!in_array($type,geoTypesEnfants($parent),true))throw new InvalidArgumentException('Ce type ne peut pas être rattaché au parent sélectionné.');
        if($parentId!==null)geoChemin($pdo,$parentId,$actif==='1',true);
        if($avant!==null&&$actif==='0'){
            $s=$pdo->prepare('SELECT id FROM geographie_unites WHERE parent_id=? AND actif=1 LIMIT 1');$s->execute([$id]);
            if($s->fetchColumn())throw new InvalidArgumentException('Désactivez d’abord les subdivisions actives. Les dossiers conservés ne seront pas supprimés.');
        }
        if($id===null){
            $s=$pdo->prepare('INSERT INTO geographie_unites(code,parent_id,type,nom,source,actif) VALUES(?,?,?,?,?,?)');
            $s->execute(['RDC-U-'.strtoupper(bin2hex(random_bytes(8))),$parentId,$type,$nom,$source,(int)$actif]);
            $id=(int)$pdo->lastInsertId();
        }else{
            $s=$pdo->prepare('UPDATE geographie_unites SET nom=?,source=?,actif=?,version=version+1 WHERE id=?');
            $s->execute([$nom,$source,(int)$actif,$id]);
        }
        $apres=geoUnite($pdo,$id);
        $s=$pdo->prepare('INSERT INTO geographie_journal(unite_id,acteur_id,action,avant,apres) VALUES(?,?,?,?,?)');
        $s->execute([$id,$acteur,$avant===null?'creation':'modification',json_encode($avant,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($apres,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        $pdo->exec('UPDATE geographie_version SET revision=revision+1 WHERE id=1');
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

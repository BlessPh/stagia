<?php
declare(strict_types=1);
require_once __DIR__.'/admin-scope.php';

/** Source unique des appartenances de lecture, sans élargir les droits de gestion. */
function biblioAppartenancesSql():string {
    return "SELECT ra.user_id,COALESCE(ra.etablissement_id,
          CASE WHEN ra.scope_type='ORGANIZATION' AND ra.scope_entity='ESTABLISHMENT' THEN ra.scope_id END) etablissement_id
        FROM role_assignments ra JOIN roles r ON r.id=ra.role_id AND r.actif=1
        WHERE ra.actif=1 AND ra.revoked_at IS NULL
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        UNION
        SELECT sp.user_id,se.etablissement_id
        FROM student_profiles sp JOIN student_enrollments se ON se.student_id=sp.id AND se.statut='ACTIF'
        UNION
        SELECT eu.user_id,eu.etablissement_id FROM etablissement_users eu
        JOIN users u ON u.id=eu.user_id JOIN roles r ON r.id=u.role_id AND r.actif=1
        WHERE NOT EXISTS(SELECT 1 FROM role_assignments ra WHERE ra.user_id=eu.user_id)";
}
function biblioCatalogueSql(array $c,string $alias='d'):string {
    if(!in_array($alias,['d'],true))throw new InvalidArgumentException('Alias non autorisé.');
    $ids=$c['org_ids']?implode(',',array_map('intval',$c['org_ids'])):'0';
    return $c['super']?"$alias.statut='publie'":
        "$alias.statut='publie' AND ($alias.visibilite='globale' OR ($alias.visibilite='etablissement' AND $alias.etablissement_id IN ($ids)))";
}
function biblioContexte(PDO $pdo):array {
    $u=(int)currentUserId();
    $s=$pdo->prepare("SELECT DISTINCT e.id,e.nom
        FROM (".biblioAppartenancesSql().") appartenance
        JOIN etablissements e ON e.id=appartenance.etablissement_id
        WHERE appartenance.user_id=?
        AND e.statut IN('VALIDE','ACTIF') ORDER BY e.nom");
    $s->execute([$u]);$orgs=$s->fetchAll(PDO::FETCH_ASSOC);
    $super=hasRole('SUPER_ADMIN');$gestion=[];
    $principal=etablissementPrincipalAdmin($pdo);
    foreach($orgs as $org){
        $id=(int)$org['id'];
        if($super||$principal===$id||hasPermission($pdo,'library.manage',['etablissement_id'=>$id]))
            $gestion[]=$id;
    }
    return ['user'=>$u,'super'=>$super,'organisations'=>$orgs,
        'org_ids'=>array_map('intval',array_column($orgs,'id')),'gestion'=>$gestion];
}
function biblioGere(array $c,array $d):bool {
    return $c['super']||($d['visibilite']==='etablissement'&&in_array((int)$d['etablissement_id'],$c['gestion'],true));
}
function biblioLit(array $c,array $d):bool {
    if(biblioGere($c,$d)||(int)$d['depose_par']===$c['user'])return true;
    return $d['statut']==='publie'&&($d['visibilite']==='globale'||in_array((int)$d['etablissement_id'],$c['org_ids'],true));
}
function biblioModifie(array $c,array $d):bool {
    return $d['statut']!=='corbeille'&&(biblioGere($c,$d)||
        ((int)$d['depose_par']===$c['user']&&in_array($d['statut'],['brouillon','rejete'],true)));
}
function biblioTelecharge(array $c,array $d):bool {
    return $d['statut']!=='corbeille'&&biblioLit($c,$d)&&(int)($d['telechargement_autorise']??0)===1;
}
function biblioRegleTelechargement(array $c,array $d):bool {
    return (int)$d['depose_par']===$c['user']&&$d['statut']!=='corbeille';
}
function biblioDocument(PDO $pdo,int $id,bool $lock=false):array {
    $s=$pdo->prepare('SELECT * FROM bibliotheque_documents WHERE id=?'.($lock?' FOR UPDATE':''));
    $s->execute([$id]);$d=$s->fetch(PDO::FETCH_ASSOC);
    if(!$d)throw new DomainException('Document introuvable.');
    return $d;
}
function biblioAudit(PDO $pdo,int $id,int $u,string $action,array $details=[]):void {
    $pdo->prepare('INSERT INTO bibliotheque_audit(document_id,acteur_id,action,details) VALUES(?,?,?,?)')
        ->execute([$id,$u,$action,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
/** À appeler dans la transaction de publication et sous verrou du document. */
function biblioNotifierPublication(PDO $pdo,array $d,int $acteur):int {
    if(!$pdo->inTransaction()||$d['statut']!=='publie')
        throw new LogicException('La notification exige une publication transactionnelle.');
    $where="u.actif=1 AND u.statut_compte='ACTIF' AND u.id<>?
      AND (EXISTS(SELECT 1 FROM role_assignments ra JOIN roles r ON r.id=ra.role_id AND r.actif=1
          WHERE ra.user_id=u.id AND ra.actif=1 AND ra.revoked_at IS NULL
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW()) AND (ra.ends_at IS NULL OR ra.ends_at>=NOW()))
        OR (NOT EXISTS(SELECT 1 FROM role_assignments ra WHERE ra.user_id=u.id)
          AND EXISTS(SELECT 1 FROM roles r WHERE r.id=u.role_id AND r.actif=1)))";
    $params=[$acteur];
    if($d['visibilite']==='etablissement'){
        $where.=" AND EXISTS(SELECT 1 FROM (".biblioAppartenancesSql().") a
          JOIN etablissements e ON e.id=a.etablissement_id AND e.statut IN('VALIDE','ACTIF')
          WHERE a.user_id=u.id AND a.etablissement_id=?)";
        $params[]=(int)$d['etablissement_id'];
    }
    $s=$pdo->prepare('SELECT u.id FROM users u WHERE '.$where.' ORDER BY u.id');
    $s->execute($params);$ids=$s->fetchAll(PDO::FETCH_COLUMN);
    $ins=$pdo->prepare("INSERT INTO notifications(identifiant_public,utilisateur_id,organisation_id,
      type_evenement,titre,contenu,donnees,lien_action) VALUES(?,?,?,'bibliotheque.publication',?,?,?,?)");
    foreach($ids as $id){
        $ins->execute([strtoupper(bin2hex(random_bytes(13))),(int)$id,$d['etablissement_id'],
          'Nouvelle ressource dans la bibliothèque',mb_substr($d['titre'],0,1000),
          json_encode(['document_id'=>(int)$d['id'],'revision'=>(int)$d['revision']],JSON_THROW_ON_ERROR),
          BASE_URL.'/views/bibliotheque/index.php?document='.(int)$d['id'].'#catalogue']);
    }
    biblioAudit($pdo,(int)$d['id'],$acteur,'notifications_publication',['destinataires'=>count($ids),'revision'=>(int)$d['revision']]);
    return count($ids);
}
function biblioDossier():string {
    $project=realpath(__DIR__.'/..');
    $path=getenv('BIBLIOTHEQUE_STORAGE_DIR')?:dirname($project).'/stagia_bibliotheque_privee';
    if(!str_starts_with($path,'/'))throw new RuntimeException('Le stockage doit être un chemin absolu.');
    if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))throw new RuntimeException('Stockage indisponible.');
    $real=realpath($path);
    $web=realpath($_SERVER['DOCUMENT_ROOT']??'')?:$project;
    foreach([$project,$web] as $forbidden){
        if($real===$forbidden||str_starts_with($real,$forbidden.'/'))
            throw new RuntimeException('Le stockage doit être hors du répertoire Web.');
    }
    return $real;
}
function biblioFichier(array $f):array {
    if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new DomainException('Ajoutez un fichier valide (limite PHP et module : 20 Mo).');
    if(!is_uploaded_file($f['tmp_name']))throw new DomainException('Transfert non valide.');
    $size=filesize($f['tmp_name']);
    if($size<1||$size>20*1024*1024)throw new DomainException('Le fichier doit faire entre 1 octet et 20 Mo.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
    $allowed=['pdf'=>['application/pdf'],'txt'=>['text/plain'],
        'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'webp'=>['image/webp']];
    if(!isset($allowed[$ext])||!in_array($mime,$allowed[$ext],true))throw new DomainException('Formats acceptés : PDF, TXT, JPG, PNG et WEBP. Le contenu doit correspondre au format.');
    return ['nom_original'=>mb_substr(basename($f['name']),0,255),
        'nom_stockage'=>bin2hex(random_bytes(32)),'mime'=>$mime,'taille'=>$size,
        'sha256'=>hash_file('sha256',$f['tmp_name'])];
}
function biblioMetadonnees(array $p,array $c):array {
    $result=[];
    foreach(['titre'=>255,'auteur'=>200,'resume'=>10000,'mots_cles'=>500,'langue'=>50,'licence'=>255] as $key=>$limit){
        $v=trim((string)($p[$key]??''));
        if(($key!=='mots_cles'&&$v==='')||mb_strlen($v)>$limit)throw new DomainException('Champ invalide : '.$key);
        $result[$key]=$v;
    }
    $result['categorie_id']=(int)($p['categorie_id']??0);
    $year=trim((string)($p['annee']??''));
    if($year!==''&&(!ctype_digit($year)||(int)$year<1000||(int)$year>(int)date('Y')+1))throw new DomainException('Année invalide.');
    $result['annee']=$year===''?null:(int)$year;
    $result['visibilite']=(string)($p['visibilite']??'');
    if(!in_array($result['visibilite'],['globale','etablissement'],true))throw new DomainException('Visibilité invalide.');
    $result['etablissement_id']=$result['visibilite']==='globale'?null:(int)($p['etablissement_id']??0);
    if($result['visibilite']==='etablissement'&&!in_array($result['etablissement_id'],$c['org_ids'],true))throw new DomainException('Établissement hors de vos affectations actives.');
    return $result;
}

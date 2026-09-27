<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/bibliotheque.php';
header('Cache-Control: no-store');
try{
    $c=biblioContexte($pdo);$u=$c['user'];
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(($_GET['action']??'')==='notifications'){
            $visible=biblioCatalogueSql($c);
            $s=$pdo->prepare("SELECT n.id,n.lue_le,n.cree_le,d.id document_id,d.titre
              FROM notifications n JOIN bibliotheque_documents d ON d.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(n.donnees,'$.document_id')) AS UNSIGNED)
              WHERE n.utilisateur_id=? AND n.type_evenement='bibliotheque.publication' AND n.archivee_le IS NULL
              AND ($visible) ORDER BY n.id DESC LIMIT 50");
            $s->execute([$u]);jsonResponse(true,'',['items'=>$s->fetchAll(PDO::FETCH_ASSOC)]);
        }
        $categories=$pdo->query('SELECT id,nom FROM bibliotheque_categories ORDER BY nom')->fetchAll(PDO::FETCH_ASSOC);
        $id=(int)($_GET['id']??0);
        if($id){
            $d=biblioDocument($pdo,$id);
            if(!biblioLit($c,$d))jsonResponse(false,'Document inaccessible.',[],403);
            $d['gestion']=biblioGere($c,$d);$d['modifiable']=biblioModifie($c,$d);
            $d['regle_telechargement']=biblioRegleTelechargement($c,$d);
            $d['telechargeable']=biblioTelecharge($c,$d);
            $d['proprietaire']=(int)$d['depose_par']===$u;
            $d['historique']=[];
            if($d['gestion']||$d['proprietaire']){
                $s=$pdo->prepare("SELECT a.action,a.details,a.cree_le,CONCAT_WS(' ',u.prenom,u.nom) acteur
                    FROM bibliotheque_audit a JOIN users u ON u.id=a.acteur_id
                    WHERE a.document_id=? ORDER BY a.id DESC LIMIT 50");
                $s->execute([$id]);$d['historique']=$s->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($d['nom_stockage'],$d['sha256']);
            jsonResponse(true,'',['document'=>$d]);
        }
        $onglet=(string)($_GET['onglet']??'catalogue');
        if(!in_array($onglet,['catalogue','favoris','depots','validation','gestion','archives','corbeille'],true))throw new DomainException('Vue invalide.');
        $orgs=$c['org_ids'];$managed=$c['gestion'];
        $orgSql=$orgs?implode(',',array_map('intval',$orgs)):'0';
        $managedSql=$managed?implode(',',array_map('intval',$managed)):'0';
        $manager=$c['super']?'1=1':"(d.visibilite='etablissement' AND d.etablissement_id IN ($managedSql))";
        if(in_array($onglet,['gestion','validation'],true)&&!$c['super']&&!$managed)
            jsonResponse(false,'La validation est réservée aux gestionnaires habilités.',[],403);
        $s=$pdo->query("SELECT statut,COUNT(*) total FROM bibliotheque_documents d WHERE ($manager) GROUP BY statut");
        $compteurs=array_fill_keys(['soumis','publie','rejete','archive'],0);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row)$compteurs[$row['statut']]=(int)$row['total'];
        $published=biblioCatalogueSql($c);
        $params=[$u];
        $where=match($onglet){
            'catalogue'=>"($published)",'favoris'=>"($published) AND fav.utilisateur_id IS NOT NULL",
            'depots'=>'d.depose_par='.$u,
            'validation'=>"d.statut='soumis' AND ($manager)",
            'gestion'=>"($manager)",
            'archives'=>"d.statut='archive' AND (($manager) OR d.depose_par=$u)",
            'corbeille'=>"d.statut='corbeille' AND (($manager) OR d.depose_par=$u)"
        };
        $diffusion=(string)($_GET['diffusion']??'');
        if($diffusion!==''){
            if(!in_array($diffusion,['globale','etablissement'],true))throw new DomainException('Diffusion invalide.');
            $where.=' AND d.visibilite=?';$params[]=$diffusion;
        }
        $etat=(string)($_GET['etat']??'');
        if($etat!==''&&in_array($onglet,['gestion','depots'],true)){
            if(!in_array($etat,['brouillon','soumis','publie','rejete','archive','corbeille'],true))throw new DomainException('État invalide.');
            $where.=' AND d.statut=?';$params[]=$etat;
        }
        $q=trim((string)($_GET['q']??''));
        if($q!==''){
            if(mb_strlen($q)>200)throw new DomainException('Recherche trop longue.');
            $where.=' AND (d.titre LIKE ? OR d.auteur LIKE ? OR d.mots_cles LIKE ?)';
            array_push($params,"%$q%","%$q%","%$q%");
        }
        $cat=(int)($_GET['categorie']??0);
        if($cat){$where.=' AND d.categorie_id=?';$params[]=$cat;}
        $page=max(1,(int)($_GET['page']??1));$offset=($page-1)*12;
        $from=" FROM bibliotheque_documents d JOIN bibliotheque_categories cat ON cat.id=d.categorie_id
            LEFT JOIN bibliotheque_favoris fav ON fav.document_id=d.id AND fav.utilisateur_id=?
            WHERE $where";
        $s=$pdo->prepare('SELECT COUNT(*)'.$from);$s->execute($params);$total=(int)$s->fetchColumn();
        $sort=($_GET['tri']??'recent')==='titre'?'d.titre,d.id':'d.cree_le DESC,d.id DESC';
        $s=$pdo->prepare("SELECT d.id,d.titre,d.auteur,d.resume,d.langue,d.annee,d.visibilite,d.etablissement_id,d.depose_par,
            d.statut,d.taille,d.mime,d.cree_le,d.telechargement_autorise,cat.nom categorie,(fav.utilisateur_id IS NOT NULL) favori,
            (SELECT CONCAT_WS(' ',u.prenom,u.nom) FROM users u WHERE u.id=d.depose_par) deposant,
            (SELECT e.nom FROM etablissements e WHERE e.id=d.etablissement_id) etablissement_nom,
            COALESCE((SELECT SUM(lectures) FROM bibliotheque_consultations bc WHERE bc.document_id=d.id),0) lectures,
            COALESCE((SELECT SUM(telechargements) FROM bibliotheque_consultations bc WHERE bc.document_id=d.id),0) telechargements
            ".$from." ORDER BY $sort LIMIT 12 OFFSET $offset");
        $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($items as &$d){$d['gestion']=biblioGere($c,$d);$d['modifiable']=biblioModifie($c,$d);}unset($d);
        jsonResponse(true,'',['items'=>$items,'total'=>$total,'page'=>$page,'categories'=>$categories,
            'organisations'=>$c['organisations'],'gestion'=>$c['super']||count($managed)>0,'compteurs'=>$compteurs]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(false,'Méthode interdite.',[],405);
    verifyAjaxCsrf();$action=(string)($_POST['action']??'');$notifies=null;
    if($action==='notification_lue'){
        $d=biblioDocument($pdo,(int)($_POST['id']??0));
        if(!biblioLit($c,$d))jsonResponse(false,'Document inaccessible.',[],403);
        $pdo->prepare("UPDATE notifications SET lue_le=COALESCE(lue_le,NOW(6))
          WHERE utilisateur_id=? AND type_evenement='bibliotheque.publication'
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(donnees,'$.document_id')) AS UNSIGNED)=?")
          ->execute([$u,(int)$d['id']]);
        jsonResponse(true,'Lecture enregistrée.');
    }
    if($action==='deposer'){
        if(empty($_POST['droits']))throw new DomainException('Confirmez votre droit de partager ce document.');
        $meta=biblioMetadonnees($_POST,$c);
        $s=$pdo->prepare('SELECT id FROM bibliotheque_categories WHERE id=?');$s->execute([$meta['categorie_id']]);
        if(!$s->fetchColumn())throw new DomainException('Catégorie invalide.');
        $f=biblioFichier($_FILES['fichier']??[]);
        $destination=biblioDossier().'/'.$f['nom_stockage'];
        if(!move_uploaded_file($_FILES['fichier']['tmp_name'],$destination))throw new RuntimeException('Transfert impossible.');
        chmod($destination,0600);
        try{
            $pdo->beginTransaction();
            $values=array_merge($meta,['depose_par'=>$u,'telechargement_autorise'=>($_POST['telechargement_autorise']??'0')==='1'?1:0],$f);
            $columns=implode(',',array_keys($values));$ph=implode(',',array_fill(0,count($values),'?'));
            $pdo->prepare("INSERT INTO bibliotheque_documents ($columns) VALUES($ph)")->execute(array_values($values));
            $id=(int)$pdo->lastInsertId();biblioAudit($pdo,$id,$u,'depot',['droits_confirmes'=>true]);$pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            // Supprime uniquement le fichier aléatoire créé par ce dépôt échoué.
            if(is_file($destination))unlink($destination);
            throw $e;
        }
        jsonResponse(true,'Document enregistré en brouillon. Soumettez-le pour validation.',['id'=>$id]);
    }
    $pdo->beginTransaction();$id=(int)($_POST['id']??0);$d=biblioDocument($pdo,$id,true);
    if(!biblioLit($c,$d)){ $pdo->rollBack();jsonResponse(false,'Document inaccessible.',[],403); }
    if($action!=='favori'&&(int)($_POST['revision']??0)!==(int)$d['revision'])
        throw new DomainException('Le document a changé. Fermez sa fiche et ouvrez-la à nouveau avant de décider.');
    if($action==='telechargement'){
        if(!biblioRegleTelechargement($c,$d))throw new DomainException('Seul le compte déposant peut décider du téléchargement.');
        $allowed=($_POST['autorise']??'0')==='1'?1:0;
        $pdo->prepare('UPDATE bibliotheque_documents SET telechargement_autorise=?,revision=revision+1 WHERE id=?')->execute([$allowed,$id]);
        biblioAudit($pdo,$id,$u,'droit_telechargement',['autorise'=>$allowed]);
    }elseif($action==='favori'){
        if($d['statut']!=='publie')throw new DomainException('Seuls les documents publiés peuvent être favoris.');
        $s=$pdo->prepare('SELECT 1 FROM bibliotheque_favoris WHERE utilisateur_id=? AND document_id=?');$s->execute([$u,$id]);
        if($s->fetchColumn())$pdo->prepare('DELETE FROM bibliotheque_favoris WHERE utilisateur_id=? AND document_id=?')->execute([$u,$id]);
        else $pdo->prepare('INSERT INTO bibliotheque_favoris(utilisateur_id,document_id) VALUES(?,?)')->execute([$u,$id]);
    }elseif($action==='modifier'){
        if(!biblioModifie($c,$d))throw new DomainException('Modification non autorisée.');
        $meta=biblioMetadonnees($_POST,$c);
        if(!$c['super']&&($meta['visibilite']!==$d['visibilite']||$meta['etablissement_id']!==($d['etablissement_id']===null?null:(int)$d['etablissement_id'])))
            throw new DomainException('Le périmètre ne peut pas être changé.');
        $s=$pdo->prepare('SELECT id FROM bibliotheque_categories WHERE id=?');$s->execute([$meta['categorie_id']]);
        if(!$s->fetchColumn())throw new DomainException('Catégorie invalide.');
        $set=implode(',',array_map(fn($key)=>"$key=?",array_keys($meta)));
        $pdo->prepare("UPDATE bibliotheque_documents SET $set,statut='brouillon',publie_le=NULL,motif=NULL,revision=revision+1 WHERE id=?")
            ->execute([...array_values($meta),$id]);
        biblioAudit($pdo,$id,$u,'modification',['avant'=>array_intersect_key($d,$meta),'apres'=>$meta]);
    }else{
        $gere=biblioGere($c,$d);$owner=(int)$d['depose_par']===$u;$state=$d['statut'];
        $target=match($action){
            'soumettre'=>($owner||$gere)&&in_array($state,['brouillon','rejete'],true)?'soumis':null,
            'retirer'=>$owner&&$state==='soumis'?'brouillon':null,
            'publier'=>$gere&&$state==='soumis'?'publie':null,
            'rejeter'=>$gere&&$state==='soumis'?'rejete':null,
            'archiver'=>$gere&&$state==='publie'?'archive':null,
            'supprimer'=>($gere||($owner&&in_array($state,['brouillon','rejete'],true)))&&$state!=='corbeille'?'corbeille':null,
            'restaurer'=>($gere||$owner)&&in_array($state,['archive','corbeille'],true)?'brouillon':null,
            default=>null
        };
        if($target===null)throw new DomainException('Action non autorisée pour cet état.');
        $motif=trim((string)($_POST['motif']??''));
        if($action==='rejeter'&&($motif===''||mb_strlen($motif)>2000))throw new DomainException('Un motif de rejet est requis (2 000 caractères maximum).');
        $pdo->prepare("UPDATE bibliotheque_documents SET statut=?,motif=?,publie_le=CASE WHEN ?='publie' THEN NOW() ELSE NULL END,revision=revision+1 WHERE id=?")
            ->execute([$target,$action==='rejeter'?$motif:null,$target,$id]);
        biblioAudit($pdo,$id,$u,$action,['avant'=>$state,'apres'=>$target,'motif'=>$action==='rejeter'?$motif:null]);
        if($target==='publie')$notifies=biblioNotifierPublication($pdo,biblioDocument($pdo,$id),$u);
    }
    $pdo->commit();jsonResponse(true,'Opération enregistrée.',['notifies'=>$notifies]);
}catch(DomainException $e){
    if($pdo->inTransaction())$pdo->rollBack();jsonResponse(false,$e->getMessage(),[],422);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[BIBLIOTHEQUE] '.$e->getMessage());
    jsonResponse(false,'Bibliothèque indisponible. Vérifiez la migration et le stockage privé dans les journaux serveur.',[],500);
}

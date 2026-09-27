<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

try{
    requirePermission($pdo,'academic.view');

    $eid=(int)($_SESSION['etablissement_id']??0);
    $cfg=$_SESSION['academic_settings']??[];

    if(!$eid)jsonResponse(false,'Aucun établissement académique actif.',[],403);
    if(empty($cfg['promotion_active']))
        jsonResponse(false,'Les promotions sont désactivées pour cet établissement.',[],403);

    $search=trim((string)($_GET['search']??''));
    $yearId=(int)($_GET['annee_academique_id']??0);
    $filiereId=(int)($_GET['filiere_id']??0);
    $status=(string)($_GET['actif']??'');

    $where=['p.etablissement_id=?'];
    $params=[$eid];

    if($search!==''){
        $where[]='(
            p.code LIKE ? OR p.nom LIKE ? OR p.niveau LIKE ?
            OR f.nom LIKE ? OR aa.libelle LIKE ? OR l.code LIKE ? OR l.libelle LIKE ?
        )';
        $like='%'.$search.'%';
        array_push($params,$like,$like,$like,$like,$like,$like,$like);
    }

    if($yearId>0){$where[]='p.annee_academique_id=?';$params[]=$yearId;}
    if($filiereId>0){$where[]='p.filiere_id=?';$params[]=$filiereId;}
    if($status==='0'||$status==='1'){$where[]='p.actif=?';$params[]=(int)$status;}

    $s=$pdo->prepare("
        SELECT
            p.id,p.code,p.nom,p.niveau,p.description,p.actif,
            p.filiere_id,p.option_specialite_id,
            p.annee_academique_id,p.academic_level_id,
            DATE_FORMAT(p.created_at,'%d/%m/%Y') date_creation,
            f.code filiere_code,
            f.nom filiere_nom,
            f.curriculum_reference_id,
            f.preparatory_level_enabled,
            o.nom option_nom,
            aa.libelle annee_libelle,
            aa.date_debut annee_debut,
            aa.date_fin annee_fin,
            l.code niveau_code,
            l.libelle niveau_libelle,
            l.preparatoire,
            l.owner_etablissement_id niveau_owner_etablissement_id,
            c.code cycle_code,
            c.libelle cycle_libelle,
            r.code curriculum_code,
            r.nom curriculum_nom,
            (SELECT COUNT(*) FROM student_academic_enrollments sae WHERE sae.promotion_id=p.id) nb_inscriptions,
            (SELECT COUNT(*) FROM student_academic_enrollments sae WHERE sae.promotion_id=p.id AND sae.statut='EN_COURS') nb_etudiants_en_cours,
            (SELECT COUNT(*) FROM stage_campaign_promotions scp WHERE scp.promotion_id=p.id) nb_campaigns,
            CASE WHEN p.annee_academique_id IS NULL OR p.academic_level_id IS NULL THEN 1 ELSE 0 END legacy_incomplete
        FROM promotions p
        JOIN filieres f ON f.id=p.filiere_id AND f.etablissement_id=p.etablissement_id
        LEFT JOIN options_specialites o ON o.id=p.option_specialite_id AND o.etablissement_id=p.etablissement_id
        LEFT JOIN annees_academiques aa ON aa.id=p.annee_academique_id AND aa.etablissement_id=p.etablissement_id
        LEFT JOIN academic_levels l ON l.id=p.academic_level_id
        LEFT JOIN academic_cycles c ON c.id=l.academic_cycle_id
        LEFT JOIN curriculum_references r ON r.id=f.curriculum_reference_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY COALESCE(aa.date_debut,'1900-01-01') DESC,f.nom,c.ordre,l.ordre,p.nom
    ");
    $s->execute($params);$items=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("SELECT id,code,nom,curriculum_reference_id,preparatory_level_enabled FROM filieres WHERE etablissement_id=? AND actif=1 AND validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL') AND curriculum_reference_id IS NOT NULL ORDER BY nom");
    $s->execute([$eid]);$filieres=$s->fetchAll(PDO::FETCH_ASSOC);

    $s=$pdo->prepare("SELECT id,libelle,date_debut,date_fin,actif FROM annees_academiques WHERE etablissement_id=? ORDER BY COALESCE(date_debut,'1900-01-01') DESC,libelle DESC");
    $s->execute([$eid]);$annees=$s->fetchAll(PDO::FETCH_ASSOC);

    $currentAnnee=null;
    $s=$pdo->prepare("SELECT id,libelle,date_debut,date_fin,actif FROM annees_academiques WHERE etablissement_id=? AND actif=1 AND (CURDATE() BETWEEN date_debut AND date_fin) ORDER BY date_debut DESC LIMIT 1");
    $s->execute([$eid]);
    $currentAnnee=$s->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$currentAnnee){
        $s=$pdo->prepare("SELECT id,libelle,date_debut,date_fin,actif FROM annees_academiques WHERE etablissement_id=? AND actif=1 ORDER BY COALESCE(date_debut,'1900-01-01') DESC,libelle DESC LIMIT 1");
        $s->execute([$eid]);
        $currentAnnee=$s->fetch(PDO::FETCH_ASSOC)?:null;
    }

    $s=$pdo->prepare("SELECT o.id,o.filiere_id,o.code,o.nom FROM options_specialites o JOIN filieres f ON f.id=o.filiere_id AND f.etablissement_id=o.etablissement_id WHERE o.etablissement_id=? AND o.actif=1 AND o.validation_statut IN('NATIONAL','VALIDE_LOCAL','INTEGRE_REFERENTIEL') ORDER BY f.nom,o.nom");
    $s->execute([$eid]);$options=$s->fetchAll(PDO::FETCH_ASSOC);

    $levels=[];
    if($filieres){
        $ids=array_column($filieres,'id');$placeholders=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare("
            SELECT f.id filiere_id,l.id academic_level_id,l.code,l.libelle,l.preparatoire,l.owner_etablissement_id,c.code cycle_code,c.libelle cycle_libelle,c.ordre cycle_ordre,l.ordre niveau_ordre
            FROM filieres f
            JOIN academic_cycles c ON c.curriculum_reference_id=f.curriculum_reference_id AND c.actif=1
            JOIN academic_levels l ON l.academic_cycle_id=c.id AND l.actif=1
            WHERE f.id IN($placeholders) AND f.etablissement_id=?
              AND (l.owner_etablissement_id IS NULL OR l.owner_etablissement_id=?)
              AND (l.preparatoire=0 OR f.preparatory_level_enabled=1)
            ORDER BY f.nom,c.ordre,l.ordre
        ");
        $s->execute([...$ids,$eid,$eid]);$levels=$s->fetchAll(PDO::FETCH_ASSOC);
    }

    $s=$pdo->prepare("SELECT COUNT(*) total,COALESCE(SUM(actif=1),0) actifs,COALESCE(SUM(annee_academique_id IS NULL OR academic_level_id IS NULL),0) legacy FROM promotions WHERE etablissement_id=?");
    $s->execute([$eid]);$k=$s->fetch(PDO::FETCH_ASSOC)?:[];
    $s=$pdo->prepare("SELECT COUNT(DISTINCT sae.enrollment_id) FROM student_academic_enrollments sae JOIN promotions p ON p.id=sae.promotion_id WHERE p.etablissement_id=? AND sae.statut='EN_COURS'");
    $s->execute([$eid]);$students=(int)$s->fetchColumn();

    jsonResponse(true,'',['items'=>$items,'filieres'=>$filieres,'annees'=>$annees,'current_annee'=>$currentAnnee,'options'=>$options,'levels'=>$levels,'rules'=>['option_enabled'=>(bool)($cfg['option_specialite_active']??false),'option_required'=>(bool)($cfg['option_specialite_obligatoire']??false)],'can_manage'=>hasPermission($pdo,'academic.manage'),'kpi'=>['total'=>(int)($k['total']??0),'actifs'=>(int)($k['actifs']??0),'legacy'=>(int)($k['legacy']??0),'students'=>$students]]);
}catch(Throwable $e){error_log('[PROMOTION LIST] '.$e->getMessage());jsonResponse(false,'Erreur Promotions : '.$e->getMessage(),[],500);}

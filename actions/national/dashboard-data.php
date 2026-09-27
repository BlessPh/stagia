<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/admin-scope.php';

if(!hasPermission($pdo,'analytics.overview.view'))
    jsonResponse(false,'Accès analytique refusé.',[],403);

function aRows(PDO $pdo,string $sql,array $params=[]):array{
    $s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function aOne(PDO $pdo,string $sql,array $params=[]):int{
    $s=$pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();
}

/*
 * Ministère = domaine SANTE.
 * Ordre des Médecins = MEDICINE uniquement.
 * Super Admin / rôle analytique personnalisé = ALL.
 *
 * Les inscriptions dont la filière n'a pas encore de référentiel
 * de cursus sont comptées séparément comme "non classées" et ne sont
 * jamais injectées silencieusement dans un domaine réglementé.
 */
$estMinisteriel=estResponsableMinisteriel($pdo);
$mode=hasRole('ORDRE_MEDECINS')&&!hasRole('SUPER_ADMIN')&&!$estMinisteriel
    ?'MEDICINE'
    :($estMinisteriel?'SANTE':'ALL');

$year=trim($_GET['year']??'');
$etabId=(int)($_GET['etablissement_id']??0);
$faculteId=(int)($_GET['faculte_id']??0);
$departementId=(int)($_GET['departement_id']??0);
$promotionId=(int)($_GET['promotion_id']??0);
$province=trim($_GET['province']??'');

try{
    $years=aRows($pdo,"SELECT DISTINCT libelle FROM annees_academiques ORDER BY libelle DESC");
    $perimetreIds=etablissementsVisiblesAdministration($pdo);
    $restreint=($estMinisteriel||hasRole(['ADMIN_ETABLISSEMENT','ADMIN_ACCUEIL']))&&!hasRole('SUPER_ADMIN');
    if($restreint&&$etabId&&!in_array($etabId,$perimetreIds,true))jsonResponse(false,'Établissement hors de votre périmètre.',[],403);
    $scopeSql='';$scopeParams=[];
    if($restreint){
        if(!$perimetreIds)jsonResponse(true,'',['mode'=>$mode,'filters'=>['year'=>$year,'etablissement_id'=>null,'years'=>$years,'establishments'=>[],'faculties'=>[],'departments'=>[],'promotions'=>[],'provinces'=>[]],'kpis'=>['students'=>0,'academic_establishments'=>0,'host_establishments'=>0,'covered_years'=>0,'unclassified_students'=>0],'by_establishment'=>[],'by_year'=>[],'establishment_types'=>[],'by_province'=>[],'by_faculty'=>[],'by_department'=>[],'by_promotion'=>[]]);
        $scopeSql=' AND id IN('.implode(',',array_fill(0,count($perimetreIds),'?')).')';$scopeParams=$perimetreIds;
    }
    $establishments=aRows($pdo,"SELECT id,code,nom,type_etablissement FROM etablissements WHERE statut IN('VALIDE','ACTIF') $scopeSql ORDER BY nom",$scopeParams);
    $estabScopeIds=array_map('intval',array_column($establishments,'id'));
    $academicScope=$estabScopeIds?' AND etablissement_id IN('.implode(',',array_fill(0,count($estabScopeIds),'?')).')':' AND 1=0';
    $faculties=aRows($pdo,"SELECT id,nom,etablissement_id FROM facultes WHERE actif=1 $academicScope ORDER BY nom",$estabScopeIds);
    $departments=aRows($pdo,"SELECT id,nom,etablissement_id FROM departements WHERE actif=1 $academicScope ORDER BY nom",$estabScopeIds);
    $promotions=aRows($pdo,"SELECT id,nom,etablissement_id FROM promotions WHERE actif=1 $academicScope ORDER BY nom",$estabScopeIds);
    $provinces=aRows($pdo,"SELECT DISTINCT province FROM etablissements WHERE statut IN('VALIDE','ACTIF') AND province IS NOT NULL AND province<>'' $scopeSql ORDER BY province",$scopeParams);

    $studentFrom="
        FROM student_enrollments se
        JOIN student_academic_enrollments sae ON sae.enrollment_id=se.id
        JOIN annees_academiques aa ON aa.id=sae.annee_academique_id
        JOIN promotions pr ON pr.id=sae.promotion_id
        JOIN filieres f ON f.id=pr.filiere_id
        LEFT JOIN curriculum_references cr ON cr.id=f.curriculum_reference_id
        JOIN etablissements e ON e.id=se.etablissement_id
        WHERE se.statut<>'ARCHIVE'
    ";
    $where=[];$params=[];

    if($restreint){$where[]='e.id IN('.implode(',',array_fill(0,count($perimetreIds),'?')).')';array_push($params,...$perimetreIds);}

    if($mode==='SANTE'){
        $where[]="cr.domaine='SANTE'";
    }elseif($mode==='MEDICINE'){
        $where[]="cr.code='MEDICINE'";
    }
    if($year!==''){
        $where[]='aa.libelle=?';$params[]=$year;
    }
    if($etabId>0){
        $where[]='e.id=?';$params[]=$etabId;
    }
    if($faculteId>0){$where[]='f.faculte_id=?';$params[]=$faculteId;}
    if($departementId>0){$where[]='f.departement_id=?';$params[]=$departementId;}
    if($promotionId>0){$where[]='pr.id=?';$params[]=$promotionId;}
    if($province!==''){$where[]='e.province=?';$params[]=$province;}

    $studentWhere=$studentFrom.($where?' AND '.implode(' AND ',$where):'');

    $students=aOne($pdo,"SELECT COUNT(DISTINCT se.student_id) ".$studentWhere,$params);
    $academicEstablishments=aOne($pdo,"SELECT COUNT(DISTINCT e.id) ".$studentWhere,$params);
    $coveredYears=aOne($pdo,"SELECT COUNT(DISTINCT aa.libelle) ".$studentWhere,$params);

    $hostSql="SELECT COUNT(*) FROM etablissements
        WHERE statut IN('VALIDE','ACTIF') AND type_etablissement='HOPITAL'";
    $hostParams=[];
    if($restreint){$hostSql.=' AND id IN('.implode(',',array_fill(0,count($perimetreIds),'?')).')';$hostParams=$perimetreIds;}
    if($etabId>0){
        $hostSql.=" AND id=?";$hostParams[]=$etabId;
    }
    $hosts=aOne($pdo,$hostSql,$hostParams);

    $byEstablishment=aRows($pdo,"
        SELECT e.id,e.code,e.nom,e.type_etablissement,aa.libelle annee,
               COUNT(DISTINCT se.student_id) total
        ".$studentWhere."
        GROUP BY e.id,e.code,e.nom,e.type_etablissement,aa.libelle
        ORDER BY aa.libelle DESC,total DESC,e.nom
    ",$params);

    $byYear=aRows($pdo,"
        SELECT aa.libelle label,COUNT(DISTINCT se.student_id) total,
               COUNT(DISTINCT e.id) etablissements
        ".$studentWhere."
        GROUP BY aa.libelle
        ORDER BY aa.libelle DESC
    ",$params);

    $establishmentTypes=aRows($pdo,"SELECT COALESCE((SELECT t.libelle FROM establishment_types t WHERE t.code=e.type_etablissement),e.type_etablissement) label,COUNT(DISTINCT se.student_id) total ".$studentWhere." GROUP BY e.type_etablissement ORDER BY total DESC,label",$params);

    $provinceRows=aRows($pdo,"
        SELECT COALESCE(NULLIF(TRIM(e.province),''),'Non renseignée') label,
               COUNT(DISTINCT e.id) etablissements,
               COUNT(DISTINCT se.student_id) etudiants
        ".$studentWhere."
        GROUP BY COALESCE(NULLIF(TRIM(e.province),''),'Non renseignée')
        ORDER BY etudiants DESC,etablissements DESC,label
    ",$params);

    $byFaculty=aRows($pdo,"SELECT COALESCE((SELECT fa.nom FROM facultes fa WHERE fa.id=f.faculte_id),'Sans faculté') label,COUNT(DISTINCT se.student_id) total ".$studentWhere." GROUP BY f.faculte_id ORDER BY total DESC,label",$params);
    $byDepartment=aRows($pdo,"SELECT COALESCE((SELECT d.nom FROM departements d WHERE d.id=f.departement_id),'Sans département') label,COUNT(DISTINCT se.student_id) total ".$studentWhere." GROUP BY f.departement_id ORDER BY total DESC,label",$params);
    $byPromotion=aRows($pdo,"SELECT pr.nom label,COUNT(DISTINCT se.student_id) total ".$studentWhere." GROUP BY pr.id,pr.nom ORDER BY total DESC,label",$params);

    $unclassified=aOne($pdo,"SELECT COUNT(DISTINCT se.student_id) ".$studentWhere." AND f.curriculum_reference_id IS NULL",$params);

    jsonResponse(true,'',[
        'mode'=>$mode,
        'filters'=>[
            'year'=>$year,
            'etablissement_id'=>$etabId?:null,
            'faculte_id'=>$faculteId?:null,'departement_id'=>$departementId?:null,'promotion_id'=>$promotionId?:null,'province'=>$province,
            'years'=>$years,
            'establishments'=>$establishments,'faculties'=>$faculties,'departments'=>$departments,'promotions'=>$promotions,'provinces'=>$provinces
        ],
        'kpis'=>[
            'students'=>$students,
            'academic_establishments'=>$academicEstablishments,
            'host_establishments'=>$hosts,
            'covered_years'=>$coveredYears,
            'unclassified_students'=>$unclassified
        ],
        'by_establishment'=>$byEstablishment,
        'by_year'=>$byYear,
        'establishment_types'=>$establishmentTypes,
        'by_province'=>$provinceRows
        ,'by_faculty'=>$byFaculty,'by_department'=>$byDepartment,'by_promotion'=>$byPromotion
    ]);
}catch(Throwable $e){
    error_log('[NATIONAL ANALYTICS] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Impossible de charger les statistiques pour le moment.',[],500);
}

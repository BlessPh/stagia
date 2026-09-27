<?php
/**
 * Endpoint AJAX de liste des conventions visibles dans le contexte académique ou d'accueil.
 * Il fournit aussi les placements éligibles à la création d'une convention.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/stage-convention.php';

try{
    /* Le helper central détermine le rôle, le périmètre SQL et les permissions de convention. */
    requireConventionAjax();
    [$scope,$scopeParams]=conventionScope($pdo,'c','pl');

    /* Les conventions sont enrichies des données de placement, campagne et stagiaire. */
    $sql="
        SELECT
            sc.id,sc.uuid,sc.reference,sc.titre,sc.version,sc.statut,
            sc.date_emission,sc.date_signature_etudiant,
            sc.date_signature_universite,sc.date_signature_accueil,
            sc.document_id,sc.submitted_at,sc.signed_at,sc.archived_at,
            sc.created_at,
            pl.id placement_id,pl.date_debut,pl.date_fin,
            sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
            c.code campaign_code,c.titre campaign_title,
            st.libelle stage_type_label,
            uni.nom university_name,host.nom host_name
        FROM stage_conventions sc
        INNER JOIN stage_placements pl ON pl.id=sc.placement_id
        INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
        INNER JOIN stage_types st ON st.id=c.stage_type_id
        INNER JOIN student_profiles sp ON sp.id=pl.student_id
        INNER JOIN etablissements uni ON uni.id=c.owner_etablissement_id
        INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
        WHERE $scope
        ORDER BY sc.created_at DESC,sc.id DESC
    ";
    $s=$pdo->prepare($sql);$s->execute($scopeParams);
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    /* Conversion des identifiants et calcul des actions disponibles pour chaque ligne. */
    foreach($items as &$x){
        foreach(['id','version','placement_id'] as $k)$x[$k]=(int)$x[$k];
        $x['document_id']=$x['document_id']!==null?(int)$x['document_id']:null;
        $x['student_name']=conventionStudentName($x);
        $x['can_edit']=$x['statut']==='BROUILLON' &&
            contextAcademicEnabled() && conventionCanAcademicManage();
        $x['can_university_sign']=$x['statut']==='A_SIGNER' &&
            empty($x['date_signature_universite']) &&
            contextAcademicEnabled() && conventionCanAcademicManage();
        $x['can_host_sign']=$x['statut']==='A_SIGNER' &&
            empty($x['date_signature_accueil']) &&
            contextHostEnabled() && conventionCanHostManage();
        $x['can_record_student']=$x['statut']==='A_SIGNER' &&
            empty($x['date_signature_etudiant']) &&
            (conventionCanAcademicManage()||conventionCanHostManage());
        $x['ready_upload']=$x['statut']==='A_SIGNER' &&
            !empty($x['date_signature_etudiant']) &&
            !empty($x['date_signature_universite']) &&
            !empty($x['date_signature_accueil']) &&
            !$x['document_id'];
    }unset($x);

    /* Seul l'établissement de formation peut créer une convention depuis un placement éligible. */
    $placements=[];
    if(conventionCanAcademicManage()){
        $eid=conventionEtablissementId();
        $s=$pdo->prepare("
            SELECT
                pl.id,pl.date_debut,pl.date_fin,
                sp.stagia_code,sp.nom,sp.postnom,sp.prenom,
                c.code campaign_code,c.titre campaign_title,
                host.nom host_name
            FROM stage_placements pl
            INNER JOIN stage_campaigns c ON c.id=pl.campaign_id
            INNER JOIN student_profiles sp ON sp.id=pl.student_id
            INNER JOIN etablissements host ON host.id=pl.host_etablissement_id
            WHERE c.owner_etablissement_id=?
              AND pl.statut IN('CONFIRME','TERMINE')
              AND NOT EXISTS(
                  SELECT 1 FROM stage_conventions sc
                  WHERE sc.placement_id=pl.id
                    AND sc.statut IN('BROUILLON','A_SIGNER','SIGNEE')
              )
            ORDER BY sp.nom,sp.postnom,sp.prenom,c.date_debut DESC
        ");
        $s->execute([$eid]);
        $placements=$s->fetchAll(PDO::FETCH_ASSOC);
        foreach($placements as &$p){
            $p['id']=(int)$p['id'];
            $p['student_name']=conventionStudentName($p);
        }unset($p);
    }

    /* Agrégation des indicateurs d'état affichés dans l'interface de gestion. */
    $k=['total'=>0,'brouillons'=>0,'a_signer'=>0,'signees'=>0,'archivees'=>0];
    foreach($items as $x){
        $k['total']++;
        if($x['statut']==='BROUILLON')$k['brouillons']++;
        elseif($x['statut']==='A_SIGNER')$k['a_signer']++;
        elseif($x['statut']==='SIGNEE')$k['signees']++;
        elseif($x['statut']==='ARCHIVEE')$k['archivees']++;
    }

    jsonResponse(true,'',[
        'items'=>$items,
        'placements'=>$placements,
        'kpi'=>$k,
        'permissions'=>[
            'create'=>conventionCanAcademicManage(),
            'academic_manage'=>conventionCanAcademicManage(),
            'host_manage'=>conventionCanHostManage()
        ]
    ]);
}catch(Throwable $e){
    jsonResponse(false,'Erreur conventions : '.$e->getMessage(),[],403);
}

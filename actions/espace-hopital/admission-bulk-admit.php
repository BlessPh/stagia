<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

requireAjaxRole(['ADMIN_ACCUEIL']);
verifyAjaxCsrf();

try{
    $hostId=currentEtablissementId($pdo);
    $userId=(int)($_SESSION['user_id']??0);
    $mode=(string)($_POST['mode']??'selected');

    if(!$hostId)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    if(!in_array($mode,['selected','filtered'],true))
        jsonResponse(false,'Mode d’admission invalide.',[],422);

    $where=[
        'ad.host_etablissement_id=?',
        "ad.statut='ATTENDU'",
        "sr.statut='CONFIRMEE'",
        "app.statut='ACCEPTEE'",
        "pl.statut='CONFIRME'",
        'app.host_etablissement_id=?'
    ];
    $params=[$hostId,$hostId];

    if($mode==='selected'){
        $ids=array_values(array_unique(array_filter(
            array_map('intval',$_POST['admission_ids']??[])
        )));

        if(!$ids)
            jsonResponse(false,'Aucun stagiaire sélectionné.',[],422);

        if(count($ids)>2000)
            jsonResponse(false,'Maximum 2000 admissions par opération.',[],422);

        $where[]='ad.id IN('.implode(',',array_fill(0,count($ids),'?')).')';
        $params=array_merge($params,$ids);

    }else{
        $campaignId=(int)($_POST['campaign_id']??0);
        $universityId=(int)($_POST['university_id']??0);
        $promotionId=(int)($_POST['promotion_id']??0);
        $q=trim((string)($_POST['q']??''));

        if($campaignId){
            $where[]='app.campaign_id=?';
            $params[]=$campaignId;
        }

        if($universityId){
            $where[]='c.owner_etablissement_id=?';
            $params[]=$universityId;
        }

        if($promotionId){
            $where[]='ae.promotion_id=?';
            $params[]=$promotionId;
        }

        if($q!==''){
            $where[]="(
                sp.nom LIKE ?
                OR sp.postnom LIKE ?
                OR sp.prenom LIKE ?
                OR sp.stagia_code LIKE ?
                OR c.code LIKE ?
                OR c.titre LIKE ?
                OR u.nom LIKE ?
            )";

            $like='%'.$q.'%';
            for($i=0;$i<7;$i++)$params[]=$like;
        }
    }

    $base="
        FROM stage_admissions ad
        JOIN stage_reservations sr ON sr.id=ad.reservation_id
        JOIN stage_applications app ON app.id=sr.application_id
        JOIN stage_placements pl ON pl.id=ad.placement_id
            AND pl.reservation_id=sr.id
            AND pl.host_etablissement_id=ad.host_etablissement_id
        JOIN stage_campaigns c ON c.id=app.campaign_id
        JOIN etablissements u ON u.id=c.owner_etablissement_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
    ";

    $stmt=$pdo->prepare(
        "SELECT ad.id ".$base." WHERE ".implode(' AND ',$where)." ORDER BY ad.id"
    );
    $stmt->execute($params);
    $ids=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));

    if(!$ids)
        jsonResponse(false,'Aucun stagiaire ATTENDU ne correspond à cette sélection.',[],422);

    /*
     * Mise à jour en une seule requête :
     * beaucoup plus efficace qu'un UPDATE par étudiant.
     */
    $placeholders=implode(',',array_fill(0,count($ids),'?'));

    $sql="
        UPDATE stage_admissions
        SET
            statut='ADMIS',
            admitted_at=COALESCE(admitted_at,NOW()),
            admitted_by=COALESCE(admitted_by,?)
        WHERE host_etablissement_id=?
          AND statut='ATTENDU'
          AND id IN($placeholders)
    ";

    $updateParams=array_merge([$userId,$hostId],$ids);

    $stmt=$pdo->prepare($sql);
    $stmt->execute($updateParams);

    $updated=$stmt->rowCount();

    jsonResponse(
        true,
        $updated.' stagiaire(s) admis avec succès.',
        [
            'requested'=>count($ids),
            'updated'=>$updated
        ]
    );

}catch(Throwable $e){
    error_log(
        '[ADMISSION BULK ADMIT] '.$e->getMessage().
        ' | '.$e->getFile().':'.$e->getLine()
    );

    jsonResponse(
        false,
        'Erreur admission en masse : '.$e->getMessage(),
        [],
        500
    );
}

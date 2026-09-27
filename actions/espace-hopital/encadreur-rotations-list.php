<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

if(file_exists(__DIR__.'/../../includes/stage-execution.php'))
    require_once __DIR__.'/../../includes/stage-execution.php';

if(function_exists('requireAjaxRole'))requireAjaxRole(['ENCADREUR','EVALUATEUR_CLINIQUE']);
else requireRole(['ENCADREUR','EVALUATEUR_CLINIQUE']);

function encStatusMap(string $v):string{
    $v=strtoupper(trim($v));
    return [
        ''=>'A_SUIVRE',
        'A_SUIVRE'=>'A_SUIVRE',
        'SUIVI'=>'A_SUIVRE',
        'ACTIVE'=>'ACTIVE',
        'ACTIF'=>'ACTIVE',
        'ACTIFS'=>'ACTIVE',
        'PLANIFIEE'=>'PLANIFIEE',
        'PLANIFIÉE'=>'PLANIFIEE',
        'PLANIFIES'=>'PLANIFIEE',
        'PLANIFIÉS'=>'PLANIFIEE',
        'TERMINEE'=>'TERMINEE',
        'TERMINÉE'=>'TERMINEE',
        'TERMINES'=>'TERMINEE',
        'TERMINÉS'=>'TERMINEE'
    ][$v]??'A_SUIVRE';
}

function encCount(PDO $pdo,int $eid,int $uid,?string $statut=null):int{
    $w=["r.host_etablissement_id=?","r.statut<>'ANNULEE'","rs.user_id=?","rs.actif=1"];
    $p=[$eid,$uid];

    if($statut){
        $w[]='r.statut=?';
        $p[]=$statut;
    }

    $s=$pdo->prepare("
        SELECT COUNT(DISTINCT r.id)
        FROM stage_rotations r
        JOIN stage_rotation_supervisors rs ON rs.rotation_id=r.id
        WHERE ".implode(' AND ',$w)
    );
    $s->execute($p);

    return (int)$s->fetchColumn();
}

try{
    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);

    if(!$eid)jsonResponse(false,'Aucun établissement associé.',[],403);
    if(!$uid)jsonResponse(false,'Session utilisateur invalide.',[],401);

    if(function_exists('syncStageExecution')){
        try{syncStageExecution($pdo,['host_etablissement_id'=>$eid]);}
        catch(Throwable $e){error_log('[ENCADREUR ROTATIONS SYNC] '.$e->getMessage());}
    }

    $status=encStatusMap((string)($_GET['status']??$_GET['statut']??'A_SUIVRE'));
    $q=trim((string)($_GET['q']??$_GET['search']??''));

    $where=[
        "r.host_etablissement_id=?",
        "r.statut<>'ANNULEE'",
        "rs.user_id=?",
        "rs.actif=1"
    ];
    $params=[$eid,$uid];

    if($status==='A_SUIVRE'){
        $where[]="r.statut IN('ACTIVE','PLANIFIEE')";
    }else{
        $where[]='r.statut=?';
        $params[]=$status;
    }

    if($q!==''){
        $where[]="(
            sp.nom LIKE ? OR sp.postnom LIKE ? OR sp.prenom LIKE ?
            OR sp.stagia_code LIKE ? OR se.matricule LIKE ?
            OR c.code LIKE ? OR c.titre LIKE ?
            OR univ.nom LIKE ? OR hu.nom LIKE ?
        )";
        $like='%'.$q.'%';
        array_push($params,$like,$like,$like,$like,$like,$like,$like,$like,$like);
    }

    $sql="
        SELECT
            r.id rotation_id,
            r.assignment_id,
            r.sequence_no,
            r.statut,
            r.date_debut,
            r.date_fin,
            r.objectifs,
            r.observation,

            a.admission_id,
            a.host_unit_id,

            COALESCE(se.matricule,sp.stagia_code,CONCAT('ROT-',r.id)) matricule,
            sp.stagia_code,

            COALESCE(NULLIF(TRIM(CONCAT_WS(' ',sp.nom,sp.postnom,sp.prenom)),''),CONCAT('Stagiaire #',r.assignment_id)) stagiaire,
            COALESCE(univ.nom,'—') universite,

            COALESCE(c.code,'—') campaign_code,
            COALESCE(c.titre,'—') campaign_title,

            COALESCE(hu.nom,CONCAT('Service #',r.host_unit_id)) service,
            COALESCE(hu.code,'') service_code,
            COALESCE(hu.type,'') service_type,

            rs.role_supervision,
            rs.principal

        FROM stage_rotations r
        JOIN stage_rotation_supervisors rs
          ON rs.rotation_id=r.id
         AND rs.user_id=?
         AND rs.actif=1

        JOIN stage_assignments a
          ON a.id=r.assignment_id
         AND a.host_etablissement_id=r.host_etablissement_id

        LEFT JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN stage_reservations sr ON sr.id=ad.reservation_id
        LEFT JOIN stage_applications app ON app.id=sr.application_id
        LEFT JOIN stage_campaigns c ON c.id=app.campaign_id
        LEFT JOIN etablissements univ ON univ.id=c.owner_etablissement_id
        LEFT JOIN student_academic_enrollments ae ON ae.id=app.academic_enrollment_id
        LEFT JOIN student_enrollments se ON se.id=ae.enrollment_id
        LEFT JOIN student_profiles sp ON sp.id=se.student_id
        LEFT JOIN host_units hu ON hu.id=r.host_unit_id

        WHERE ".implode(' AND ',$where)."

        ORDER BY
            CASE r.statut
                WHEN 'ACTIVE' THEN 1
                WHEN 'PLANIFIEE' THEN 2
                WHEN 'TERMINEE' THEN 3
                ELSE 9
            END,
            r.date_debut ASC,
            r.sequence_no ASC,
            r.id DESC
        LIMIT 300
    ";

    $s=$pdo->prepare($sql);
    $s->execute(array_merge([$uid],$params));
    $items=$s->fetchAll(PDO::FETCH_ASSOC);

    foreach($items as &$x){
        $x['rotation_id']=(int)$x['rotation_id'];
        $x['assignment_id']=(int)$x['assignment_id'];
        $x['sequence_no']=(int)$x['sequence_no'];
        $x['principal']=(int)$x['principal'];
    }
    unset($x);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>[
            'total'=>encCount($pdo,$eid,$uid),
            'actives'=>encCount($pdo,$eid,$uid,'ACTIVE'),
            'planifiees'=>encCount($pdo,$eid,$uid,'PLANIFIEE'),
            'terminees'=>encCount($pdo,$eid,$uid,'TERMINEE'),
            'a_suivre'=>encCount($pdo,$eid,$uid,'ACTIVE')+encCount($pdo,$eid,$uid,'PLANIFIEE')
        ]
    ]);

}catch(Throwable $e){
    error_log('[ENCADREUR ROTATIONS LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],500);
}

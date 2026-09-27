<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

$role=$_SESSION['role_code']??'';

if($role==='CHEF_SERVICE')requireRole(['CHEF_SERVICE']);
else requirePermission($pdo,'rotation.hosting.manage');

verifyAjaxCsrf();

$eid=(int)currentEtablissementId($pdo);
$uid=(int)($_SESSION['user_id']??0);
$groupId=(int)($_POST['group_id']??0);

if(!$eid||!$groupId)jsonResponse(false,'Groupe invalide.',[],422);
if(!contextHostEnabled())jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

function rotationUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

function normalizeUnitType(string $t):string{
    $t=strtoupper(trim($t));
    return str_replace(['É','È','Ê','Ë','é','è','ê','ë'],'E',$t);
}

function chefUnitScope(PDO $pdo,int $uid,int $eid,string $role):?array{
    if($role!=='CHEF_SERVICE')return null;

    $ids=[];$all=false;

    $s=$pdo->prepare("
        SELECT ra.scope_type,ra.scope_id
        FROM role_assignments ra
        JOIN roles r ON r.id=ra.role_id
        WHERE ra.user_id=?
          AND ra.etablissement_id=?
          AND r.code='CHEF_SERVICE'
          AND ra.actif=1
          AND ra.revoked_at IS NULL
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
    ");
    $s->execute([$uid,$eid]);

    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){
        if($r['scope_type']==='ORGANIZATION')$all=true;
        if($r['scope_type']==='UNIT'&&(int)$r['scope_id']>0)$ids[]=(int)$r['scope_id'];
    }

    if($all)return null;
    if(!$ids)return [-1];

    $allIds=$ids;

    for($i=0;$i<4;$i++){
        $in=implode(',',array_fill(0,count($allIds),'?'));
        $s=$pdo->prepare("
            SELECT id
            FROM host_units
            WHERE host_etablissement_id=?
              AND actif=1
              AND parent_id IN($in)
        ");
        $s->execute(array_merge([$eid],$allIds));
        $new=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));

        $before=count($allIds);
        $allIds=array_values(array_unique(array_merge($allIds,$new)));
        if(count($allIds)===$before)break;
    }

    return $allIds;
}

function addUnitFilter(string $field,?array $unitIds,array &$where,array &$params):void{
    if(!is_array($unitIds))return;
    $where[]=$field.' IN('.implode(',',array_fill(0,count($unitIds),'?')).')';
    $params=array_merge($params,$unitIds);
}

function supervisorAllowed(PDO $pdo,int $userId,int $eid,?array $unitIds):bool{
    $where=[
        'u.id=?',
        'u.actif=1',
        "u.statut_compte='ACTIF'",
        "EXISTS(
            SELECT 1
            FROM role_assignments ra
            JOIN roles r ON r.id=ra.role_id
            WHERE ra.user_id=u.id
              AND ra.etablissement_id=?
              AND ra.actif=1
              AND ra.revoked_at IS NULL
              AND r.code IN('ENCADREUR','EVALUATEUR_CLINIQUE')
              AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
              AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
        )"
    ];

    $params=[$userId,$eid];

    if(is_array($unitIds)){
        $in=implode(',',array_fill(0,count($unitIds),'?'));
        $where[]="EXISTS(
            SELECT 1
            FROM role_assignments ra2
            JOIN roles r2 ON r2.id=ra2.role_id
            WHERE ra2.user_id=u.id
              AND ra2.etablissement_id=?
              AND ra2.actif=1
              AND ra2.revoked_at IS NULL
              AND r2.code IN('ENCADREUR','EVALUATEUR_CLINIQUE')
              AND (
                    ra2.scope_type='ORGANIZATION'
                    OR (ra2.scope_type='UNIT' AND ra2.scope_id IN($in))
              )
        )";
        $params=array_merge($params,[$eid],$unitIds);
    }

    $s=$pdo->prepare('SELECT COUNT(*) FROM users u WHERE '.implode(' AND ',$where));
    $s->execute($params);

    return (int)$s->fetchColumn()>0;
}

try{
    $unitIds=chefUnitScope($pdo,$uid,$eid,$role);

    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT g.id,g.campaign_id,g.promotion_id,g.host_etablissement_id,g.nom,g.statut,
               c.date_debut campaign_start,c.date_fin campaign_end
        FROM stage_groups g
        JOIN stage_campaigns c ON c.id=g.campaign_id
        WHERE g.id=?
          AND g.host_etablissement_id=?
          AND g.statut='BROUILLON'
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$groupId,$eid]);
    $group=$s->fetch(PDO::FETCH_ASSOC);

    if(!$group)throw new RuntimeException('Ce plan est déjà publié ou n’est plus modifiable.');

    $campaignId=(int)$group['campaign_id'];
    $promotionId=(int)$group['promotion_id'];

    $s=$pdo->prepare("
        SELECT rp.id,rp.group_id,rp.sequence_no,rp.host_unit_id,rp.principal_supervisor_user_id,
               rp.date_debut,rp.date_fin,rp.objectifs,rp.observation,rp.statut,
               hu.nom unit_name,hu.type unit_type
        FROM stage_group_rotation_plans rp
        JOIN host_units hu
          ON hu.id=rp.host_unit_id
         AND hu.host_etablissement_id=?
         AND hu.actif=1
        WHERE rp.group_id=?
          AND rp.statut IN('BROUILLON','PLANIFIEE')
        ORDER BY rp.sequence_no,rp.id
        FOR UPDATE
    ");
    $s->execute([$eid,$groupId]);
    $plans=$s->fetchAll(PDO::FETCH_ASSOC);

    if(!$plans)throw new RuntimeException('Ajoutez au moins une rotation avant de publier le plan.');

    foreach($plans as $plan){
        $unitId=(int)$plan['host_unit_id'];

        if(is_array($unitIds)&&!in_array($unitId,$unitIds,true))
            throw new RuntimeException("La rotation {$plan['sequence_no']} n’appartient pas à votre service.");

        $type=normalizeUnitType((string)$plan['unit_type']);

        if(!in_array($type,['SERVICE','UNITE'],true))
            throw new RuntimeException("La rotation {$plan['sequence_no']} utilise « {$plan['unit_name']} » qui est un {$plan['unit_type']}. Choisissez un vrai service.");

        if(empty($plan['principal_supervisor_user_id']))
            throw new RuntimeException("La rotation {$plan['sequence_no']} n’a pas d’encadreur principal.");

        if(!supervisorAllowed($pdo,(int)$plan['principal_supervisor_user_id'],$eid,$unitIds))
            throw new RuntimeException("L’encadreur de la rotation {$plan['sequence_no']} n’est pas autorisé dans votre périmètre.");
    }

    $whereStudents=[
        'gs.group_id=?',
        'gs.campaign_id=?',
        'ae.promotion_id=?',
        'ad.host_etablissement_id=?',
        "ad.statut IN('ADMIS','EN_COURS')",
        'sa.host_etablissement_id=?',
        "sa.statut IN('PLANIFIEE','ACTIVE')"
    ];

    $paramsStudents=[$groupId,$campaignId,$promotionId,$eid,$eid];

    addUnitFilter('sa.host_unit_id',$unitIds,$whereStudents,$paramsStudents);

    $s=$pdo->prepare("
        SELECT DISTINCT
            gs.academic_enrollment_id,
            ad.id admission_id,
            sa.id assignment_id,
            sa.date_debut assignment_start,
            sa.date_fin assignment_end,
            sp.stagia_code,
            CONCAT_WS(' ',sp.prenom,sp.nom,sp.postnom) student_name
        FROM stage_group_students gs
        JOIN student_academic_enrollments ae
          ON ae.id=gs.academic_enrollment_id
        JOIN student_enrollments se
          ON se.id=ae.enrollment_id
        JOIN student_profiles sp
          ON sp.id=se.student_id
        JOIN stage_applications app
          ON app.academic_enrollment_id=ae.id
         AND app.campaign_id=gs.campaign_id
         AND app.host_etablissement_id=?
        JOIN stage_reservations sr
          ON sr.application_id=app.id
         AND sr.statut='CONFIRMEE'
        JOIN stage_admissions ad
          ON ad.reservation_id=sr.id
        JOIN stage_assignments sa
          ON sa.admission_id=ad.id
        WHERE ".implode(' AND ',$whereStudents)."
        ORDER BY sp.nom,sp.prenom,sa.id DESC
        FOR UPDATE
    ");
    $s->execute(array_merge([$eid],$paramsStudents));
    $rawStudents=$s->fetchAll(PDO::FETCH_ASSOC);

    $students=[];$seen=[];

    foreach($rawStudents as $student){
        $academicId=(int)$student['academic_enrollment_id'];
        if(isset($seen[$academicId]))continue;
        $seen[$academicId]=true;
        $students[]=$student;
    }

    if(!$students)throw new RuntimeException('Le groupe ne contient aucun stagiaire admis et affecté dans votre périmètre.');

    foreach($students as $student){
        foreach($plans as $plan){
            if(!empty($student['assignment_start'])&&$plan['date_debut']<$student['assignment_start'])
                throw new RuntimeException("La rotation {$plan['sequence_no']} commence avant l’affectation de {$student['student_name']}.");

            if(!empty($student['assignment_end'])&&$plan['date_fin']>$student['assignment_end'])
                throw new RuntimeException("La rotation {$plan['sequence_no']} dépasse l’affectation de {$student['student_name']}.");

            $s=$pdo->prepare("
                SELECT id,statut,host_unit_id,group_rotation_plan_id
                FROM stage_rotations
                WHERE assignment_id=?
                  AND sequence_no=?
                LIMIT 1
                FOR UPDATE
            ");
            $s->execute([(int)$student['assignment_id'],(int)$plan['sequence_no']]);
            $existing=$s->fetch(PDO::FETCH_ASSOC);

            if($existing&&(int)($existing['group_rotation_plan_id']??0)!==(int)$plan['id']&&$existing['statut']!=='PLANIFIEE')
                throw new RuntimeException("Conflit pour {$student['student_name']} : la séquence {$plan['sequence_no']} existe déjà et n'est plus modifiable.");
        }
    }

    $actor=$uid?:null;
    $created=0;$updated=0;

    $findRotation=$pdo->prepare("
        SELECT id,statut,group_rotation_plan_id
        FROM stage_rotations
        WHERE assignment_id=?
          AND sequence_no=?
        LIMIT 1
        FOR UPDATE
    ");

    $updateRotation=$pdo->prepare("
        UPDATE stage_rotations
        SET group_id=?,
            group_rotation_plan_id=?,
            host_unit_id=?,
            host_etablissement_id=?,
            date_debut=?,
            date_fin=?,
            objectifs=?,
            observation=?,
            created_by=?,
            updated_at=NOW()
        WHERE id=?
    ");

    $insertRotation=$pdo->prepare("
        INSERT INTO stage_rotations(
            uuid,assignment_id,group_id,group_rotation_plan_id,host_unit_id,
            host_etablissement_id,sequence_no,date_debut,date_fin,statut,
            objectifs,observation,created_by
        )
        VALUES(?,?,?,?,?,?,?,?,?,'PLANIFIEE',?,?,?)
    ");

    $saveSupervisor=$pdo->prepare("
        INSERT INTO stage_rotation_supervisors(
            rotation_id,user_id,role_supervision,principal,assigned_by,assigned_at,actif
        )
        VALUES(?,?,'ENCADREUR',1,?,NOW(),1)
        ON DUPLICATE KEY UPDATE
            principal=1,
            assigned_by=VALUES(assigned_by),
            assigned_at=VALUES(assigned_at),
            actif=1
    ");

    foreach($students as $student){
        foreach($plans as $plan){
            $findRotation->execute([(int)$student['assignment_id'],(int)$plan['sequence_no']]);
            $existing=$findRotation->fetch(PDO::FETCH_ASSOC);

            if($existing){
                if($existing['statut']!=='PLANIFIEE')continue;

                $updateRotation->execute([
                    $groupId,
                    (int)$plan['id'],
                    (int)$plan['host_unit_id'],
                    $eid,
                    $plan['date_debut'],
                    $plan['date_fin'],
                    $plan['objectifs'],
                    $plan['observation'],
                    $actor,
                    (int)$existing['id']
                ]);

                $rotationId=(int)$existing['id'];
                $updated++;
            }else{
                $insertRotation->execute([
                    rotationUuid(),
                    (int)$student['assignment_id'],
                    $groupId,
                    (int)$plan['id'],
                    (int)$plan['host_unit_id'],
                    $eid,
                    (int)$plan['sequence_no'],
                    $plan['date_debut'],
                    $plan['date_fin'],
                    $plan['objectifs'],
                    $plan['observation'],
                    $actor
                ]);

                $rotationId=(int)$pdo->lastInsertId();
                $created++;
            }

            $saveSupervisor->execute([
                $rotationId,
                (int)$plan['principal_supervisor_user_id'],
                $actor
            ]);
        }
    }

    $pdo->prepare("
        UPDATE stage_group_rotation_plans
        SET statut='PLANIFIEE'
        WHERE group_id=?
          AND statut IN('BROUILLON','PLANIFIEE')
    ")->execute([$groupId]);

    $pdo->prepare("
        UPDATE stage_groups
        SET statut='ACTIF'
        WHERE id=?
          AND host_etablissement_id=?
          AND statut='BROUILLON'
    ")->execute([$groupId,$eid]);

    $pdo->prepare("
        INSERT INTO stage_group_history(group_id,event_code,details,actor_user_id,created_at)
        VALUES(?,?,?,?,NOW())
    ")->execute([
        $groupId,
        'ROTATIONS_PUBLISHED',
        json_encode([
            'campaign_id'=>$campaignId,
            'promotion_id'=>$promotionId,
            'students'=>count($students),
            'plans'=>count($plans),
            'rotations_created'=>$created,
            'rotations_updated'=>$updated,
            'host_etablissement_id'=>$eid,
            'chef_service'=>($role==='CHEF_SERVICE')
        ],JSON_UNESCAPED_UNICODE),
        $actor
    ]);

    $pdo->commit();

    jsonResponse(true,"Plan publié pour ".count($students)." stagiaire(s) : {$created} rotation(s) créée(s), {$updated} mise(s) à jour.",[
        'group_id'=>$groupId,
        'students'=>count($students),
        'plans'=>count($plans),
        'rotations_created'=>$created,
        'rotations_updated'=>$updated
    ]);

}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[GROUPE ROTATION PUBLISH] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,$e->getMessage(),[],422);
}
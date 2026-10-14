<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/stage-execution.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

/* =========================================================
   MINI-JURYS — MÉTADONNÉES
   ---------------------------------------------------------
   Même initialisation et même périmètre que
   groupes-rotations-list.php.
========================================================= */

try{
    $role=$_SESSION['role_code']??'';

    if($role==='CHEF_SERVICE')requireRole(['CHEF_SERVICE']);
    else requireRole([
        'ADMIN_ACCUEIL',
        'COORDINATEUR_STAGES',
        'AUTORITE_HOSPITALIERE'
    ]);

    if(!contextHostEnabled())
        jsonResponse(false,"Cet établissement n'est pas une structure d'accueil.",[],403);

    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);

    if(!$eid)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    try{
        syncStageExecution(
            $pdo,
            ['host_etablissement_id'=>$eid]
        );
    }catch(Throwable $e){
        error_log('[MINI JURY META SYNC] '.$e->getMessage());
    }

    function mjMetaChefScope(
        PDO $pdo,
        int $uid,
        int $eid,
        string $role
    ):?array{
        if($role!=='CHEF_SERVICE')
            return null;

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
            if($r['scope_type']==='ORGANIZATION')
                $all=true;

            if(
                $r['scope_type']==='UNIT'
                &&(int)$r['scope_id']>0
            ){
                $ids[]=(int)$r['scope_id'];
            }
        }

        if($all)return null;
        if(!$ids)return [-1];

        $allIds=$ids;

        for($i=0;$i<3;$i++){
            $in=implode(
                ',',
                array_fill(0,count($allIds),'?')
            );

            $s=$pdo->prepare("
                SELECT id
                FROM host_units
                WHERE host_etablissement_id=?
                  AND actif=1
                  AND parent_id IN($in)
            ");

            $s->execute(
                array_merge([$eid],$allIds)
            );

            $new=array_map(
                'intval',
                $s->fetchAll(PDO::FETCH_COLUMN)
            );

            $before=count($allIds);

            $allIds=array_values(
                array_unique(
                    array_merge($allIds,$new)
                )
            );

            if(count($allIds)===$before)
                break;
        }

        return $allIds;
    }

    function mjMetaFilter(
        string $field,
        ?array $unitIds,
        array &$where,
        array &$params
    ):void{
        if(!is_array($unitIds))
            return;

        $where[]=
            $field.
            ' IN('.
            implode(
                ',',
                array_fill(0,count($unitIds),'?')
            ).
            ')';

        $params=array_merge(
            $params,
            $unitIds
        );
    }

    $unitIds=mjMetaChefScope(
        $pdo,
        $uid,
        $eid,
        $role
    );

    $where=[
        "r.host_etablissement_id=?",
        "r.statut<>'ANNULEE'"
    ];

    $params=[$eid];

    mjMetaFilter(
        'a.host_unit_id',
        $unitIds,
        $where,
        $params
    );

    $s=$pdo->prepare("
        SELECT
            r.id AS rotation_id,
            r.assignment_id,
            r.group_id,
            r.group_rotation_plan_id,
            r.sequence_no,
            r.host_unit_id,
            r.date_debut,
            r.date_fin,
            r.statut,

            MAX(
                CASE
                    WHEN e.type_evaluation='FIN_ROTATION'
                     AND e.statut IN('VALIDEE','FINALISEE')
                    THEN 1
                    ELSE 0
                END
            ) AS final_eval_ready,

            ROUND(
                AVG(
                    CASE
                        WHEN e.type_evaluation='FIN_ROTATION'
                         AND e.statut IN('VALIDEE','FINALISEE')
                        THEN e.note_finale
                        ELSE NULL
                    END
                ),
                2
            ) AS final_score

        FROM stage_rotations r

        JOIN stage_assignments a
          ON a.id=r.assignment_id
         AND a.host_etablissement_id=r.host_etablissement_id

        LEFT JOIN stage_evaluations e
          ON e.rotation_id=r.id

        WHERE ".implode(' AND ',$where)."

        GROUP BY
            r.id,
            r.assignment_id,
            r.group_id,
            r.group_rotation_plan_id,
            r.sequence_no,
            r.host_unit_id,
            r.date_debut,
            r.date_fin,
            r.statut
    ");

    $s->execute($params);

    $rotations=$s->fetchAll(PDO::FETCH_ASSOC);

    $whereJ=[
        "mj.host_etablissement_id=?"
    ];

    $paramsJ=[$eid];

    mjMetaFilter(
        'a.host_unit_id',
        $unitIds,
        $whereJ,
        $paramsJ
    );

    $s=$pdo->prepare("
        SELECT
            mj.id,
            mj.assignment_id,
            mj.department_unit_id,
            mj.statut,
            mj.note_finale,
            mj.decision,
            mj.appreciation,
            mj.date_jury,
            mj.validated_at

        FROM stage_mini_juries mj

        JOIN stage_assignments a
          ON a.id=mj.assignment_id
         AND a.host_etablissement_id=mj.host_etablissement_id

        WHERE ".implode(' AND ',$whereJ)."
    ");

    $s->execute($paramsJ);

    $juries=$s->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(true,'',[
        'rotations'=>$rotations,
        'juries'=>$juries,
        'can_manage'=>(
            $role==='CHEF_SERVICE'
            ||$role==='ADMIN_ACCUEIL'
            ||$role==='COORDINATEUR_STAGES'
        )?1:0
    ]);

}catch(Throwable $e){

    error_log(
        '[MINI JURY META] '.
        $e->getMessage().
        ' | '.
        $e->getFile().
        ':'.
        $e->getLine()
    );

    jsonResponse(
        false,
        'Erreur Mini-jurys : '.$e->getMessage(),
        [],
        500
    );
}



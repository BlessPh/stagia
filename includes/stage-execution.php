<?php

/**
 * =============================================================
 * STAGIA-RDC — STAGE EXECUTION ENGINE
 * =============================================================
 *
 * Moteur temporel centralisé pour les rotations.
 *
 * Règles :
 * - PLANIFIEE + date courante dans la période -> ACTIVE
 * - PLANIFIEE/ACTIVE + date courante après la fin -> TERMINEE
 * - aucune régression automatique vers PLANIFIEE
 * - BROUILLON / ANNULEE ne sont jamais modifiés
 *
 * Le moteur synchronise :
 * - stage_rotations (rotations individuelles)
 * - stage_group_rotation_plans (plan collectif D4)
 *
 * Les actions Journal / Présence doivent utiliser la rotation ACTIVE.
 * =============================================================
 */

/** Normalise la date d'exécution, ou utilise la date courante. */
function stageExecutionDate(?string $date=null):string
{
    if($date!==null && preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
        return $date;
    }

    return date('Y-m-d');
}

/** Applique les règles temporelles sans modifier les statuts hors cycle automatique. */
function stageExecutionDesiredStatus(
    string $current,
    string $start,
    string $end,
    string $today
):string{
    if(!in_array($current,['PLANIFIEE','ACTIVE'],true)){
        return $current;
    }

    if($today>$end){
        return 'TERMINEE';
    }

    if($current==='PLANIFIEE' && $today>=$start && $today<=$end){
        return 'ACTIVE';
    }

    return $current;
}

/** Enregistre une transition de rotation ; l'absence de table historique ne bloque pas le moteur. */
function stageExecutionHistory(
    PDO $pdo,
    int $rotationId,
    string $previous,
    string $next,
    string $effectiveDate,
    string $source='AUTO_DATE'
):void{
    try{
        $s=$pdo->prepare("
            INSERT INTO stage_rotation_status_history(
                rotation_id,previous_status,new_status,
                effective_date,source,changed_at
            ) VALUES(?,?,?,?,?,NOW())
        ");
        $s->execute([
            $rotationId,$previous,$next,$effectiveDate,$source
        ]);
    }catch(Throwable $ignored){
        // Le moteur continue même si la table d'historique n'est
        // pas encore installée. La migration 27 reste recommandée.
    }
}

/**
 * Synchronise les statuts selon la date.
 *
 * Scopes supportés :
 * - assignment_id
 * - host_etablissement_id
 * - group_id
 */
/** Synchronise les rotations du périmètre donné dans sa propre transaction si nécessaire. */
function syncStageExecution(
    PDO $pdo,
    array $scope=[],
    ?string $date=null
):array{
    $today=stageExecutionDate($date);

    $where=["r.statut IN('PLANIFIEE','ACTIVE')"];
    $params=[];

    if(!empty($scope['assignment_id'])){
        $where[]='r.assignment_id=?';
        $params[]=(int)$scope['assignment_id'];
    }

    if(!empty($scope['host_etablissement_id'])){
        $where[]='r.host_etablissement_id=?';
        $params[]=(int)$scope['host_etablissement_id'];
    }

    if(!empty($scope['group_id'])){
        $where[]='r.group_id=?';
        $params[]=(int)$scope['group_id'];
    }

    $ownTransaction=!$pdo->inTransaction();

    if($ownTransaction){
        $pdo->beginTransaction();
    }

    try{
        $sql="
            SELECT
                r.id,r.assignment_id,r.group_id,
                r.group_rotation_plan_id,
                r.date_debut,r.date_fin,r.statut,
                r.started_at,r.ended_at
            FROM stage_rotations r
            WHERE ".implode(' AND ',$where)."
            ORDER BY r.id
            FOR UPDATE
        ";

        $s=$pdo->prepare($sql);
        $s->execute($params);
        $rotations=$s->fetchAll(PDO::FETCH_ASSOC);

        $individualChanged=0;

        foreach($rotations as $r){
            $old=$r['statut'];
            $new=stageExecutionDesiredStatus(
                $old,
                $r['date_debut'],
                $r['date_fin'],
                $today
            );

            if($new===$old){
                continue;
            }

            if($new==='ACTIVE'){
                $u=$pdo->prepare("
                    UPDATE stage_rotations
                    SET
                        statut='ACTIVE',
                        started_at=COALESCE(
                            started_at,
                            CONCAT(date_debut,' 00:00:00')
                        )
                    WHERE id=?
                      AND statut='PLANIFIEE'
                ");
            }else{
                $u=$pdo->prepare("
                    UPDATE stage_rotations
                    SET
                        statut='TERMINEE',
                        started_at=COALESCE(
                            started_at,
                            CONCAT(date_debut,' 00:00:00')
                        ),
                        ended_at=COALESCE(
                            ended_at,
                            CONCAT(date_fin,' 23:59:59')
                        )
                    WHERE id=?
                      AND statut IN('PLANIFIEE','ACTIVE')
                ");
            }

            $u->execute([(int)$r['id']]);

            if($u->rowCount()===1){
                $individualChanged++;

                stageExecutionHistory(
                    $pdo,
                    (int)$r['id'],
                    $old,
                    $new,
                    $new==='ACTIVE'?$r['date_debut']:$r['date_fin']
                );
            }
        }

        /*
         * Plans collectifs.
         * On applique le même scope sans dépendre des rotations
         * individuelles afin qu'un plan garde un état cohérent.
         */
        $gpWhere=["gp.statut IN('PLANIFIEE','ACTIVE')"];
        $gpParams=[];

        if(!empty($scope['host_etablissement_id'])){
            $gpWhere[]='gp.host_etablissement_id=?';
            $gpParams[]=(int)$scope['host_etablissement_id'];
        }

        if(!empty($scope['group_id'])){
            $gpWhere[]='gp.group_id=?';
            $gpParams[]=(int)$scope['group_id'];
        }

        if(!empty($scope['assignment_id'])){
            $gpWhere[]="
                EXISTS(
                    SELECT 1
                    FROM stage_rotations rr
                    WHERE rr.assignment_id=?
                      AND rr.group_rotation_plan_id=gp.id
                )
            ";
            $gpParams[]=(int)$scope['assignment_id'];
        }

        $s=$pdo->prepare("
            SELECT
                gp.id,gp.group_id,gp.date_debut,gp.date_fin,gp.statut
            FROM stage_group_rotation_plans gp
            WHERE ".implode(' AND ',$gpWhere)."
            ORDER BY gp.id
            FOR UPDATE
        ");
        $s->execute($gpParams);
        $plans=$s->fetchAll(PDO::FETCH_ASSOC);

        $planChanged=0;

        foreach($plans as $p){
            $old=$p['statut'];
            $new=stageExecutionDesiredStatus(
                $old,
                $p['date_debut'],
                $p['date_fin'],
                $today
            );

            if($new===$old){
                continue;
            }

            $u=$pdo->prepare("
                UPDATE stage_group_rotation_plans
                SET statut=?
                WHERE id=?
                  AND statut=?
            ");
            $u->execute([$new,(int)$p['id'],$old]);

            if($u->rowCount()===1){
                $planChanged++;

                try{
                    $pdo->prepare("
                        INSERT INTO stage_group_history(
                            group_id,event_code,details,
                            actor_user_id,created_at
                        ) VALUES(
                            ?,'ROTATION_PLAN_STATUS_AUTO',?,
                            NULL,NOW()
                        )
                    ")->execute([
                        (int)$p['group_id'],
                        json_encode([
                            'rotation_plan_id'=>(int)$p['id'],
                            'previous_status'=>$old,
                            'new_status'=>$new,
                            'effective_date'=>$new==='ACTIVE'
                                ?$p['date_debut']
                                :$p['date_fin'],
                            'sync_date'=>$today
                        ],JSON_UNESCAPED_UNICODE)
                    ]);
                }catch(Throwable $ignored){}
            }
        }

        if($ownTransaction){
            $pdo->commit();
        }

        return [
            'date'=>$today,
            'individual_changed'=>$individualChanged,
            'plan_changed'=>$planChanged
        ];

    }catch(Throwable $e){
        if($ownTransaction && $pdo->inTransaction()){
            $pdo->rollBack();
        }

        throw $e;
    }
}

/** Retourne la rotation active aujourd'hui pour une affectation. */
function stageExecutionCurrentRotation(
    PDO $pdo,
    int $assignmentId,
    ?string $date=null
):?array{
    $today=stageExecutionDate($date);

    syncStageExecution(
        $pdo,
        ['assignment_id'=>$assignmentId],
        $today
    );

    $s=$pdo->prepare("
        SELECT
            r.id rotation_id,
            r.uuid rotation_uuid,
            r.assignment_id,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut,
            r.objectifs,
            r.observation,

            u.id host_unit_id,
            u.code unit_code,
            u.nom unit_name,
            u.type unit_type,

            parent.id parent_unit_id,
            parent.code parent_unit_code,
            parent.nom parent_unit_name,
            parent.type parent_unit_type,

            su.id supervisor_user_id,
            CONCAT_WS(' ',su.prenom,su.nom,su.postnom) supervisor_name,
            COALESCE(NULLIF(eu.fonction,''),'Encadreur') supervisor_function

        FROM stage_rotations r

        JOIN host_units u ON u.id=r.host_unit_id

        LEFT JOIN host_units parent ON parent.id=u.parent_id

        LEFT JOIN stage_rotation_supervisors rs
          ON rs.id=(
              SELECT rs2.id
              FROM stage_rotation_supervisors rs2
              WHERE rs2.rotation_id=r.id
                AND rs2.actif=1
              ORDER BY rs2.principal DESC,rs2.id
              LIMIT 1
          )

        LEFT JOIN users su ON su.id=rs.user_id

        LEFT JOIN etablissement_users eu
          ON eu.user_id=su.id
         AND eu.etablissement_id=r.host_etablissement_id

        WHERE r.assignment_id=?
          AND r.statut='ACTIVE'
          AND ? BETWEEN r.date_debut AND r.date_fin

        ORDER BY r.sequence_no
        LIMIT 1
    ");
    $s->execute([$assignmentId,$today]);

    $row=$s->fetch(PDO::FETCH_ASSOC);

    return $row?:null;
}

/** Retourne la prochaine rotation planifiée après la date considérée. */
function stageExecutionNextRotation(
    PDO $pdo,
    int $assignmentId,
    ?string $date=null
):?array{
    $today=stageExecutionDate($date);

    $s=$pdo->prepare("
        SELECT
            r.id rotation_id,
            r.uuid rotation_uuid,
            r.sequence_no,
            r.date_debut,
            r.date_fin,
            r.statut,
            u.code unit_code,
            u.nom unit_name,
            u.type unit_type
            ,parent.id parent_unit_id
            ,parent.code parent_unit_code
            ,parent.nom parent_unit_name
            ,parent.type parent_unit_type
        FROM stage_rotations r
        JOIN host_units u ON u.id=r.host_unit_id
        LEFT JOIN host_units parent ON parent.id=u.parent_id
        WHERE r.assignment_id=?
          AND r.statut='PLANIFIEE'
          AND r.date_debut>?
        ORDER BY r.date_debut,r.sequence_no
        LIMIT 1
    ");
    $s->execute([$assignmentId,$today]);

    $row=$s->fetch(PDO::FETCH_ASSOC);

    return $row?:null;
}

/** Calcule les droits fonctionnels de journal, présence et évaluation selon les rotations. */
function stageExecutionAccess(
    PDO $pdo,
    int $assignmentId,
    ?string $date=null
):array{
    $today=stageExecutionDate($date);
    $current=stageExecutionCurrentRotation($pdo,$assignmentId,$today);
    $next=stageExecutionNextRotation($pdo,$assignmentId,$today);

    $s=$pdo->prepare("
        SELECT COUNT(*)
        FROM stage_rotations
        WHERE assignment_id=?
          AND statut='TERMINEE'
    ");
    $s->execute([$assignmentId]);
    $terminated=(int)$s->fetchColumn();

    return [
        'date'=>$today,
        'current_rotation'=>$current,
        'next_rotation'=>$next,

        // Écriture quotidienne uniquement dans la rotation courante.
        'can_logbook'=>$current!==null,
        'can_attendance'=>$current!==null,

        // Consultation / évaluation disponible pendant une rotation
        // ou après qu'au moins une rotation soit terminée.
        'can_evaluation'=>$current!==null || $terminated>0,

        'reason'=>$current
            ?null
            :($next
                ?'Le suivi s’ouvrira au début de la prochaine rotation.'
                :'Aucune rotation active aujourd’hui.'),

        'unlock_date'=>$next['date_debut']??null
    ];
}

/**
 * Garde backend à réutiliser dans les endpoints de journal/présence.
 */
/** Garde backend : interdit les écritures de suivi lorsqu'aucune rotation n'est active. */
function requireActiveRotation(
    PDO $pdo,
    int $assignmentId,
    ?string $date=null
):array{
    $rotation=stageExecutionCurrentRotation(
        $pdo,
        $assignmentId,
        $date
    );

    if(!$rotation){
        throw new RuntimeException(
            "Aucune rotation active pour cette date. ".
            "Le journal et la présence ne peuvent pas être enregistrés."
        );
    }

    return $rotation;
}

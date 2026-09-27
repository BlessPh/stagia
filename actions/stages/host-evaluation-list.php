<?php
/**
 * Endpoint AJAX qui liste les rotations et évaluations visibles selon le rôle de supervision.
 * Il calcule les actions possibles sans autoriser le navigateur à décider des droits.
 */
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/*
|--------------------------------------------------------------------------
| Liste des évaluations hospitalières
|--------------------------------------------------------------------------
| ENCADREUR / EVALUATEUR_CLINIQUE :
| - voit uniquement les rotations où il est superviseur ;
| - voit les évaluations encore à traiter : aucune évaluation ou brouillon ;
| - ne voit plus la ligne après soumission/validation/finalisation.
|
| CHEF_SERVICE :
| - voit uniquement les évaluations SOUMISES dans son périmètre ;
| - après validation, la ligne disparaît.
|
| ADMIN_ACCUEIL :
| - accès institutionnel selon permission de finalisation.
*/
requireAjaxRole([
    'ENCADREUR',
    'EVALUATEUR_CLINIQUE',
    'CHEF_SERVICE',
    'ADMIN_ACCUEIL'
,'ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_STAGE_CLINIQUE','MAITRE_DE_STAGE_CLINIQUE']);

/** Vérifie une permission active, dans le périmètre plateforme ou établissement de l'utilisateur. */
function evPerm(PDO $pdo,int $uid,int $eid,string $code):bool{
    $s=$pdo->prepare("\n        SELECT 1\n        FROM role_assignments ra\n        JOIN role_permissions rp ON rp.role_id=ra.role_id\n        JOIN permissions p ON p.id=rp.permission_id\n        WHERE ra.user_id=?\n          AND ra.actif=1\n          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())\n          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())\n          AND p.code=?\n          AND p.actif=1\n          AND (ra.scope_type='PLATFORM' OR ra.etablissement_id=?)\n        LIMIT 1\n    ");
    $s->execute([$uid,$code,$eid]);
    return (bool)$s->fetchColumn();
}

/** Vérifie qu'un chef de service couvre l'unité demandée ou son unité parente. */
function evChefUnit(PDO $pdo,int $uid,int $eid,int $unit):bool{
    $s=$pdo->prepare("\n        SELECT 1\n        FROM role_assignments ra\n        JOIN roles r ON r.id=ra.role_id\n        LEFT JOIN host_units hu ON hu.id=?\n        WHERE ra.user_id=?\n          AND (r.code='CHEF_SERVICE' OR r.code LIKE '%ROLE_CHEF_SERVICE')\n          AND ra.etablissement_id=?\n          AND ra.actif=1\n          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())\n          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())\n          AND (\n                ra.scope_type='ORGANIZATION'\n                OR (\n                    ra.scope_type='UNIT'\n                    AND (ra.scope_id=? OR ra.scope_id=hu.parent_id)\n                )\n          )\n        LIMIT 1\n    ");
    $s->execute([$unit,$uid,$eid,$unit]);
    return (bool)$s->fetchColumn();
}

try{
    /* Le rôle de session détermine les files de travail : encadreur, chef ou finalisateur. */
    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $roleUpper=strtoupper(trim((string)($_SESSION['role_code']??'')));

    if(!$eid||!$uid)
        jsonResponse(false,'Aucun établissement associé.',[],403);

    $isChef=$roleUpper==='CHEF_SERVICE'||preg_match('/ROLE_CHEF_SERVICE$/',$roleUpper);
    $isEnc=in_array($roleUpper,['ENCADREUR','EVALUATEUR_CLINIQUE','ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_STAGE_CLINIQUE','MAITRE_DE_STAGE_CLINIQUE'],true);

    /* Permissions utilisateur */
    $pc=evPerm($pdo,$uid,$eid,'evaluation.create');
    $pu=evPerm($pdo,$uid,$eid,'evaluation.update');
    $ps=evPerm($pdo,$uid,$eid,'evaluation.submit');
    $pv=evPerm($pdo,$uid,$eid,'evaluation.validate')||$isChef;
    $pf=evPerm($pdo,$uid,$eid,'evaluation.finalize');

    /*
       Filtre métier :
       - Chef : uniquement les évaluations SOUMISES.
       - Encadreur : seulement le travail restant à faire ou à corriger.
         Donc on cache les évaluations SOUMISES, VALIDÉES et FINALISÉES.
    */
    $chefPendingOnly=$isChef?1:0;
    $encadreurWorkOnly=($isEnc&&!$isChef)?1:0;

    $q=$pdo->prepare("\n        SELECT\n            r.id rotation_id,\n            r.sequence_no,\n            r.date_debut,\n            r.date_fin,\n            r.statut rotation_statut,\n            r.host_unit_id,\n\n            a.id assignment_id,\n\n            sp.id student_id,\n            sp.stagia_code,\n            sp.nom,\n            sp.postnom,\n            sp.prenom,\n\n            hu.code unit_code,\n            hu.nom unit_name,\n            parent.nom parent_name,\n\n            c.id campaign_id,\n            c.code campaign_code,\n            c.titre campaign_title,\n            c.stage_type_id,\n\n            sae.promotion_id,\n            uni.nom university_name,\n\n            psc.id promotion_stage_config_id,\n\n            ref.id referential_id,\n            ref.code referential_code,\n            ref.libelle referential_label,\n            ref.version referential_version,\n\n            EXISTS(\n                SELECT 1\n                FROM stage_rotation_supervisors rs\n                WHERE rs.rotation_id=r.id\n                  AND rs.user_id=?\n                  AND rs.actif=1\n            ) is_supervisor,\n\n            (\n                SELECT COUNT(*)\n                FROM stage_logbook_entries le\n                WHERE le.rotation_id=r.id\n                  AND le.statut='VALIDE'\n            ) validated_logbooks,\n\n            (\n                SELECT COUNT(*)\n                FROM stage_attendances att\n                WHERE att.rotation_id=r.id\n            ) attendance_count\n\n        FROM stage_rotations r\n        JOIN stage_assignments a ON a.id=r.assignment_id\n        JOIN stage_admissions ad ON ad.id=a.admission_id\n        JOIN stage_reservations sr ON sr.id=ad.reservation_id\n        JOIN stage_applications sa ON sa.id=sr.application_id\n        JOIN student_academic_enrollments sae ON sae.id=sa.academic_enrollment_id\n        JOIN student_enrollments se ON se.id=sae.enrollment_id\n        JOIN student_profiles sp ON sp.id=se.student_id\n        JOIN stage_campaigns c ON c.id=sa.campaign_id\n\n        JOIN stage_campaign_promotions scp\n          ON scp.campaign_id=c.id\n         AND scp.promotion_id=sae.promotion_id\n\n        JOIN promotion_stage_configs psc\n          ON psc.id=scp.promotion_stage_config_id\n         AND psc.actif=1\n\n        JOIN stage_referentials ref\n          ON ref.id=psc.referential_id\n         AND ref.statut='ACTIF'\n\n        JOIN etablissements uni ON uni.id=c.owner_etablissement_id\n        JOIN host_units hu ON hu.id=r.host_unit_id\n        LEFT JOIN host_units parent ON parent.id=hu.parent_id\n\n        WHERE r.host_etablissement_id=?\n          AND r.statut IN('PLANIFIEE','ACTIVE','TERMINEE')\n          AND (\n                EXISTS(\n                    SELECT 1\n                    FROM stage_rotation_supervisors rs2\n                    WHERE rs2.rotation_id=r.id\n                      AND rs2.user_id=?\n                      AND rs2.actif=1\n                )\n\n                OR EXISTS(\n                    SELECT 1\n                    FROM role_assignments ra\n                    JOIN roles rr ON rr.id=ra.role_id\n                    WHERE ra.user_id=?\n                      AND (rr.code='CHEF_SERVICE' OR rr.code LIKE '%ROLE_CHEF_SERVICE')\n                      AND ra.etablissement_id=?\n                      AND ra.scope_type='ORGANIZATION'\n                      AND ra.actif=1\n                      AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())\n                      AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())\n                )\n\n                OR EXISTS(\n                    SELECT 1\n                    FROM role_assignments ra\n                    JOIN roles rr ON rr.id=ra.role_id\n                    WHERE ra.user_id=?\n                      AND (rr.code='CHEF_SERVICE' OR rr.code LIKE '%ROLE_CHEF_SERVICE')\n                      AND ra.etablissement_id=?\n                      AND ra.scope_type='UNIT'\n                      AND ra.scope_id=r.host_unit_id\n                      AND ra.actif=1\n                      AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())\n                      AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())\n                )\n\n                OR EXISTS(\n                    SELECT 1\n                    FROM role_assignments ra\n                    JOIN roles rr ON rr.id=ra.role_id\n                    JOIN host_units child ON child.id=r.host_unit_id\n                    WHERE ra.user_id=?\n                      AND (rr.code='CHEF_SERVICE' OR rr.code LIKE '%ROLE_CHEF_SERVICE')\n                      AND ra.etablissement_id=?\n                      AND ra.scope_type='UNIT'\n                      AND ra.scope_id=child.parent_id\n                      AND ra.actif=1\n                      AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())\n                      AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())\n                )\n\n                OR ?\n          )\n\n          /* Chef : on garde uniquement les évaluations soumises à valider. */\n          AND (\n                ?=0\n                OR EXISTS(\n                    SELECT 1\n                    FROM stage_evaluations evp\n                    WHERE evp.rotation_id=r.id\n                      AND evp.statut='SOUMISE'\n                )\n          )\n\n          /* Encadreur : on cache quand c'est déjà soumis, validé ou finalisé. */\n          AND (\n                ?=0\n                OR NOT EXISTS(\n                    SELECT 1\n                    FROM stage_evaluations ev_done\n                    WHERE ev_done.rotation_id=r.id\n                      AND ev_done.evaluator_user_id=?\n                      AND ev_done.statut IN('SOUMISE','VALIDEE','FINALISEE')\n                )\n          )\n\n        ORDER BY r.date_debut DESC,sp.nom,sp.prenom\n    ");

    $q->execute([
        $uid,
        $eid,
        $uid,
        $uid,$eid,
        $uid,$eid,
        $uid,$eid,
        $pf?1:0,
        $chefPendingOnly,
        $encadreurWorkOnly,$uid
    ]);

    $items=$q->fetchAll(PDO::FETCH_ASSOC);

    $qe=$pdo->prepare("\n        SELECT\n            e.id,\n            e.referential_id,\n            e.evaluator_user_id,\n            e.evaluator_role,\n            e.type_evaluation,\n            e.statut,\n            e.note_finale,\n            e.appreciation,\n            e.points_forts,\n            e.axes_amelioration,\n            e.evaluated_at,\n            e.submitted_at,\n            e.validated_at,\n            e.validated_by,\n            e.finalized_at,\n            e.finalized_by,\n            CONCAT_WS(' ',u.prenom,u.nom,u.postnom) evaluator_name\n        FROM stage_evaluations e\n        LEFT JOIN users u ON u.id=e.evaluator_user_id\n        WHERE e.rotation_id=?\n        ORDER BY FIELD(e.type_evaluation,'CONTINUE','MI_ROTATION','FIN_ROTATION'),e.id\n    ");

    $qs=$pdo->prepare("\n        SELECT competency_id,note,note_max,poids,commentaire\n        FROM stage_evaluation_scores\n        WHERE evaluation_id=?\n    ");

    $qc=$pdo->prepare("\n        SELECT\n            c.id,\n            c.code,\n            c.nom,\n            c.categorie,\n            c.description,\n            rc.poids,\n            rc.note_max,\n            rc.section,\n            rc.obligatoire,\n            rc.ordre\n        FROM stage_referential_competencies rc\n        JOIN stage_competencies c ON c.id=rc.competency_id AND c.actif=1\n        WHERE rc.referential_id=?\n        ORDER BY rc.ordre,c.categorie,c.code\n    ");

    $stats=[
        'rotations'=>count($items),
        'brouillons'=>0,
        'soumises'=>0,
        'validees'=>0,
        'finalisees'=>0
    ];

    /* Les droits par évaluation sont calculés côté serveur avec le statut et l'auteur réels. */
    foreach($items as &$x){
        foreach(['rotation_id','assignment_id','student_id','stage_type_id','promotion_id','referential_id','host_unit_id'] as $f)
            $x[$f]=(int)$x[$f];

        $x['validated_logbooks']=(int)$x['validated_logbooks'];
        $x['attendance_count']=(int)$x['attendance_count'];
        $x['is_supervisor']=(int)$x['is_supervisor'];

        $qc->execute([$x['referential_id']]);
        $x['competencies']=$qc->fetchAll(PDO::FETCH_ASSOC);

        foreach($x['competencies'] as &$c){
            $c['id']=(int)$c['id'];
            $c['poids']=(float)$c['poids'];
            $c['obligatoire']=(int)$c['obligatoire'];
        }
        unset($c);

        $qe->execute([$x['rotation_id']]);
        $x['evaluations']=$qe->fetchAll(PDO::FETCH_ASSOC);

        $chef=$pv&&evChefUnit($pdo,$uid,$eid,$x['host_unit_id']);

        foreach($x['evaluations'] as &$e){
            $e['id']=(int)$e['id'];
            $e['evaluator_user_id']=(int)$e['evaluator_user_id'];
            $e['note_finale']=$e['note_finale']!==null?(float)$e['note_finale']:null;

            $qs->execute([$e['id']]);
            $e['scores']=$qs->fetchAll(PDO::FETCH_ASSOC);

            $own=$e['evaluator_user_id']===$uid;

            $e['can_edit']=$own&&$x['is_supervisor']&&$pu&&$e['statut']==='BROUILLON'?1:0;
            $e['can_submit']=$own&&$x['is_supervisor']&&$ps&&$e['statut']==='BROUILLON'?1:0;
            $e['can_validate']=$chef&&$e['statut']==='SOUMISE'&&!$own?1:0;
            $e['can_finalize']=$pf&&$e['statut']==='VALIDEE'&&!$own?1:0;

            if($e['statut']==='BROUILLON')$stats['brouillons']++;
            elseif($e['statut']==='SOUMISE')$stats['soumises']++;
            elseif($e['statut']==='VALIDEE')$stats['validees']++;
            elseif($e['statut']==='FINALISEE')$stats['finalisees']++;
        }
        unset($e);

        $x['can_create']=$x['is_supervisor']&&$pc?1:0;
        $x['can_validate']=$chef?1:0;
        $x['can_finalize']=$pf?1:0;
    }
    unset($x);

    jsonResponse(true,'',[
        'items'=>$items,
        'stats'=>$stats,
        'filters'=>[
            'chef_pending_only'=>$chefPendingOnly,
            'encadreur_work_only'=>$encadreurWorkOnly
        ]
    ]);

}catch(Throwable $e){
    error_log('[HOST EVALUATION LIST] '.$e->getMessage().' | '.$e->getFile().':'.$e->getLine());
    jsonResponse(false,'Erreur évaluations : '.$e->getMessage(),[],500);
}

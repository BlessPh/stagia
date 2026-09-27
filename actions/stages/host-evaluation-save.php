<?php
/**
 * Endpoint AJAX de sauvegarde ou soumission d'une évaluation par l'encadreur de rotation.
 * Il valide le référentiel, les compétences et calcule la note pondérée côté serveur.
 */
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/ajax.php';
require_once __DIR__.'/../../includes/permissions.php';

/* Encadreur / maître de stage + admin si besoin */
requireAjaxRole(['ENCADREUR','EVALUATEUR_CLINIQUE','ADMIN_ACCUEIL','ENCADREUR_CLINIQUE','MAITRE_STAGE','MAITRE_DE_STAGE','MAITRE_STAGE_CLINIQUE','MAITRE_DE_STAGE_CLINIQUE']);

/* Sécurité CSRF */
$csrf=$_POST['csrf']??'';
if(empty($_SESSION['csrf'])||!$csrf||!hash_equals($_SESSION['csrf'],$csrf))
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);

/* UUID évaluation */
/** Génère l'identifiant UUID de la nouvelle évaluation. */
function evUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&15)|64);
    $d[8]=chr((ord($d[8])&63)|128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($d),4));
}

/* Vérifie une permission RBAC */
/** Vérifie une permission RBAC active dans le périmètre de l'établissement concerné. */
function evHas(PDO $pdo,int $uid,int $eid,string $code):bool{
    $s=$pdo->prepare("
        SELECT 1
        FROM role_assignments ra
        JOIN role_permissions rp ON rp.role_id=ra.role_id
        JOIN permissions p ON p.id=rp.permission_id
        WHERE ra.user_id=?
          AND ra.actif=1
          AND (ra.starts_at IS NULL OR ra.starts_at<=NOW())
          AND (ra.ends_at IS NULL OR ra.ends_at>=NOW())
          AND p.code=?
          AND p.actif=1
          AND (ra.scope_type='PLATFORM' OR ra.etablissement_id=?)
        LIMIT 1
    ");
    $s->execute([$uid,$code,$eid]);
    return (bool)$s->fetchColumn();
}

/* Autorisation plus directe : l’utilisateur est bien encadreur de cette rotation */
/** Confirme que l'utilisateur est un encadreur actif de la rotation à évaluer. */
function evIsRotationSupervisor(PDO $pdo,int $uid,int $rid):bool{
    $s=$pdo->prepare("
        SELECT 1
        FROM stage_rotation_supervisors
        WHERE rotation_id=?
          AND user_id=?
          AND actif=1
        LIMIT 1
    ");
    $s->execute([$rid,$uid]);
    return (bool)$s->fetchColumn();
}

try{
    /* Lecture des données de l'évaluation et validation des valeurs contrôlées. */
    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);
    $rid=(int)($_POST['rotation_id']??0);

    $type=strtoupper(trim($_POST['type_evaluation']??''));
    $action=strtoupper(trim($_POST['action']??'SAVE'));

    $app=trim($_POST['appreciation']??'');
    $forts=trim($_POST['points_forts']??'');
    $axes=trim($_POST['axes_amelioration']??'');
    $scores=json_decode($_POST['scores']??'[]',true);

    if(!$eid||!$uid||!$rid)
        jsonResponse(false,'Rotation invalide.',[],422);

    if(!in_array($type,['CONTINUE','MI_ROTATION','FIN_ROTATION'],true))
        jsonResponse(false,'Type d’évaluation invalide.',[],422);

    if(!in_array($action,['SAVE','SUBMIT'],true))
        jsonResponse(false,'Action invalide.',[],422);

    if(!is_array($scores)||!$scores)
        jsonResponse(false,'Notez au moins une compétence.',[],422);

    /* =========================================================
       RÉCUPÉRATION DE LA ROTATION + RÉFÉRENTIEL
       ---------------------------------------------------------
       Correction importante :
       on accepte PLANIFIEE pour permettre le test / brouillon.
       Avant, le code acceptait seulement ACTIVE et TERMINEE.
    ========================================================= */
    $s=$pdo->prepare("
        SELECT
            r.id,
            r.assignment_id,
            r.statut,
            c.stage_type_id,
            sp.id AS student_id,
            rs.role_supervision,
            psc.referential_id

        FROM stage_rotations r

        JOIN stage_assignments a
          ON a.id=r.assignment_id

        JOIN stage_admissions ad
          ON ad.id=a.admission_id

        JOIN stage_reservations sr
          ON sr.id=ad.reservation_id

        JOIN stage_applications sa
          ON sa.id=sr.application_id

        JOIN student_academic_enrollments sae
          ON sae.id=sa.academic_enrollment_id

        JOIN student_enrollments se
          ON se.id=sae.enrollment_id

        JOIN student_profiles sp
          ON sp.id=se.student_id

        JOIN stage_campaigns c
          ON c.id=sa.campaign_id

        JOIN stage_campaign_promotions scp
          ON scp.campaign_id=c.id
         AND scp.promotion_id=sae.promotion_id

        JOIN promotion_stage_configs psc
          ON psc.id=scp.promotion_stage_config_id
         AND psc.actif=1

        JOIN stage_referentials ref
          ON ref.id=psc.referential_id
         AND ref.statut='ACTIF'

        JOIN stage_rotation_supervisors rs
          ON rs.rotation_id=r.id
         AND rs.user_id=?
         AND rs.actif=1

        WHERE r.id=?
          AND r.host_etablissement_id=?
          AND r.statut IN('PLANIFIEE','ACTIVE','TERMINEE')
        LIMIT 1
    ");
    $s->execute([$uid,$rid,$eid]);
    $rot=$s->fetch(PDO::FETCH_ASSOC);

    if(!$rot)
        jsonResponse(false,'Rotation non autorisée ou référentiel non configuré.',[],403);

    /* L’évaluation finale reste réservée à une rotation terminée */
    if($type==='FIN_ROTATION'&&$rot['statut']!=='TERMINEE')
        jsonResponse(false,"L'évaluation de fin nécessite une rotation terminée.",[],422);

    /* Vérification encadreur affecté à cette rotation */
    if(!evIsRotationSupervisor($pdo,$uid,$rid))
        jsonResponse(false,'Vous n’êtes pas encadreur de cette rotation.',[],403);

    /* =========================================================
       COMPÉTENCES DU RÉFÉRENTIEL
    ========================================================= */
    $s=$pdo->prepare("
        SELECT
            rc.competency_id,
            rc.poids,
            rc.obligatoire
        FROM stage_referential_competencies rc
        JOIN stage_competencies c
          ON c.id=rc.competency_id
         AND c.actif=1
        WHERE rc.referential_id=?
    ");
    $s->execute([(int)$rot['referential_id']]);

    /* Seules les compétences actives du référentiel de la promotion peuvent être notées. */
    $allowed=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $c){
        $allowed[(int)$c['competency_id']]=[
            'w'=>(float)$c['poids'],
            'req'=>(int)$c['obligatoire']
        ];
    }

    if(!$allowed)
        jsonResponse(false,'Référentiel sans compétence active.',[],422);

    /* =========================================================
       VALIDATION DES NOTES
    ========================================================= */
    $valid=[];
    $weighted=0;
    $weights=0;
    $scored=[];

    /* Notes et pondérations sont contrôlées puis cumulées pour le calcul de la note finale. */
    foreach($scores as $x){
        $cid=(int)($x['competency_id']??0);
        $note=(float)($x['note']??-1);

        if(!$cid||!isset($allowed[$cid]))
            continue;

        $max=$allowed[$cid]['max'];
        if($note<0||$note>$max)
            jsonResponse(false,'Chaque note doit être comprise entre 0 et '.$max.'.',[],422);

        $w=$allowed[$cid]['w'];
        $comment=trim($x['commentaire']??'');

        $valid[]=[$cid,$note,$max,$w,$comment];
        $scored[$cid]=1;

        $weighted+=($note/$max)*$w;
        $weights+=$w;
    }

    if(!$valid)
        jsonResponse(false,'Aucune compétence valide.',[],422);

    /* En soumission, toutes les compétences obligatoires doivent être notées */
    /* La soumission impose une appréciation et toutes les compétences obligatoires. */
    if($action==='SUBMIT'){
        if(!evHas($pdo,$uid,$eid,'evaluation.submit'))
            jsonResponse(false,'Permission de soumission insuffisante.',[],403);

        if($app==='')
            jsonResponse(false,"L'appréciation est obligatoire.",[],422);

        foreach($allowed as $cid=>$c){
            if($c['req']&&!isset($scored[$cid]))
                jsonResponse(false,'Toutes les compétences obligatoires doivent être notées.',[],422);
        }
    }

    /* La note finale est une moyenne pondérée normalisée sur 100. */
    $final=$weights?round(($weighted/$weights)*100,2):0;

    /* =========================================================
       CRÉATION / MISE À JOUR DE L’ÉVALUATION
    ========================================================= */
    /* Entête d'évaluation, notes détaillées et historique sont écrits atomiquement. */
    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT id,statut
        FROM stage_evaluations
        WHERE rotation_id=?
          AND evaluator_user_id=?
          AND type_evaluation=?
        LIMIT 1
        FOR UPDATE
    ");
    $s->execute([$rid,$uid,$type]);
    $old=$s->fetch(PDO::FETCH_ASSOC);

    /* Seul un brouillon appartenant à l'évaluateur peut être remplacé. */
    if($old){
        if($old['statut']!=='BROUILLON')
            throw new RuntimeException("Cette évaluation n'est plus modifiable.");

        if(!evHas($pdo,$uid,$eid,'evaluation.update'))
            throw new RuntimeException('Permission de modification insuffisante.');
    }else{
        if(!evHas($pdo,$uid,$eid,'evaluation.create'))
            throw new RuntimeException('Permission de création insuffisante.');
    }

    $status=$action==='SUBMIT'?'SOUMISE':'BROUILLON';

    if($old){
        $id=(int)$old['id'];

        $pdo->prepare("
            UPDATE stage_evaluations
            SET
                referential_id=?,
                evaluator_role=?,
                statut=?,
                note_finale=?,
                appreciation=?,
                points_forts=?,
                axes_amelioration=?,
                evaluated_at=NOW(),
                submitted_at=CASE WHEN ?='SOUMISE' THEN NOW() ELSE NULL END,
                validated_at=NULL,
                validated_by=NULL,
                finalized_at=NULL,
                finalized_by=NULL
            WHERE id=?
        ")->execute([
            $rot['referential_id'],
            $rot['role_supervision'],
            $status,
            $final,
            $app?:null,
            $forts?:null,
            $axes?:null,
            $status,
            $id
        ]);

        $pdo->prepare("DELETE FROM stage_evaluation_scores WHERE evaluation_id=?")
            ->execute([$id]);

        $previous='BROUILLON';

    }else{
        $pdo->prepare("
            INSERT INTO stage_evaluations(
                uuid,
                rotation_id,
                assignment_id,
                student_id,
                host_etablissement_id,
                referential_id,
                evaluator_user_id,
                evaluator_role,
                type_evaluation,
                statut,
                note_finale,
                appreciation,
                points_forts,
                axes_amelioration,
                evaluated_at,
                submitted_at
            )
            VALUES(
                ?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                NOW(),
                CASE WHEN ?='SOUMISE' THEN NOW() ELSE NULL END
            )
        ")->execute([
            evUuid(),
            $rid,
            $rot['assignment_id'],
            $rot['student_id'],
            $eid,
            $rot['referential_id'],
            $uid,
            $rot['role_supervision'],
            $type,
            $status,
            $final,
            $app?:null,
            $forts?:null,
            $axes?:null,
            $status
        ]);

        $id=(int)$pdo->lastInsertId();
        $previous=null;
    }

    /* Enregistrement des notes */
    $ins=$pdo->prepare("
        INSERT INTO stage_evaluation_scores(
            evaluation_id,
            competency_id,
            note,
            note_max,
            poids,
            commentaire
        )
        VALUES(?,?,?,?,?,?)
    ");

    /* Enregistrement des notes détaillées correspondant au référentiel validé. */
    foreach($valid as $v)
        $ins->execute([$id,$v[0],$v[1],$v[2],$v[3],$v[4]?:null]);

    /* Historique statut */
    if($status!==$previous){
        $pdo->prepare("
            INSERT INTO stage_evaluation_status_history(
                evaluation_id,
                previous_status,
                new_status,
                changed_by_user_id
            )
            VALUES(?,?,?,?)
        ")->execute([$id,$previous,$status,$uid]);
    }

    $pdo->commit();

    jsonResponse(
        true,
        $status==='SOUMISE'
            ?'Évaluation soumise au Chef de service.'
            :'Brouillon enregistré.',
        [
            'id'=>$id,
            'statut'=>$status,
            'note_finale'=>$final
        ]
    );

}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    jsonResponse(false,'Erreur : '.$e->getMessage(),[],422);
}

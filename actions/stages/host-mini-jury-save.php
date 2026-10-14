<?php
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../includes/stage-execution.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ajax.php';

/* =========================================================
   MINI-JURYS — ENREGISTREMENT
   ---------------------------------------------------------
   Même session / même contexte que Rotations du service.
========================================================= */

$csrf=$_POST['csrf']??'';

if(
    empty($_SESSION['csrf'])
    ||!$csrf
    ||!hash_equals($_SESSION['csrf'],$csrf)
){
    jsonResponse(false,'Jeton de sécurité invalide.',[],419);
}

function mjSaveUuid():string{
    $d=random_bytes(16);
    $d[6]=chr((ord($d[6])&0x0f)|0x40);
    $d[8]=chr((ord($d[8])&0x3f)|0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($d),4)
    );
}

try{
    $role=$_SESSION['role_code']??'';

    if($role==='CHEF_SERVICE')requireRole(['CHEF_SERVICE']);
    else requireRole([
        'ADMIN_ACCUEIL',
        'COORDINATEUR_STAGES'
    ]);

    $eid=(int)currentEtablissementId($pdo);
    $uid=(int)($_SESSION['user_id']??0);

    $assignmentId=(int)($_POST['assignment_id']??0);
    $departmentId=(int)($_POST['department_unit_id']??0);

    $action=strtoupper(
        trim((string)($_POST['action']??''))
    );

    $dateJury=trim(
        (string)($_POST['date_jury']??'')
    );

    $scoreRaw=trim(
        (string)($_POST['score']??'')
    );

    $decision=strtoupper(
        trim((string)($_POST['decision']??''))
    );

    $appreciation=trim(
        (string)($_POST['appreciation']??'')
    );

    if(!$eid||!$uid)
        jsonResponse(false,'Session invalide.',[],403);

    if(!$assignmentId||!$departmentId)
        jsonResponse(false,'Passage invalide.',[],422);

    if(!in_array($action,['SAVE','VALIDATE'],true))
        jsonResponse(false,'Action invalide.',[],422);

    /*
     * Rotations individuelles du département.
     * Le mini-jury ne peut être validé que si elles existent
     * et sont toutes terminées.
     */
    $s=$pdo->prepare("
        SELECT
            r.id,
            r.statut,
            r.host_unit_id
        FROM stage_rotations r

        JOIN stage_assignments a
          ON a.id=r.assignment_id
         AND a.host_etablissement_id=r.host_etablissement_id

        JOIN stage_admissions ad
          ON ad.id=a.admission_id

        JOIN host_units hu
          ON hu.id=r.host_unit_id

        LEFT JOIN host_units parent
          ON parent.id=hu.parent_id
         AND parent.host_etablissement_id=r.host_etablissement_id

        LEFT JOIN host_units coord
          ON coord.id=ad.coordination_unit_id
         AND coord.host_etablissement_id=r.host_etablissement_id

        WHERE r.assignment_id=?
          AND r.host_etablissement_id=?
          AND r.statut<>'ANNULEE'
          AND COALESCE(coord.id,parent.id,hu.id)=?

        ORDER BY r.sequence_no,r.id
    ");

    $s->execute([
        $assignmentId,
        $eid,
        $departmentId
    ]);

    $rotations=$s->fetchAll(PDO::FETCH_ASSOC);

    if(!$rotations)
        throw new RuntimeException(
            'Aucune rotation individuelle trouvée pour ce département.'
        );

    $rotationIds=[];
    $done=0;

    foreach($rotations as $r){
        $rotationIds[]=(int)$r['id'];

        if($r['statut']==='TERMINEE')
            $done++;
    }

    if($done!==count($rotationIds))
        throw new RuntimeException(
            'Toutes les rotations du département doivent être terminées.'
        );

    $ph=implode(
        ',',
        array_fill(0,count($rotationIds),'?')
    );

    $s=$pdo->prepare("
        SELECT COUNT(DISTINCT rotation_id)
        FROM stage_evaluations
        WHERE rotation_id IN($ph)
          AND type_evaluation='FIN_ROTATION'
          AND statut='FINALISEE'
    ");

    $s->execute($rotationIds);

    if((int)$s->fetchColumn()!==count($rotationIds))
        throw new RuntimeException(
            'Chaque rotation doit avoir une évaluation FIN_ROTATION finalisée.'
        );

    $score=null;

    if($scoreRaw!==''){
        if(!is_numeric($scoreRaw))
            throw new RuntimeException('Note invalide.');

        $score=round((float)$scoreRaw,2);

        if($score<0||$score>100)
            throw new RuntimeException(
                'La note doit être comprise entre 0 et 100.'
            );
    }

    if(
        $decision!==''
        &&!in_array(
            $decision,
            ['VALIDE','A_REPRENDRE','AJOURNE'],
            true
        )
    ){
        throw new RuntimeException('Décision invalide.');
    }

    if(
        $dateJury!==''
        &&(
            !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateJury)
            ||!checkdate((int)substr($dateJury,5,2),(int)substr($dateJury,8,2),(int)substr($dateJury,0,4))
        )
    ){
        throw new RuntimeException('Date du mini-jury invalide.');
    }

    if($dateJury!==''&&$dateJury>date('Y-m-d'))
        throw new RuntimeException('La date du mini-jury ne peut pas être future.');

    if($action==='VALIDATE'){
        if($score===null)
            throw new RuntimeException(
                'La note du mini-jury est obligatoire.'
            );

        if($decision==='')
            throw new RuntimeException(
                'La décision est obligatoire.'
            );

        if($dateJury==='')
            throw new RuntimeException(
                'La date du mini-jury est obligatoire.'
            );
    }

    $pdo->beginTransaction();

    $s=$pdo->prepare("
        SELECT id,statut
        FROM stage_mini_juries
        WHERE assignment_id=?
          AND department_unit_id=?
          AND host_etablissement_id=?
        LIMIT 1
        FOR UPDATE
    ");

    $s->execute([
        $assignmentId,
        $departmentId,
        $eid
    ]);

    $old=$s->fetch(PDO::FETCH_ASSOC);

    if($old&&$old['statut']==='VALIDEE')
        throw new RuntimeException(
            'Ce mini-jury est déjà validé.'
        );

    $status=
        $action==='VALIDATE'
            ?'VALIDEE'
            :'BROUILLON';

    if($old){

        $juryId=(int)$old['id'];

        $pdo->prepare("
            UPDATE stage_mini_juries
            SET
                statut=?,
                note_finale=?,
                decision=?,
                appreciation=?,
                date_jury=?,
                validated_by=?,
                validated_at=?
            WHERE id=?
        ")->execute([
            $status,
            $score,
            $decision!==''?$decision:null,
            $appreciation!==''?$appreciation:null,
            $dateJury!==''?$dateJury:null,
            $status==='VALIDEE'?$uid:null,
            $status==='VALIDEE'
                ?date('Y-m-d H:i:s')
                :null,
            $juryId
        ]);

    }else{

        $pdo->prepare("
            INSERT INTO stage_mini_juries(
                uuid,
                assignment_id,
                department_unit_id,
                host_etablissement_id,
                statut,
                note_finale,
                decision,
                appreciation,
                date_jury,
                created_by,
                validated_by,
                validated_at
            )
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            mjSaveUuid(),
            $assignmentId,
            $departmentId,
            $eid,
            $status,
            $score,
            $decision!==''?$decision:null,
            $appreciation!==''?$appreciation:null,
            $dateJury!==''?$dateJury:null,
            $uid,
            $status==='VALIDEE'?$uid:null,
            $status==='VALIDEE'
                ?date('Y-m-d H:i:s')
                :null
        ]);

        $juryId=(int)$pdo->lastInsertId();
    }

    $pdo->prepare("
        DELETE FROM stage_mini_jury_rotations
        WHERE mini_jury_id=?
    ")->execute([$juryId]);

    $ins=$pdo->prepare("
        INSERT INTO stage_mini_jury_rotations(
            mini_jury_id,
            rotation_id
        )
        VALUES(?,?)
    ");

    foreach($rotationIds as $rotationId)
        $ins->execute([
            $juryId,
            $rotationId
        ]);

    $pdo->commit();

    jsonResponse(
        true,
        $status==='VALIDEE'
            ?'Mini-jury validé avec succès.'
            :'Brouillon enregistré.',
        [
            'id'=>$juryId,
            'statut'=>$status
        ]
    );

}catch(Throwable $e){

    if($pdo->inTransaction())
        $pdo->rollBack();

    error_log(
        '[MINI JURY SAVE] '.
        $e->getMessage().
        ' | '.
        $e->getFile().
        ':'.
        $e->getLine()
    );

    jsonResponse(
        false,
        $e->getMessage(),
        [],
        422
    );
}



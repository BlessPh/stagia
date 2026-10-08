<?php
declare(strict_types=1);

require_once __DIR__.'/../config/database.php';

/** Audit en lecture seule : département d'affectation, service et unité des rotations. */
try{
    $assignments=$pdo->query("
        SELECT a.id assignment_id,a.uuid assignment_uuid,a.statut assignment_status,
               ad.id admission_id,ad.coordination_unit_id,
               department.nom department_name,department.type department_type,department.actif department_active,
               assigned.nom assigned_unit_name,assigned.type assigned_unit_type,assigned.parent_id assigned_parent_id
        FROM stage_assignments a
        JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN host_units department
          ON department.id=ad.coordination_unit_id
         AND department.host_etablissement_id=a.host_etablissement_id
        LEFT JOIN host_units assigned
          ON assigned.id=a.host_unit_id
         AND assigned.host_etablissement_id=a.host_etablissement_id
        ORDER BY a.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $assignmentAnomalies=[];
    foreach($assignments as $row){
        $departmentType=strtoupper(trim((string)($row['department_type']??'')));
        if(empty($row['coordination_unit_id'])||empty($row['department_name']))$reason='DEPARTMENT_MISSING';
        elseif(!(int)$row['department_active'])$reason='DEPARTMENT_INACTIVE';
        elseif(!in_array($departmentType,['DEPARTEMENT','COORDINATION'],true))$reason='DEPARTMENT_TYPE_INVALID';
        elseif(strtoupper((string)$row['assigned_unit_type'])!=='SERVICE')$reason='ASSIGNMENT_TARGET_NOT_SERVICE';
        elseif((int)$row['assigned_parent_id']!==(int)$row['coordination_unit_id'])$reason='SERVICE_OUTSIDE_DEPARTMENT';
        else continue;
        $assignmentAnomalies[]=$row+['reason'=>$reason];
    }

    $rotations=$pdo->query("
        SELECT r.id rotation_id,r.uuid rotation_uuid,r.sequence_no,r.statut rotation_status,
               a.id assignment_id,ad.coordination_unit_id,
               target.nom target_name,target.type target_type,target.parent_id target_parent_id,
               parent.nom parent_name,parent.type parent_type,parent.parent_id parent_department_id
        FROM stage_rotations r
        JOIN stage_assignments a ON a.id=r.assignment_id
        JOIN stage_admissions ad ON ad.id=a.admission_id
        LEFT JOIN host_units target
          ON target.id=r.host_unit_id
         AND target.host_etablissement_id=r.host_etablissement_id
        LEFT JOIN host_units parent
          ON parent.id=target.parent_id
         AND parent.host_etablissement_id=target.host_etablissement_id
        ORDER BY a.id,r.sequence_no,r.date_debut,r.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $rotationAnomalies=[];
    foreach($rotations as $row){
        $targetType=strtoupper(trim((string)($row['target_type']??'')));
        $parentType=strtoupper(trim((string)($row['parent_type']??'')));
        $departmentId=$targetType==='SERVICE'
            ?(int)($row['target_parent_id']??0)
            :(in_array($targetType,['UNITE','UNITÉ'],true)&&$parentType==='SERVICE'
                ?(int)($row['parent_department_id']??0):0);
        if(empty($row['target_name']))$reason='ROTATION_TARGET_MISSING';
        elseif(!in_array($targetType,['SERVICE','UNITE','UNITÉ'],true))$reason='ROTATION_TARGET_TYPE_INVALID';
        elseif(in_array($targetType,['UNITE','UNITÉ'],true)&&$parentType!=='SERVICE')$reason='UNIT_WITHOUT_SERVICE';
        elseif($departmentId!==(int)$row['coordination_unit_id'])$reason='ROTATION_OUTSIDE_DEPARTMENT';
        else continue;
        $rotationAnomalies[]=$row+['reason'=>$reason];
    }

    echo 'Affectations contrôlées : '.count($assignments).PHP_EOL;
    echo 'Rotations contrôlées : '.count($rotations).PHP_EOL;
    echo 'Anomalies d’affectation : '.count($assignmentAnomalies).PHP_EOL;
    foreach($assignmentAnomalies as $row){
        echo "  - Affectation #{$row['assignment_id']} | département #".
            ((int)($row['coordination_unit_id']??0))." | {$row['reason']}".PHP_EOL;
    }
    echo 'Anomalies de rotation : '.count($rotationAnomalies).PHP_EOL;
    foreach($rotationAnomalies as $row){
        echo "  - Rotation #{$row['rotation_id']} | affectation #{$row['assignment_id']} | séquence {$row['sequence_no']} | {$row['reason']}".PHP_EOL;
    }
    echo PHP_EOL.'Aucune donnée modifiée.'.PHP_EOL;
    exit(($assignmentAnomalies||$rotationAnomalies)?1:0);
}catch(Throwable $error){
    fwrite(STDERR,'Échec du contrôle : '.$error->getMessage().PHP_EOL);
    exit(2);
}

<?php
declare(strict_types=1);

/**
 * Controle les rattachements des modeles academiques.
 * --repair ne corrige que le cas non ambigu d'une unite existante, rattachee
 * au meme modele, mais desactivee alors qu'elle est encore utilisee.
 */
if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

$options=getopt('',['repair']);
$repair=array_key_exists('repair',$options);

require __DIR__.'/../config/database.php';

try{
    $sql="
        SELECT
            t.id template_id,
            t.nom template_name,
            t.unite_academique_active,
            d.id department_id,
            d.nom department_name,
            d.unit_id referenced_unit_id,
            u.id actual_unit_id,
            u.template_id unit_template_id,
            u.code unit_code,
            u.nom unit_name,
            u.type_unite unit_type,
            u.actif unit_active,
            CASE
                WHEN d.unit_id IS NULL THEN 'OK_DIRECT'
                WHEN u.id IS NULL THEN 'UNIT_MISSING'
                WHEN u.template_id<>d.template_id THEN 'UNIT_OTHER_TEMPLATE'
                WHEN u.actif<>1 THEN 'UNIT_INACTIVE'
                ELSE 'OK'
            END integrity_status
        FROM academic_template_departments d
        JOIN academic_structure_templates t ON t.id=d.template_id
        LEFT JOIN academic_template_units u ON u.id=d.unit_id
        WHERE d.actif=1
        ORDER BY t.id,d.ordre,d.id
    ";
    $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $invalid=array_values(array_filter(
        $rows,
        static fn(array $row):bool=>!in_array($row['integrity_status'],['OK','OK_DIRECT'],true)
    ));

    echo 'Departements actifs controles : '.count($rows)."\n";
    if(!$invalid){
        echo "Integrite des rattachements : OK\n";
        exit(0);
    }

    echo 'Anomalies detectees : '.count($invalid)."\n\n";
    foreach($invalid as $row){
        echo sprintf(
            "- Modele #%d %s | departement #%d %s | unite #%s | %s\n",
            $row['template_id'],
            $row['template_name'],
            $row['department_id'],
            $row['department_name'],
            $row['referenced_unit_id']??'NULL',
            $row['integrity_status']
        );
        if($row['actual_unit_id']!==null){
            echo sprintf(
                "  Unite trouvee : modele #%d, %s - %s, active=%d\n",
                $row['unit_template_id'],
                $row['unit_code']?:'sans code',
                $row['unit_name']?:'sans nom',
                $row['unit_active']
            );
        }
    }

    if(!$repair){
        echo "\nAucune donnee modifiee.\n";
        $repairable=count(array_filter(
            $invalid,
            static fn(array $row):bool=>$row['integrity_status']==='UNIT_INACTIVE'
        ));
        if($repairable>0)
            echo "{$repairable} anomalie(s) non ambigue(s) peuvent etre corrigees avec :\n  php bin/check-academic-template-integrity.php --repair\n";
        exit(2);
    }

    $repaired=0;
    $pdo->beginTransaction();
    foreach($invalid as $row){
        if($row['integrity_status']!=='UNIT_INACTIVE')continue;

        $allowed=$pdo->prepare("
            SELECT 1
            FROM academic_structure_template_unit_types
            WHERE template_id=? AND type_unite=? AND actif=1
            LIMIT 1
        ");
        $allowed->execute([(int)$row['template_id'],$row['unit_type']]);
        if(!$allowed->fetchColumn()){
            echo "Non corrigee : l'unite #{$row['referenced_unit_id']} utilise un type interdit par le modele.\n";
            continue;
        }

        $update=$pdo->prepare("
            UPDATE academic_template_units
            SET actif=1
            WHERE id=? AND template_id=? AND actif=0
        ");
        $update->execute([(int)$row['referenced_unit_id'],(int)$row['template_id']]);
        if($update->rowCount()===1){
            $repaired++;
            echo "Unite #{$row['referenced_unit_id']} reactivee pour le modele #{$row['template_id']}.\n";
        }
    }
    $pdo->commit();

    $remaining=count($invalid)-$repaired;
    echo "\nReparation terminee : {$repaired} corrigee(s), {$remaining} restante(s).\n";
    if($remaining>0){
        echo "Les references absentes ou appartenant a un autre modele exigent un choix manuel dans le referentiel academique.\n";
        exit(2);
    }
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"Echec du controle : {$e->getMessage()}\n");
    exit(1);
}

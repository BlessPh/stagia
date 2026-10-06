<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../config/database.php';

$execute=in_array('--execute',$argv,true);
$requirements=[
    [
        'table'=>'stage_competencies',
        'column'=>'etablissement_id',
        'definition'=>'INT NULL AFTER `id`',
    ],
    [
        'table'=>'stage_referential_competencies',
        'column'=>'note_max',
        'definition'=>'DECIMAL(5,2) NOT NULL DEFAULT 5.00 AFTER `poids`',
    ],
    [
        'table'=>'stage_referential_competencies',
        'column'=>'section',
        'definition'=>'VARCHAR(180) NULL AFTER `note_max`',
    ],
];

function migrationTableExists(PDO $pdo,string $table):bool
{
    $stmt=$pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn()>0;
}

function migrationColumnExists(PDO $pdo,string $table,string $column):bool
{
    $stmt=$pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $stmt->execute([$table,$column]);
    return (int)$stmt->fetchColumn()>0;
}

function migrationIndexExists(PDO $pdo,string $table,string $index):bool
{
    $stmt=$pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS '
        .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
    );
    $stmt->execute([$table,$index]);
    return (int)$stmt->fetchColumn()>0;
}

try {
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    echo 'Base cible : '.$database.PHP_EOL;

    $tables=array_values(array_unique(array_column($requirements,'table')));
    foreach ($tables as $table) {
        if (!migrationTableExists($pdo,$table)) {
            throw new RuntimeException("La table {$table} est absente.");
        }
    }

    $missing=[];
    foreach ($requirements as $requirement) {
        $exists=migrationColumnExists($pdo,$requirement['table'],$requirement['column']);
        echo $requirement['table'].'.'.$requirement['column'].' : '.($exists?'OK':'ABSENTE').PHP_EOL;
        if (!$exists) {
            $missing[]=$requirement;
        }
    }

    $indexMissing=!migrationIndexExists(
        $pdo,
        'stage_competencies',
        'idx_stage_competency_etablissement'
    );
    echo 'Index idx_stage_competency_etablissement : '.($indexMissing?'ABSENT':'OK').PHP_EOL;

    if (!$missing&&!$indexMissing) {
        echo PHP_EOL.'Migration deja appliquee : le schema est a jour.'.PHP_EOL;
        exit(0);
    }

    if (!$execute) {
        echo PHP_EOL.'SIMULATION UNIQUEMENT : aucune modification effectuee.'.PHP_EOL;
        echo 'Pour appliquer la migration :'.PHP_EOL;
        echo '  php bin/migrate-evaluation-grids.php --execute'.PHP_EOL;
        exit(0);
    }

    foreach ($missing as $requirement) {
        $table=$requirement['table'];
        $column=$requirement['column'];
        $definition=$requirement['definition'];
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo 'Colonne ajoutee : '.$table.'.'.$column.PHP_EOL;
    }

    if ($indexMissing) {
        $pdo->exec(
            'ALTER TABLE `stage_competencies` '
            .'ADD INDEX `idx_stage_competency_etablissement` (`etablissement_id`)'
        );
        echo 'Index ajoute : idx_stage_competency_etablissement'.PHP_EOL;
    }

    foreach ($requirements as $requirement) {
        if (!migrationColumnExists($pdo,$requirement['table'],$requirement['column'])) {
            throw new RuntimeException(
                'Verification impossible pour '
                .$requirement['table'].'.'.$requirement['column'].'.'
            );
        }
    }

    echo PHP_EOL.'Migration des grilles d evaluation terminee.'.PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR,'Echec de la migration : '.$e->getMessage().PHP_EOL);
    exit(1);
}

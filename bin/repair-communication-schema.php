<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../config/database.php';

$tables = ['taches_asynchrones', 'sujets_forums'];

foreach ($tables as $table) {
    $statement = $pdo->prepare(
        "SELECT COLUMN_KEY AS checked_key, EXTRA AS checked_extra
           FROM information_schema.columns
          WHERE table_schema=DATABASE()
            AND table_name=?
            AND column_name='id'
          LIMIT 1"
    );
    $statement->execute([$table]);
    $column = $statement->fetch();

    if (!$column || $column['checked_key'] !== 'PRI') {
        throw new RuntimeException("Reparation refusee : {$table}.id n'est pas une cle primaire.");
    }

    if (str_contains((string)$column['checked_extra'], 'auto_increment')) {
        echo "- {$table} : deja conforme\n";
        continue;
    }

    $pdo->exec("ALTER TABLE `{$table}` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
    echo "- {$table} : AUTO_INCREMENT ajoute\n";
}

echo "Schema Communication repare.\n";

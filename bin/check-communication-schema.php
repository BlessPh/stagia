<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../config/database.php';

$tables = [
    'fichiers',
    'espaces_collaboratifs',
    'membres_espaces',
    'conversations',
    'messages',
    'communications_officielles',
    'destinataires_communications',
    'evenements_calendrier',
    'notifications',
    'livraisons_notifications',
    'taches_asynchrones',
    'messages_sortants',
    'sujets_forums',
    'reponses_forums',
    'reunions_visioconference',
    'journal_archivage_communications',
    'corbeille_communications',
    'historique_modifications_communication',
    'configurations_canaux_communication',
];

$placeholders = implode(',', array_fill(0, count($tables), '?'));
$statement = $pdo->prepare(
    "SELECT TABLE_NAME AS checked_table,
            COLUMN_TYPE AS checked_type,
            COLUMN_KEY AS checked_key,
            EXTRA AS checked_extra
       FROM information_schema.columns
      WHERE table_schema=DATABASE()
        AND column_name='id'
        AND table_name IN ({$placeholders})
      ORDER BY table_name"
);
$statement->execute($tables);
$columns = [];

foreach ($statement->fetchAll() as $column) {
    $columns[$column['checked_table']] = $column;
}

$invalid = 0;
foreach ($tables as $table) {
    $column = $columns[$table] ?? null;
    $valid = $column
        && $column['checked_key'] === 'PRI'
        && str_contains((string)$column['checked_extra'], 'auto_increment');

    if (!$valid) {
        $invalid++;
    }

    echo '- '.$table.' : '.($valid ? 'OK' : 'AUTO_INCREMENT ABSENT').PHP_EOL;
}

echo PHP_EOL;
echo $invalid === 0
    ? "Schema Communication valide.\n"
    : "{$invalid} table(s) a corriger.\n";

exit($invalid === 0 ? 0 : 1);

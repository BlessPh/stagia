<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../config/database.php';

$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$tableCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();

$requiredTables = [
    'users',
    'roles',
    'etablissements',
    'communication_migrations',
    'bibliotheque_documents',
    'geographie_localisations',
    'api_tokens',
    'password_reset_tokens',
    'stage_tasks',
    'mobile_notification_devices',
];

$placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
$statement = $pdo->prepare(
    "SELECT table_name
       FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name IN ({$placeholders})"
);
$statement->execute($requiredTables);
$existingTables = array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);

echo "Base active : {$database}\n";
echo "Nombre de tables : {$tableCount}\n";
echo "Verification du schema :\n";

foreach ($requiredTables as $table) {
    $status = isset($existingTables[$table]) ? 'OK' : 'ABSENTE';
    echo "- {$table} : {$status}\n";
}

$requiredColumns = [
    'api_tokens' => ['refresh_token_hash', 'refresh_expires_at'],
];

echo "Verification des colonnes recentes :\n";

foreach ($requiredColumns as $table => $columns) {
    foreach ($columns as $column) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = ?
                AND column_name = ?'
        );
        $statement->execute([$table, $column]);
        $status = (int)$statement->fetchColumn() === 1 ? 'OK' : 'ABSENTE';
        echo "- {$table}.{$column} : {$status}\n";
    }
}

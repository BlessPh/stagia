<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../config/database.php';

$dumpPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stagia-aiven-export.sql';
$sanitizedDumpPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stagia-aiven-export-sanitized.sql';
$restartRequested = in_array('--restart', $argv, true);

$tableCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();

if ($tableCount !== 0) {
    if (!$restartRequested) {
        fwrite(STDERR, "Import refuse : la base cible contient deja {$tableCount} table(s).\n");
        fwrite(STDERR, "Apres un import interrompu, utilisez --restart.\n");
        exit(1);
    }

    $dumpSql = file_get_contents($dumpPath);
    if ($dumpSql === false || !preg_match_all('/^CREATE TABLE `([^`]+)`/m', $dumpSql, $matches)) {
        fwrite(STDERR, "Impossible de verifier les tables de l'export.\n");
        exit(1);
    }

    $dumpTables = array_fill_keys($matches[1], true);
    $existingTables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $unexpectedTables = array_values(array_filter(
        $existingTables,
        static fn(string $table): bool => !isset($dumpTables[$table])
    ));

    if ($unexpectedTables !== []) {
        fwrite(STDERR, "Redemarrage refuse : la base contient des tables absentes de l'export.\n");
        exit(1);
    }

    fwrite(STDOUT, "Reprise securisee : {$tableCount} table(s) partielles seront remplacees.\n");
}

$mysqlPath = 'C:\\wamp64\\bin\\mysql\\mysql8.4.7\\bin\\mysql.exe';
$sslCa = trim((string)(getenv('DB_SSL_CA') ?: ''));

if (!is_file($dumpPath) || !is_readable($dumpPath)) {
    fwrite(STDERR, "Export introuvable : {$dumpPath}\n");
    exit(1);
}

$dumpSql = file_get_contents($dumpPath);
if ($dumpSql === false) {
    fwrite(STDERR, "Impossible de lire l'export.\n");
    exit(1);
}

$sanitizedSql = preg_replace(
    '~\/\*!\d{5}\s+DEFINER=`[^`]+`@`[^`]+`\*\/\s*~i',
    '',
    $dumpSql,
    -1,
    $removedDefiners
);

if ($sanitizedSql === null || file_put_contents($sanitizedDumpPath, $sanitizedSql) === false) {
    fwrite(STDERR, "Impossible de preparer l'export compatible Aiven.\n");
    exit(1);
}

if ($removedDefiners > 0) {
    fwrite(STDOUT, "Compatibilite Aiven : {$removedDefiners} clause(s) DEFINER retiree(s).\n");
}

if (!is_file($mysqlPath)) {
    fwrite(STDERR, "Client MySQL introuvable : {$mysqlPath}\n");
    exit(1);
}

if ($sslCa === '') {
    fwrite(STDERR, "DB_SSL_CA doit etre configure pour Aiven.\n");
    exit(1);
}

if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $sslCa)) {
    $sslCa = dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sslCa);
}

if (!is_file($sslCa) || !is_readable($sslCa)) {
    fwrite(STDERR, "Certificat CA introuvable.\n");
    exit(1);
}

$command = [
    $mysqlPath,
    '--host='.(string)getenv('DB_HOST'),
    '--port='.(string)getenv('DB_PORT'),
    '--user='.(string)getenv('DB_USER'),
    '--database='.(string)getenv('DB_NAME'),
    '--ssl-mode=VERIFY_CA',
    '--ssl-ca='.$sslCa,
    '--default-character-set=utf8mb4',
    '--binary-mode=1',
    '--connect-timeout=30',
    '--max-allowed-packet=1073741824',
    '--reconnect',
];

$previousMysqlPassword = getenv('MYSQL_PWD');
putenv('MYSQL_PWD='.(string)getenv('DB_PASSWORD'));

$pipes = [];
$process = proc_open(
    $command,
    [
        0 => ['file', $sanitizedDumpPath, 'rb'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    dirname(__DIR__),
    null,
    ['bypass_shell' => true]
);

if (!is_resource($process)) {
    if ($previousMysqlPassword === false) {
        putenv('MYSQL_PWD');
    } else {
        putenv('MYSQL_PWD='.$previousMysqlPassword);
    }
    fwrite(STDERR, "Impossible de lancer le client MySQL.\n");
    exit(1);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
@unlink($sanitizedDumpPath);

if ($previousMysqlPassword === false) {
    putenv('MYSQL_PWD');
} else {
    putenv('MYSQL_PWD='.$previousMysqlPassword);
}

if ($stdout !== '') {
    fwrite(STDOUT, $stdout);
}

if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}

if ($exitCode !== 0) {
    fwrite(STDERR, "Echec de l'import (code {$exitCode}).\n");
    exit($exitCode);
}

$importedTableCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
)->fetchColumn();

fwrite(STDOUT, "Import Aiven termine : {$importedTableCount} table(s).\n");

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../config/database.php';

$cipher = $pdo
    ->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")
    ->fetchColumn(1);

if (!is_string($cipher) || $cipher === '') {
    fwrite(STDERR, "Connexion etablie, mais TLS est inactif.\n");
    exit(1);
}

fwrite(STDOUT, "Connexion Aiven OK - TLS : {$cipher}\n");

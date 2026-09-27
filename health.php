<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(500);

require_once __DIR__.'/config/database.php';

$pdo->query('SELECT 1')->fetchColumn();
http_response_code(200);

echo json_encode(
    ['status' => 'ok', 'database' => 'connected'],
    JSON_UNESCAPED_SLASHES
);

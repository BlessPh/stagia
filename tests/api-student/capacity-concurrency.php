<?php
declare(strict_types=1);
require_once __DIR__ . '/ApiTestSupport.php';

$suite = $suite ?? new ApiTestSuite();
$config = apiTestConfig();
$fixture = $config['capacity_concurrency'] ?? null;
if (!is_array($fixture) || empty($fixture['enabled'])) {
    $suite->skip('Fixture capacity_concurrency désactivée.');
}

$client = new ApiTestClient((string) $config['base_url']);
$a = $client->login($config['students']['a']);
$b = $client->login($config['students']['b']);
$responses = $client->concurrentJson([
    ['method' => 'POST', 'path' => '/student/reservations', 'token' => $a['access_token'], 'body' => $fixture['payload_a']],
    ['method' => 'POST', 'path' => '/student/reservations', 'token' => $b['access_token'], 'body' => $fixture['payload_b']],
]);

$successes = array_values(array_filter($responses, static fn(array $r): bool => in_array($r['status'], [200, 201], true) && ($r['json']['success'] ?? false)));
$rejections = array_values(array_filter($responses, static fn(array $r): bool => in_array($r['status'], [409, 422], true) && !($r['json']['success'] ?? true)));
$suite->ok(count($successes) === 1, 'Une seule réservation gagne la dernière place');
$suite->ok(count($rejections) === 1, 'La réservation concurrente est refusée proprement');

if (!empty($fixture['cleanup'])) {
    foreach ([[$a, $responses[0]], [$b, $responses[1]]] as [$token, $response]) {
        $uuid = $response['json']['data']['reservation_uuid'] ?? null;
        if ($uuid && in_array($response['status'], [200, 201], true)) {
            $client->request('POST', '/student/reservations/' . rawurlencode((string) $uuid) . '/cancel', [], (string) $token['access_token']);
        }
    }
}

return $suite->summary();
